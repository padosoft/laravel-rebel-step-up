<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Padosoft\Rebel\Core\Assurance\Aal;
use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Audit\AuthEventType;
use Padosoft\Rebel\Core\Contracts\AuditLogger;
use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;
use Padosoft\Rebel\StepUp\Enums\StepUpStatus;
use Padosoft\Rebel\StepUp\Exceptions\NoAvailableDriverException;
use Padosoft\Rebel\StepUp\Models\StepUpChallenge;
use Padosoft\Rebel\StepUp\Results\StepUpResult;
use Padosoft\Rebel\StepUp\Results\StepUpStartResult;
use Psr\Clock\ClockInterface;

/**
 * Step-up manager: decides whether it is needed, starts the challenge with the right
 * driver, and verifies the confirmation. Enforces assurance and PSD2/SCA dynamic linking.
 */
final class RebelStepUp
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly PolicyRepository $policies,
        private readonly KeyedHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly DatabaseManager $db,
        private readonly Repository $config,
    ) {}

    public function policy(string $purpose): PurposePolicy
    {
        return $this->policies->for($purpose);
    }

    /** Is there a valid confirmation for this context? */
    public function isConfirmed(StepUpContext $context): bool
    {
        return $this->validConfirmation($context) !== null;
    }

    /**
     * Drivers usable for the purpose, in order of preference (config), filtered
     * by availability and assurance.
     *
     * @return list<StepUpDriver>
     */
    public function availableDrivers(StepUpContext $context): array
    {
        $policy = $this->policy($context->purpose);
        $available = [];

        foreach ($policy->drivers as $key) {
            $driver = $this->drivers->get($key);

            if ($driver !== null && $driver->isAvailableFor($context) && $this->driverSatisfies($driver, $policy)) {
                $available[] = $driver;
            }
        }

        return $available;
    }

    public function start(StepUpContext $context, ?string $driverKey = null): StepUpStartResult
    {
        $policy = $this->policy($context->purpose);

        // Fail-closed PSD2/SCA: a purpose with dynamic linking WITHOUT a TransactionContext
        // would produce a null binding (reusable and NOT tied to amount/payee). Forbidden.
        if ($policy->scaDynamicLinking && $context->transaction === null) {
            throw new \InvalidArgumentException(
                "Il purpose '{$context->purpose}' richiede il PSD2/SCA dynamic linking: un TransactionContext (importo+beneficiario) è obbligatorio."
            );
        }

        $driver = $this->pickDriver($context, $policy, $driverKey);

        // The SCA binding is governed by the POLICY, not by the mere presence of a
        // transaction: for a NON-SCA purpose the bound_* fields stay null even if the
        // caller accidentally passes a TransactionContext (no inconsistent data in the table).
        $transaction = $policy->scaDynamicLinking ? $context->transaction : null;
        $binding = $transaction?->bindingHash($this->hasher);
        $now = CarbonImmutable::instance($this->clock->now());

        $challenge = new StepUpChallenge;
        $challenge->forceFill([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context->tenantId(),
            'subject_type' => $context->subjectType(),
            'subject_id' => $context->subjectId(),
            'guard' => $context->security->guard,
            'device_id' => $context->deviceId,
            'purpose' => $context->purpose,
            'required_assurance' => $policy->requiredAssurance->value,
            'require_phishing_resistant' => $policy->requirePhishingResistant,
            'selected_driver' => $driver->key(),
            'binding_hash' => $binding?->hash,
            'bound_amount' => $transaction?->amount,
            'bound_currency' => $transaction?->currency,
            'bound_payee' => $transaction?->payee,
            'bound_order_ref' => $transaction?->orderRef,
            'key_version' => $binding?->keyVersion,
            'status' => StepUpStatus::Pending,
            'expires_at' => $now->addSeconds($this->intConfig('rebel-step-up.challenge_ttl_seconds', 300)),
        ]);
        $challenge->save();

        // If the driver fails to start the challenge (e.g. email provider down), do not leave
        // an orphan "pending" challenge: cancel it and rethrow (fail-fast, no dirty state).
        try {
            $reference = $driver->start($context);
        } catch (\Throwable $e) {
            // Best-effort: try to cancel the challenge. If the cancel ALSO fails
            // (e.g. degraded DB), do NOT mask the driver's original error: the pending
            // challenge remains and will expire by TTL.
            try {
                $challenge->status = StepUpStatus::Cancelled;
                $challenge->save();
            } catch (\Throwable) {
                // intentionally ignored: we rethrow the driver's original exception
            }

            throw $e;
        }

        if ($reference !== null) {
            $challenge->driver_ref = $reference;
            $challenge->save();
        }

        $this->record(AuthEventType::StepUpRequired->value, $context, $driver);

        return new StepUpStartResult($challenge->id, $driver->key(), $reference);
    }

    public function confirm(string $challengeId, string $input, StepUpContext $context): StepUpResult
    {
        $maxAttempts = $this->intConfig('rebel-step-up.max_attempts', 5);
        $now = CarbonImmutable::instance($this->clock->now());
        $policy = $this->policy($context->purpose);
        $tenantId = $context->tenantId();
        $guard = $context->security->guard;
        $deviceId = $context->deviceId;
        // Expected binding: computed ONLY for SCA purposes (for the others the binding is ignored).
        $expectedBinding = $policy->scaDynamicLinking ? $context->transaction?->bindingHash($this->hasher)->hash : null;

        return $this->db->connection()->transaction(function () use ($challengeId, $input, $context, $maxAttempts, $now, $tenantId, $guard, $deviceId, $expectedBinding): StepUpResult {
            $challenge = StepUpChallenge::query()
                ->whereKey($challengeId)
                ->where('subject_type', $context->subjectType())
                ->where('subject_id', $context->subjectId())
                ->where('purpose', $context->purpose)
                ->when(
                    $tenantId === null,
                    fn ($query) => $query->whereNull('tenant_id'),
                    fn ($query) => $query->where('tenant_id', $tenantId),
                )
                // Per-guard isolation: a confirmation made under one guard (e.g. `web`) cannot
                // count for a different guard (e.g. `admin`) with the same user/tenant/purpose.
                ->when(
                    $guard === null,
                    fn ($query) => $query->whereNull('guard'),
                    fn ($query) => $query->where('guard', $guard),
                )
                // Symmetric device binding at confirm time too (consistent with validConfirmation):
                // a challenge started from another device is not confirmed.
                ->when(
                    $deviceId === null,
                    fn ($query) => $query->whereNull('device_id'),
                    fn ($query) => $query->where('device_id', $deviceId),
                )
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return StepUpResult::failure('invalid');
            }

            if ($challenge->status !== StepUpStatus::Pending) {
                return StepUpResult::failure('not_pending');
            }

            if ($challenge->isExpiredAt($now)) {
                $challenge->status = StepUpStatus::Expired;
                $challenge->save();

                return StepUpResult::failure('expired');
            }

            // SCA dynamic linking: the comparison applies ONLY to challenges that are actually
            // bound (binding_hash not null). Constant-time comparison.
            if ($challenge->binding_hash !== null
                && ($expectedBinding === null || ! hash_equals($challenge->binding_hash, $expectedBinding))) {
                return StepUpResult::failure('binding_mismatch');
            }

            if ($challenge->attempts >= $maxAttempts) {
                $challenge->status = StepUpStatus::Failed;
                $challenge->save();

                return StepUpResult::failure('too_many_attempts');
            }

            $driver = $this->drivers->get($challenge->selected_driver);

            if ($driver === null) {
                return StepUpResult::failure('driver_unavailable');
            }

            $challenge->attempts++;

            if (! $driver->verify($context, $input, $challenge->driver_ref)) {
                $challenge->status = $challenge->attempts >= $maxAttempts ? StepUpStatus::Failed : StepUpStatus::Pending;
                $challenge->save();
                $this->record(AuthEventType::StepUpFailed->value, $context, $driver);

                return StepUpResult::failure('wrong_input');
            }

            $assurance = $driver->assurance();
            $challenge->status = StepUpStatus::Verified;
            $challenge->verified_at = $now;
            $challenge->achieved_assurance = $assurance->aal->value;
            $challenge->achieved_phishing_resistant = $assurance->phishingResistant;
            $challenge->achieved_restricted = $assurance->restricted;
            $challenge->save();

            $this->record(AuthEventType::StepUpVerified->value, $context, $driver);

            return StepUpResult::success();
        });
    }

    private function validConfirmation(StepUpContext $context): ?StepUpChallenge
    {
        $policy = $this->policy($context->purpose);

        $now = CarbonImmutable::instance($this->clock->now());
        $threshold = $now->subSeconds($policy->ttlSeconds);
        $tenantId = $context->tenantId();
        $guard = $context->security->guard;

        // The binding is governed by the POLICY: computed only for SCA purposes.
        $bindingHash = $policy->scaDynamicLinking ? $context->transaction?->bindingHash($this->hasher)->hash : null;

        // Fail-closed PSD2/SCA: for a purpose with dynamic linking, a confirmation without a
        // binding (no transaction in the context) is NEVER valid — avoids unlinked confirmations.
        if ($policy->scaDynamicLinking && $bindingHash === null) {
            return null;
        }

        $challenge = StepUpChallenge::query()
            ->where('subject_type', $context->subjectType())
            ->where('subject_id', $context->subjectId())
            ->where('purpose', $context->purpose)
            ->where('status', StepUpStatus::Verified->value)
            ->where('verified_at', '>=', $threshold)
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when(
                $guard === null,
                fn ($query) => $query->whereNull('guard'),
                fn ($query) => $query->where('guard', $guard),
            )
            // Binding filtered by POLICY: for SCA purposes it must match the current
            // transaction; for non-SCA purposes the binding is ignored entirely.
            ->when(
                $policy->scaDynamicLinking,
                fn ($query) => $query->where('binding_hash', $bindingHash),
            )
            // Device binding is SYMMETRIC: a context without a device ⇒ only deviceless
            // confirmations; a context with a device ⇒ only confirmations for THAT device (no cross bypass).
            ->when(
                $context->deviceId === null,
                fn ($query) => $query->whereNull('device_id'),
                fn ($query) => $query->where('device_id', $context->deviceId),
            )
            ->latest('verified_at')
            ->first();

        if ($challenge === null) {
            return null;
        }

        // Assurance enforcement against the CURRENT policy: if the purpose's policy was
        // raised after the confirmation, an "old", weaker confirmation no longer counts.
        if (! $this->achievedSatisfies($challenge, $policy)) {
            return null;
        }

        return $challenge;
    }

    /** Does the assurance actually achieved by the confirmation satisfy the current policy? */
    private function achievedSatisfies(StepUpChallenge $challenge, PurposePolicy $policy): bool
    {
        $achievedAal = $challenge->achieved_assurance === null
            ? null
            : Aal::tryFrom($challenge->achieved_assurance);

        // Missing or unrecognized value (e.g. corrupted/migrated data) ⇒ fail-closed.
        if ($achievedAal === null) {
            return false;
        }

        $achieved = new AssuranceLevel(
            $achievedAal,
            $challenge->achieved_phishing_resistant ?? false,
            [],
            $challenge->achieved_restricted ?? false,
        );

        return $achieved->satisfies(
            $policy->requiredAssurance,
            $policy->requirePhishingResistant,
            $policy->rejectRestricted,
        );
    }

    private function pickDriver(StepUpContext $context, PurposePolicy $policy, ?string $driverKey): StepUpDriver
    {
        $available = $this->availableDrivers($context);

        if ($driverKey !== null) {
            foreach ($available as $driver) {
                if ($driver->key() === $driverKey) {
                    return $driver;
                }
            }

            throw new NoAvailableDriverException("Il driver '{$driverKey}' non è disponibile per il purpose '{$policy->purpose}'.");
        }

        if ($available === []) {
            throw new NoAvailableDriverException("Nessun driver disponibile per il purpose '{$policy->purpose}'.");
        }

        return $available[0];
    }

    private function driverSatisfies(StepUpDriver $driver, PurposePolicy $policy): bool
    {
        return $driver->assurance()->satisfies(
            $policy->requiredAssurance,
            $policy->requirePhishingResistant,
            $policy->rejectRestricted,
        );
    }

    private function record(string $type, StepUpContext $context, StepUpDriver $driver): void
    {
        $this->audit->record(new AuditEvent(
            type: $type,
            guard: $context->security->guard,
            subjectType: $context->subjectType(),
            subjectId: $context->subjectId(),
            tenantId: $context->tenantId(),
            purpose: $context->purpose,
            aal: $driver->assurance()->aal,
            amr: $driver->assurance()->amr,
        ));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_int($value) ? $value : $default;
    }
}
