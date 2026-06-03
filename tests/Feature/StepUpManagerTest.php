<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Padosoft\Rebel\Core\Assurance\Aal;
use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\Core\Clock\FakeClock;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\StepUp\Contracts\StepUpDriver;
use Padosoft\Rebel\StepUp\DriverRegistry;
use Padosoft\Rebel\StepUp\Exceptions\NoAvailableDriverException;
use Padosoft\Rebel\StepUp\Models\StepUpChallenge;
use Padosoft\Rebel\StepUp\RebelStepUp;
use Padosoft\Rebel\StepUp\Sca\TransactionContext;
use Padosoft\Rebel\StepUp\StepUpContext;
use Padosoft\Rebel\StepUp\Testing\FakeStepUpDriver;
use Psr\Clock\ClockInterface;

function fakeDriver(Aal $aal = Aal::Aal2, bool $phishingResistant = true): void
{
    app(DriverRegistry::class)->register(
        new FakeStepUpDriver('fake', new AssuranceLevel($aal, $phishingResistant, ['fake']))
    );
}

it('starts and confirms a step-up via the chosen driver', function (): void {
    fakeDriver();
    config()->set('rebel-step-up.purposes.test', [
        'required_assurance' => 'aal2', 'require_phishing_resistant' => true, 'drivers' => ['fake'], 'always_require' => true,
    ]);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext(new GenericUser(['id' => 7]), 'test', new SecurityContext('r'));

    expect($stepUp->isConfirmed($ctx))->toBeFalse();

    $start = $stepUp->start($ctx);

    expect($start->driver)->toBe('fake')
        ->and($stepUp->confirm($start->challengeId, '123456', $ctx)->success)->toBeTrue()
        ->and($stepUp->isConfirmed($ctx))->toBeTrue();
});

it('rejects a wrong code and fails the challenge after max attempts', function (): void {
    fakeDriver();
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal2', 'require_phishing_resistant' => true, 'drivers' => ['fake'], 'always_require' => true]);
    config()->set('rebel-step-up.max_attempts', 2);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'test', new SecurityContext('r'));
    $start = $stepUp->start($ctx);

    expect($stepUp->confirm($start->challengeId, 'wrong', $ctx)->reason)->toBe('wrong_input')
        ->and($stepUp->confirm($start->challengeId, 'wrong', $ctx)->reason)->toBe('wrong_input')
        ->and($stepUp->confirm($start->challengeId, '123456', $ctx)->reason)->toBe('not_pending');
});

it('throws when no driver satisfies the policy', function (): void {
    // fake è AAL1, ma il purpose richiede AAL2 phishing-resistant.
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal2', 'require_phishing_resistant' => true, 'drivers' => ['fake'], 'always_require' => true]);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'test', new SecurityContext('r'));

    app(RebelStepUp::class)->start($ctx);
})->throws(NoAvailableDriverException::class);

it('binds the confirmation to the transaction (PSD2 dynamic linking)', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.pay', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true, 'sca' => ['dynamic_linking' => true]]);
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    $ctxA = new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(100.00, 'EUR', 'ACME', 'ORD-1'));
    $start = $stepUp->start($ctxA);

    expect($stepUp->confirm($start->challengeId, '123456', $ctxA)->success)->toBeTrue()
        ->and($stepUp->isConfirmed($ctxA))->toBeTrue();

    // Cambiando l'importo, la conferma esistente NON è più valida.
    $ctxB = new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(200.00, 'EUR', 'ACME', 'ORD-1'));
    expect($stepUp->isConfirmed($ctxB))->toBeFalse();
});

it('ignores a stray transaction on a non-SCA purpose (binding policy-driven)', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true]); // niente sca
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    // Anche se al chiamante "scappa" una transazione su un purpose non-SCA…
    $withTx = new StepUpContext($user, 'test', new SecurityContext('r'), new TransactionContext(50.00, 'EUR', 'X', 'Y'));
    $start = $stepUp->start($withTx);

    // …i campi bound NON vengono persistiti (nessun dato incoerente).
    $challenge = StepUpChallenge::query()->findOrFail($start->challengeId);
    expect($challenge->binding_hash)->toBeNull()
        ->and($challenge->bound_amount)->toBeNull();

    // …e la conferma funziona anche con un contesto SENZA transazione (binding ignorato).
    $plain = new StepUpContext($user, 'test', new SecurityContext('r'));
    expect($stepUp->confirm($start->challengeId, '123456', $plain)->success)->toBeTrue()
        ->and($stepUp->isConfirmed($plain))->toBeTrue();
});

it('refuses to start an SCA purpose without a transaction (fail-closed)', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.pay', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true, 'sca' => ['dynamic_linking' => true]]);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'pay', new SecurityContext('r')); // niente TransactionContext

    app(RebelStepUp::class)->start($ctx);
})->throws(InvalidArgumentException::class);

it('does not let a step-up under one guard satisfy another guard', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true]);
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    $web = new StepUpContext($user, 'test', (new SecurityContext('r'))->withGuard('web'));
    $start = $stepUp->start($web);
    $stepUp->confirm($start->challengeId, '123456', $web);
    expect($stepUp->isConfirmed($web))->toBeTrue();

    // Stesso utente/tenant/purpose ma guard diverso ⇒ la conferma NON vale.
    $admin = new StepUpContext($user, 'test', (new SecurityContext('r'))->withGuard('admin'));
    expect($stepUp->isConfirmed($admin))->toBeFalse();
});

it('is not fooled by a delimiter-collision in the transaction fields (anti-injection)', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.pay', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true, 'sca' => ['dynamic_linking' => true]]);
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    // Sotto un naive join con '|' queste due transazioni collassano sulla STESSA stringa
    // ("...|A|B|C"); con la canonicalizzazione JSON restano distinte.
    $confirmed = new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(100.00, 'EUR', 'A', 'B|C'));
    $start = $stepUp->start($confirmed);
    expect($stepUp->confirm($start->challengeId, '123456', $confirmed)->success)->toBeTrue();

    $forged = new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(100.00, 'EUR', 'A|B', 'C'));
    expect($stepUp->isConfirmed($forged))->toBeFalse();
});

it('rejects a confirm when the transaction changed between start and confirm', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.pay', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true, 'sca' => ['dynamic_linking' => true]]);
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    $start = $stepUp->start(new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(100.00, 'EUR', 'ACME', 'ORD-1')));
    $changed = new StepUpContext($user, 'pay', new SecurityContext('r'), new TransactionContext(999.00, 'EUR', 'ACME', 'ORD-1'));

    expect($stepUp->confirm($start->challengeId, '123456', $changed)->reason)->toBe('binding_mismatch');
});

it('expires a confirmation after the policy ttl', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01 10:00:00'));
    app()->instance(ClockInterface::class, $clock);

    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true, 'ttl_seconds' => 600]);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'test', new SecurityContext('r'));

    $start = $stepUp->start($ctx);
    $stepUp->confirm($start->challengeId, '123456', $ctx);
    expect($stepUp->isConfirmed($ctx))->toBeTrue();

    $clock->advance(601);
    expect($stepUp->isConfirmed($ctx))->toBeFalse();
});

it('invalidates a prior confirmation when the policy assurance is raised', function (): void {
    fakeDriver(Aal::Aal1, false); // il driver fake raggiunge solo AAL1
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true]);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'test', new SecurityContext('r'));

    $start = $stepUp->start($ctx);
    $stepUp->confirm($start->challengeId, '123456', $ctx);
    expect($stepUp->isConfirmed($ctx))->toBeTrue();

    // La policy viene innalzata: la conferma AAL1 "vecchia" non soddisfa più il purpose.
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal2', 'require_phishing_resistant' => true, 'drivers' => ['fake'], 'always_require' => true]);
    expect($stepUp->isConfirmed($ctx))->toBeFalse();
});

it('binds a confirmation to the device (no cross-device reuse)', function (): void {
    fakeDriver(Aal::Aal1, false);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['fake'], 'always_require' => true]);
    $stepUp = app(RebelStepUp::class);
    $user = new GenericUser(['id' => 1]);

    $ctxA = new StepUpContext($user, 'test', new SecurityContext('r'), null, 'dev-A');
    $start = $stepUp->start($ctxA);
    $stepUp->confirm($start->challengeId, '123456', $ctxA);
    expect($stepUp->isConfirmed($ctxA))->toBeTrue();

    // Un contesto SENZA device non può riutilizzare una conferma device-bound...
    expect($stepUp->isConfirmed(new StepUpContext($user, 'test', new SecurityContext('r'))))->toBeFalse()
        // ...né un device diverso.
        ->and($stepUp->isConfirmed(new StepUpContext($user, 'test', new SecurityContext('r'), null, 'dev-B')))->toBeFalse();
});

it('cancels the challenge when the driver fails to start (no orphan pending)', function (): void {
    $throwing = new class implements StepUpDriver
    {
        public function key(): string
        {
            return 'boom';
        }

        public function assurance(): AssuranceLevel
        {
            return new AssuranceLevel(Aal::Aal1, false, ['boom']);
        }

        public function isAvailableFor(StepUpContext $context): bool
        {
            return true;
        }

        public function start(StepUpContext $context): ?string
        {
            throw new RuntimeException('provider down');
        }

        public function verify(StepUpContext $context, string $input, ?string $reference): bool
        {
            return false;
        }
    };
    app(DriverRegistry::class)->register($throwing);
    config()->set('rebel-step-up.purposes.test', ['required_assurance' => 'aal1', 'drivers' => ['boom'], 'always_require' => true]);
    $stepUp = app(RebelStepUp::class);
    $ctx = new StepUpContext(new GenericUser(['id' => 1]), 'test', new SecurityContext('r'));

    expect(fn () => $stepUp->start($ctx))->toThrow(RuntimeException::class);

    // Nessun challenge resta "pending": quello creato è stato annullato.
    expect(StepUpChallenge::query()->where('status', 'pending')->count())->toBe(0)
        ->and(StepUpChallenge::query()->where('status', 'cancelled')->count())->toBe(1);
});
