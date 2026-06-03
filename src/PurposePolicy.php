<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Padosoft\Rebel\Core\Assurance\Aal;

/**
 * La policy di un "purpose" (azione protetta): che assurance richiede, quali driver
 * sono ammessi (in ordine di preferenza), per quanto vale la conferma, e se attiva
 * il dynamic linking PSD2.
 */
final readonly class PurposePolicy
{
    /**
     * @param  list<string>  $drivers  chiavi driver in ordine di preferenza
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
