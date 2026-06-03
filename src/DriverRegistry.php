<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;

/**
 * Registro dei driver di step-up. I package (es. bridge-fortify, channels) registrano
 * qui i propri driver; il manager li risolve per chiave.
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
