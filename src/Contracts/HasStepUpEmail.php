<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Contracts;

/**
 * Implementala sul tuo modello utente per abilitare lo step-up via email-OTP:
 *
 *   class Customer extends Model implements HasStepUpEmail {
 *       public function stepUpEmail(): string { return $this->email; }
 *   }
 */
interface HasStepUpEmail
{
    public function stepUpEmail(): string;
}
