<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Contracts;

use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\StepUp\StepUpContext;

/**
 * A method by which the user confirms a step-up (email-OTP, passkey, TOTP, SMS...).
 *
 * Each driver DECLARES the assurance it produces: during config validation the
 * resolver/policy rejects drivers below the threshold required by the purpose (fail-fast).
 */
interface StepUpDriver
{
    /** Unique key (e.g. 'email_otp', 'fortify_passkey_confirm'). */
    public function key(): string;

    public function assurance(): AssuranceLevel;

    /** True if this driver is usable for the subject/context (e.g. has a passkey). */
    public function isAvailableFor(StepUpContext $context): bool;

    /**
     * Starts the challenge (e.g. sends an OTP). Returns an opaque reference to store
     * (e.g. the OTP challenge id), or null if no state is needed.
     */
    public function start(StepUpContext $context): ?string;

    /** Verifies the user's input using the stored reference, if any. */
    public function verify(StepUpContext $context, string $input, ?string $reference): bool;
}
