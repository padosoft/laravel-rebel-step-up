<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;

/**
 * Registry of step-up drivers. Packages (e.g. bridge-fortify, channels) register
 * their own drivers here; the manager resolves them by key.
 */
final class DriverRegistry
{
    /** @var array<string, StepUpDriver> */
    private array $drivers = [];

    public function register(StepUpDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

    public function get(string $key): ?StepUpDriver
    {
        return $this->drivers[$key] ?? null;
    }

    /**
     * @return array<string, StepUpDriver>
     */
    public function all(): array
    {
        return $this->drivers;
    }
}
