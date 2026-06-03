<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Drivers;

use Padosoft\Rebel\Core\Assurance\Aal;
use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\Core\Identifiers\EmailIdentifier;
use Padosoft\Rebel\EmailOtp\RebelEmailOtp;
use Padosoft\Rebel\StepUp\Contracts\HasStepUpEmail;
use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;
use Padosoft\Rebel\StepUp\StepUpContext;

/**
 * Driver di step-up basato sull'engine email-OTP (purpose-scoped, distinto dal login).
 * Disponibile solo se il subject implementa HasStepUpEmail.
 *
 * Assurance: AAL1, NON phishing-resistant → ok per purpose a bassa assurance o come
 * fallback; per azioni forti preferire passkey (vedi bridge-fortify).
 */
final class EmailOtpStepUpDriver implements StepUpDriver
{
    public function __construct(private readonly RebelEmailOtp $otp) {}

    public function key(): string
    {
        return 'email_otp';
    }

    public function assurance(): AssuranceLevel
    {
        return new AssuranceLevel(Aal::Aal1, phishingResistant: false, amr: ['otp', 'email']);
    }

    public function isAvailableFor(StepUpContext $context): bool
    {
        return $context->subject instanceof HasStepUpEmail;
    }

    public function start(StepUpContext $context): ?string
    {
        if (! $context->subject instanceof HasStepUpEmail) {
            return null;
        }

        $purpose = 'step-up:'.$context->purpose;

        $result = $this->otp->start(
            EmailIdentifier::from($context->subject->stepUpEmail()),
            $purpose,
            $context->security->withPurpose($purpose),
        );

        return $result->challengeId;
    }

    public function verify(StepUpContext $context, string $input, ?string $reference): bool
    {
        if ($reference === null) {
            return false;
        }

        return $this->otp->verify($reference, $input, $context->security)->success;
    }
}
