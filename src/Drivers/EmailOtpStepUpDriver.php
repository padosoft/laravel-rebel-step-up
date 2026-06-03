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
 * Step-up driver based on the email-OTP engine (purpose-scoped, distinct from login).
 * Available only if the subject implements HasStepUpEmail.
 *
 * Assurance: AAL1, NOT phishing-resistant → fine for low-assurance purposes or as a
 * fallback; for strong actions prefer a passkey (see bridge-fortify).
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
