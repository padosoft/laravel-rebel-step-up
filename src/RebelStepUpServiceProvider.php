<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Illuminate\Routing\Router;
use Padosoft\Rebel\StepUp\Config\StepUpConfigValidator;
use Padosoft\Rebel\StepUp\Drivers\EmailOtpStepUpDriver;
use Padosoft\Rebel\StepUp\Http\Middleware\EnsureStepUp;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Step-up authentication purpose/risk-based, con assurance AAL/AMR e PSD2/SCA
 * dynamic linking. Dipende da core (linguaggio comune) ed email-otp (driver email).
 */
final class RebelStepUpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rebel-step-up')
            ->hasConfigFile('rebel-step-up')
            ->hasMigration('create_rebel_step_up_challenges_table');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DriverRegistry::class);
        $this->app->singleton(PolicyRepository::class);
        $this->app->singleton(RebelStepUp::class);

        $this->app->tag([StepUpConfigValidator::class], 'rebel.config_validators');
    }

    public function packageBooted(): void
    {
        // Driver nativo email-OTP.
        $this->app->make(DriverRegistry::class)->register(
            $this->app->make(EmailOtpStepUpDriver::class)
        );

        // Alias middleware: 'rebel.stepup:{purpose}'.
        $this->app->make(Router::class)->aliasMiddleware('rebel.stepup', EnsureStepUp::class);
    }
}
