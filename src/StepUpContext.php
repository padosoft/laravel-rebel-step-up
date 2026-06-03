<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp;

use Illuminate\Contracts\Auth\Authenticatable;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\StepUp\Sca\TransactionContext;

/**
 * Everything needed to evaluate/perform a step-up:
 *  - subject:     the authenticated user who must confirm;
 *  - purpose:     the sensitive action (e.g. 'checkout-credit-order');
 *  - security:    the SecurityContext (tenant/ip/ua...);
 *  - transaction: for transactional purposes (SCA dynamic linking);
 *  - deviceId:    the current device (to bind the confirmation to the device).
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

        // Fail-fast: a non-scalar identifier would collapse multiple users onto the same
        // subject_id ("") and let them share step-up confirmations → NEVER fail-open.
        if (! is_scalar($id)) {
            throw new \UnexpectedValueException(
                'Lo step-up richiede un identificativo scalare per il subject; ricevuto: '.get_debug_type($id).'.'
            );
        }

        return (string) $id;
    }
}
