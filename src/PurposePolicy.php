<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Padosoft\Rebel\Core\Assurance\Aal;

/**
 * The policy of a "purpose" (protected action): which assurance it requires, which
 * drivers are allowed (in order of preference), how long the confirmation lasts, and
 * whether PSD2 dynamic linking is enabled.
 */
final readonly class PurposePolicy
{
    /**
     * @param  list<string>  $drivers  driver keys in order of preference
     */
    public function __construct(
        public string $purpose,
        public Aal $requiredAssurance,
        public bool $requirePhishingResistant,
        public bool $rejectRestricted,
        public array $drivers,
        public int $ttlSeconds,
        public bool $alwaysRequire,
        public bool $scaDynamicLinking,
    ) {}
}
