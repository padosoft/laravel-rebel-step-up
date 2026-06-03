<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Results;

/**
 * Outcome of starting a step-up: the step-up challenge id, the chosen driver,
 * and the optional driver reference (e.g. OTP challenge id) to show to the client.
 */
final readonly class StepUpStartResult
{
    public function __construct(
        public string $challengeId,
        public string $driver,
        public ?string $reference = null,
    ) {}
}
