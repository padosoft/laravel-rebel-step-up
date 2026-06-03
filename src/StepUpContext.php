<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Illuminate\Contracts\Auth\Authenticatable;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\StepUp\Sca\TransactionContext;

/**
 * Tutto ciò che serve a valutare/effettuare uno step-up:
 *  - subject:     l'utente autenticato che deve confermare;
 *  - purpose:     l'azione sensibile (es. 'checkout-credit-order');
 *  - security:    il SecurityContext (tenant/ip/ua...);
 *  - transaction: per i purpose transazionali (SCA dynamic linking);
 *  - deviceId:    dispositivo corrente (per legare la conferma al device).
 */
final readonly class StepUpContext
{
    public function __construct(
        public Authenticatable $subject,
        public string $purpose,
        public SecurityContext $security,
        public ?TransactionContext $transaction = null,
        public ?string $deviceId = null,
    ) {}

    public function tenantId(): ?string
    {
        return $this->security->tenant?->id;
    }

    public function subjectType(): string
    {
        return $this->subject::class;
    }

    public function subjectId(): string
    {
        $id = $this->subject->getAuthIdentifier();

        // Fail-fast: un identifier non scalare collasserebbe più utenti sullo stesso
        // subject_id ("") facendoli condividere le conferme di step-up → MAI fail-open.
        if (! is_scalar($id)) {
            throw new \UnexpectedValueException(
                'Lo step-up richiede un identificativo scalare per il subject; ricevuto: '.get_debug_type($id).'.'
            );
        }

        return (string) $id;
    }
}
