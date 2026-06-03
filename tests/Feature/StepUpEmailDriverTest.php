<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\EmailOtp\Notifications\EmailOtpNotification;
use Padosoft\Rebel\StepUp\Contracts\HasStepUpEmail;
use Padosoft\Rebel\StepUp\RebelStepUp;
use Padosoft\Rebel\StepUp\StepUpContext;

/**
 * Test user that exposes the email for step-up.
 */
class StepUpEmailUser extends User implements HasStepUpEmail
{
    protected $table = 'step_up_email_users';

    protected $guarded = [];

    public $timestamps = false;

    public function stepUpEmail(): string
    {
        $email = $this->getAttribute('email');

        return is_string($email) ? $email : '';
    }
}

beforeEach(function (): void {
    Schema::create('step_up_email_users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });
});

it('confirms a step-up through the real email_otp driver (cross-package)', function (): void {
    config()->set('rebel-step-up.purposes.change-email', [
        'required_assurance' => 'aal1', 'drivers' => ['email_otp'], 'always_require' => true,
    ]);
    Notification::fake();

    $user = StepUpEmailUser::query()->create(['email' => 'mario@example.it']);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext($user, 'change-email', new SecurityContext('r'));

    $start = $stepUp->start($ctx);
    expect($start->driver)->toBe('email_otp');

    $code = '';
    Notification::assertSentOnDemand(
        EmailOtpNotification::class,
        function (EmailOtpNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        }
    );

    expect($stepUp->confirm($start->challengeId, $code, $ctx)->success)->toBeTrue()
        ->and($stepUp->isConfirmed($ctx))->toBeTrue();
});
