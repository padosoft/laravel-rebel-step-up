<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Config;

use Illuminate\Contracts\Config\Repository;
use Padosoft\Rebel\Core\Contracts\ConfigValidator;
use Padosoft\Rebel\StepUp\DriverRegistry;
use Padosoft\Rebel\StepUp\PolicyRepository;

/**
 * Validates (fail-fast) that EVERY purpose has at least one driver that is configured,
 * registered, and that meets the required assurance (AAL + phishing-resistant + restricted).
 *
 * This IS the central security check: it prevents, for example, `change-email`
 * (which requires AAL2 phishing-resistant) from allowing only `email_otp` (AAL1) → CI error.
 */
final class StepUpConfigValidator implements ConfigValidator
{
    public function __construct(
        private readonly Repository $config,
        private readonly PolicyRepository $policies,
        private readonly DriverRegistry $registry,
    ) {}

    public function name(): string
    {
        return 'step-up';
    }

    public function validate(): array
    {
        $purposes = $this->config->get('rebel-step-up.purposes');

        if (! is_array($purposes)) {
            return [];
        }

        $errors = [];

        foreach (array_keys($purposes) as $purposeKey) {
            $purpose = (string) $purposeKey;
            $policy = $this->policies->for($purpose);

            if ($policy->drivers === []) {
                $errors[] = "Il purpose '{$purpose}' non ha driver configurati.";

                continue;
            }

            $satisfied = false;

            foreach ($policy->drivers as $driverKey) {
                $driver = $this->registry->get($driverKey);

                if ($driver === null) {
                    $errors[] = "Il purpose '{$purpose}' usa il driver '{$driverKey}' non registrato.";

                    continue;
                }

                if ($driver->assurance()->satisfies($policy->requiredAssurance, $policy->requirePhishingResistant, $policy->rejectRestricted)) {
                    $satisfied = true;
                }
            }

            if (! $satisfied) {
                $detail = $policy->requiredAssurance->value.($policy->requirePhishingResistant ? ' + phishing-resistant' : '');
                $errors[] = "Il purpose '{$purpose}' non ha alcun driver che soddisfi l'assurance richiesta ({$detail}).";
            }
        }

        return $errors;
    }
}
