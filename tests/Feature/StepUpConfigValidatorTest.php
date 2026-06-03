<?php

declare(strict_types=1);

it('passes validation for an aal1 purpose using email_otp', function (): void {
    config()->set('rebel-step-up.purposes', [
        'change-email' => ['required_assurance' => 'aal1', 'drivers' => ['email_otp'], 'always_require' => true],
    ]);

    $this->artisan('rebel:validate-config')->assertExitCode(0);
});

it('fails when a purpose requires aal2 phishing-resistant but only email_otp is configured', function (): void {
    config()->set('rebel-step-up.purposes', [
        'change-email' => ['required_assurance' => 'aal2', 'require_phishing_resistant' => true, 'drivers' => ['email_otp'], 'always_require' => true],
    ]);

    $this->artisan('rebel:validate-config')->assertExitCode(1);
});

it('fails when a purpose references an unknown driver', function (): void {
    config()->set('rebel-step-up.purposes', [
        'x' => ['required_assurance' => 'aal1', 'drivers' => ['does-not-exist'], 'always_require' => true],
    ]);

    $this->artisan('rebel:validate-config')->assertExitCode(1);
});
