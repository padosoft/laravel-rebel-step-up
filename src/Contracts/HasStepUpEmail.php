<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Contracts;

/**
 * Implement this on your user model to enable step-up via email-OTP:
 *
 *   class Customer extends Model implements HasStepUpEmail {
 *       public function stepUpEmail(): string { return $this->email; }
 *   }
 */
interface HasStepUpEmail
{
    public function stepUpEmail(): string;
}
