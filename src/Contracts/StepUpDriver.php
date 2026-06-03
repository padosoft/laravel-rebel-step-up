<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Contracts;

use Padosoft\Rebel\Core\Assurance\AssuranceLevel;
use Padosoft\Rebel\StepUp\StepUpContext;

/**
 * Un metodo con cui l'utente conferma uno step-up (email-OTP, passkey, TOTP, SMS...).
 *
 * Ogni driver DICHIARA l'assurance che produce: il resolver/policy rifiuta in
 * validazione config i driver sotto la soglia richiesta dal purpose (fail-fast).
 */
interface StepUpDriver
{
    /** Chiave univoca (es. 'email_otp', 'fortify_passkey_confirm'). */
    public function key(): string;

    public function assurance(): AssuranceLevel;

    /** True se questo driver è utilizzabile per il subject/contesto (es. ha una passkey). */
    public function isAvailableFor(StepUpContext $context): bool;

    /**
     * Avvia la sfida (es. invia un OTP). Ritorna un riferimento opaco da conservare
     * (es. l'id della challenge OTP), oppure null se non serve stato.
     */
    public function start(StepUpContext $context): ?string;

    /** Verifica l'input dell'utente usando l'eventuale riferimento salvato. */
    public function verify(StepUpContext $context, string $input, ?string $reference): bool;
}
