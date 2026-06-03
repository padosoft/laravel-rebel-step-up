<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Padosoft\Rebel\Core\Assurance\Aal;
use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\StepUp\DriverRegistry;
use Padosoft\Rebel\StepUp\RebelStepUp;
use Padosoft\Rebel\StepUp\StepUpContext;
use Padosoft\Rebel\StepUp\Testing\FakeStepUpDriver;

it('blocks a protected route without confirmation, then passes after step-up', function (): void {
    app(DriverRegistry::class)->register(new FakeStepUpDriver('fake', new AssuranceLevel(Aal::Aal1, false, ['fake'])));
    config()->set('rebel-step-up.purposes.guarded', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true]);

    Route::middleware('rebel.stepup:guarded')->get('/protected', fn () => 'ok');

    $user = new GenericUser(['id' => 1]);
    $this->actingAs($user);

    // Senza conferma → 423 con i driver disponibili.
    $this->getJson('/protected')
        ->assertStatus(423)
        ->assertJsonPath('error', 'step_up_required')
        ->assertJsonPath('drivers.0', 'fake');

    // Conferma lo step-up.
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext($user, 'guarded', new SecurityContext('r'));
    $start = $stepUp->start($ctx);
    $stepUp->confirm($start->challengeId, '123456', $ctx);

    // Ora passa.
    $this->getJson('/protected')->assertOk()->assertSee('ok');
});
