<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Illuminate\Contracts\Config\Repository;
use Padosoft\Rebel\Core\Assurance\Aal;

/**
 * Costruisce le PurposePolicy a partire dalla config `rebel-step-up.purposes`.
 * Tutte le letture sono type-safe (niente cast su mixed).
 */
final class PolicyRepository
{
    public function __construct(private readonly Repository $config) {}

    public function exists(string $purpose): bool
    {
        return is_array($this->config->get("rebel-step-up.purposes.{$purpose}"));
    }

    public function for(string $purpose): PurposePolicy
    {
        $raw = $this->config->get("rebel-step-up.purposes.{$purpose}");
        $cfg = is_array($raw) ? $raw : [];

        $sca = $cfg['sca'] ?? null;
        $dynamicLinking = is_array($sca) ? ($sca['dynamic_linking'] ?? false) : false;

        $defaultTtl = $this->intVal($this->config->get('rebel-step-up.default_ttl_seconds'), 600);

        return new PurposePolicy(
            purpose: $purpose,
            requiredAssurance: $this->aal($cfg['required_assurance'] ?? null),
            requirePhishingResistant: $this->boolVal($cfg['require_phishing_resistant'] ?? null, false),
            rejectRestricted: $this->boolVal($cfg['reject_restricted'] ?? null, false),
            drivers: $this->stringList($cfg['drivers'] ?? null),
            ttlSeconds: $this->intVal($cfg['ttl_seconds'] ?? null, $defaultTtl),
            alwaysRequire: $this->boolVal($cfg['always_require'] ?? null, true),
            scaDynamicLinking: $this->boolVal($dynamicLinking, false),
        );
    }

    private function aal(mixed $value): Aal
    {
        return is_string($value) ? (Aal::tryFrom($value) ?? Aal::Aal2) : Aal::Aal2;
    }

    private function boolVal(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    private function intVal(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
