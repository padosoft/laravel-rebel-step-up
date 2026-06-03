<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Testing;

use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;
use Padosoft\Rebel\StepUp\StepUpContext;

/**
 * Fake driver for tests: accepts an expected code and a configurable assurance.
 */
final class FakeStepUpDriver implements StepUpDriver
{
    public function __construct(
        private readonly string $key,
        private readonly AssuranceLevel $assurance,
        private readonly string $expectedCode = '123456',
        private readonly bool $available = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function assurance(): AssuranceLevel
    {
        return $this->assurance;
    }

    public function isAvailableFor(StepUpContext $context): bool
    {
        return $this->available;
    }

    public function start(StepUpContext $context): ?string
    {
        return null;
    }

    public function verify(StepUpContext $context, string $input, ?string $reference): bool
    {
        return $input === $this->expectedCode;
    }
}
