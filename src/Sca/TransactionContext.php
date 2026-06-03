<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Sca;

use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\Core\Hashing\HashedValue;

/**
 * Transaction context for PSD2/SCA "dynamic linking": the step-up confirmation
 * is BOUND to amount + currency + payee + order reference. If any of these
 * changes, the binding_hash changes and the confirmation lapses → re-authentication.
 *
 *   new TransactionContext(1250.00, 'EUR', 'ACME Srl', 'ORD-1234');
 */
final readonly class TransactionContext
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $payee,
        public string $orderRef,
    ) {
        // Fail-fast on non-representable amounts: NaN/Inf or negatives make no sense for
        // a transaction and would make the binding ambiguous. Better to fail immediately.
        if (! is_finite($amount) || $amount < 0) {
            throw new \InvalidArgumentException("Importo transazione non valido: {$amount}.");
        }
    }

    /**
     * Canonical serialization (fixed order) for a deterministic hash.
     *
     * SECURITY NOTE — anti delimiter-injection: fields are NOT concatenated with a separator
     * (e.g. '|'), because a payee like "ACME|EUR|..." could collide with a different
     * transaction and bypass dynamic linking. Instead, a JSON with fixed ordered keys is used:
     * JSON escaping makes every field unambiguous. JSON_THROW_ON_ERROR ⇒ fail-closed on
     * non-representable input (better to reject the step-up than produce an ambiguous binding).
     *
     * The amount is normalized to 2 decimals with a dot and no thousands separator, so the
     * float's binary imprecision collapses to a stable string (e.g. 0.1+0.2 → "0.30").
     * The canonicalization is IDENTICAL in start() and confirm()/validConfirmation(): the binding
     * is self-consistent — same transaction ⇒ same hash, different transaction ⇒ different hash.
     */
    public function canonical(): string
    {
        return json_encode([
            'amount' => number_format($this->amount, 2, '.', ''),
            'currency' => strtoupper($this->currency),
            'payee' => $this->payee,
            'orderRef' => $this->orderRef,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function bindingHash(KeyedHasher $hasher): HashedValue
    {
        return $hasher->hash($this->canonical());
    }
}
