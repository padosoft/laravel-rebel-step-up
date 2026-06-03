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
 * Manager dello step-up: decide se serve, avvia la sfida col driver adatto, e verifica
 * la conferma. Applica l'enforcement dell'assurance e il PSD2/SCA dynamic linking.
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

    /** Esiste una conferma valida per questo contesto? */
    public function isConfirmed(StepUpContext $context): bool
    {
        return $this->validConfirmation($context) !== null;
    }

    /**
     * Driver utilizzabili per il purpose, in ordine di preferenza (config), filtrati
     * per disponibilità e assurance.
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

        // Fail-closed PSD2/SCA: un purpose con dynamic linking SENZA TransactionContext
        // produrrebbe un binding nullo (riutilizzabile e NON legato a importo/payee). Vietato.
        if ($policy->scaDynamicLinking && $context->transaction === null) {
            throw new \InvalidArgumentException(
                "Il purpose '{$context->purpose}' richiede il PSD2/SCA dynamic linking: un TransactionContext (importo+beneficiario) è obbligatorio."
            );
        }

        $driver = $this->pickDriver($context, $policy, $driverKey);

        $binding = $context->transaction?->bindingHash($this->hasher);
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
            'bound_amount' => $context->transaction?->amount,
            'bound_currency' => $context->transaction?->currency,
            'bound_payee' => $context->transaction?->payee,
            'bound_order_ref' => $context->transaction?->orderRef,
            'key_version' => $binding?->keyVersion,
            'status' => StepUpStatus::Pending,
            'expires_at' => $now->addSeconds($this->intConfig('rebel-step-up.challenge_ttl_seconds', 300)),
        ]);
        $challenge->save();

        // Se il driver fallisce ad avviare la sfida (es. provider email giù), non lasciare
        // un challenge "pending" orfano: lo si annulla e si rilancia (fail-fast, niente stato sporco).
        try {
            $reference = $driver->start($context);
        } catch (\Throwable $e) {
            // Best-effort: prova ad annullare il challenge. Se ANCHE il cancel fallisce
            // (es. DB degradato) NON mascherare l'errore originale del driver: il challenge
            // pending resta e scadrà per TTL.
            try {
                $challenge->status = StepUpStatus::Cancelled;
                $challenge->save();
            } catch (\Throwable) {
                // ignorato di proposito: rilanciamo l'eccezione originale del driver
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
        $tenantId = $context->tenantId();
        $guard = $context->security->guard;
        $bindingHash = $context->transaction?->bindingHash($this->hasher)->hash;

        return $this->db->connection()->transaction(function () use ($challengeId, $input, $context, $maxAttempts, $now, $tenantId, $guard, $bindingHash): StepUpResult {
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
                // Isolamento per-guard: una conferma fatta sotto un guard (es. `web`) non può
                // valere per un guard diverso (es. `admin`) con lo stesso utente/tenant/purpose.
                ->when(
                    $guard === null,
                    fn ($query) => $query->whereNull('guard'),
                    fn ($query) => $query->where('guard', $guard),
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

            // SCA dynamic linking: il binding deve combaciare (importo/beneficiario invariati).
            if ($challenge->binding_hash !== $bindingHash) {
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
        $bindingHash = $context->transaction?->bindingHash($this->hasher)->hash;
        $tenantId = $context->tenantId();
        $guard = $context->security->guard;

        // Fail-closed PSD2/SCA: per un purpose con dynamic linking una conferma senza binding
        // (nessuna transazione nel contesto) non è MAI valida — evita conferme non linkate.
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
            ->when(
                $bindingHash === null,
                fn ($query) => $query->whereNull('binding_hash'),
                fn ($query) => $query->where('binding_hash', $bindingHash),
            )
            // Il device binding è SIMMETRICO: contesto senza device ⇒ solo conferme senza device,
            // contesto con device ⇒ solo conferme di QUEL device (niente bypass incrociati).
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

        // Enforcement dell'assurance contro la policy CORRENTE: se la policy del purpose è
        // stata innalzata dopo la conferma, una conferma "vecchia" e più debole non vale più.
        if (! $this->achievedSatisfies($challenge, $policy)) {
            return null;
        }

        return $challenge;
    }

    /** L'assurance effettivamente raggiunta dalla conferma soddisfa la policy corrente? */
    private function achievedSatisfies(StepUpChallenge $challenge, PurposePolicy $policy): bool
    {
        $achievedAal = $challenge->achieved_assurance === null
            ? null
            : Aal::tryFrom($challenge->achieved_assurance);

        // Valore mancante o non riconosciuto (es. dato corrotto/migrato) ⇒ fail-closed.
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
