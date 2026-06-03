<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Results;

/**
 * Outcome of a step-up confirmation.
 */
final readonly class StepUpResult
{
    private function __construct(
        public bool $success,
        public ?string $reason = null,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }
}
