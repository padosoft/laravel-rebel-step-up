<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Sca;

use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\Core\Hashing\HashedValue;

/**
 * Contesto transazionale per il PSD2/SCA "dynamic linking": la conferma di step-up
 * viene LEGATA a importo + valuta + beneficiario + riferimento ordine. Se uno di
 * questi cambia, il binding_hash cambia e la conferma decade → si ri-autentica.
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
        // Fail-fast su importi non rappresentabili: NaN/Inf o negativi non hanno senso per
        // una transazione e renderebbero il binding ambiguo. Meglio rompere subito.
        if (! is_finite($amount) || $amount < 0) {
            throw new \InvalidArgumentException("Importo transazione non valido: {$amount}.");
        }
    }

    /**
     * Serializzazione canonica (ordine fisso) per un hash deterministico.
     *
     * NOTA SICUREZZA — anti delimiter-injection: NON si concatenano i campi con un separatore
     * (es. '|'), perché un payee come "ACME|EUR|..." potrebbe collidere con una transazione
     * diversa e aggirare il dynamic linking. Si usa invece un JSON a chiavi ordinate fisse:
     * l'escaping del JSON rende ogni campo non ambiguo. JSON_THROW_ON_ERROR ⇒ fail-closed su
     * input non rappresentabile (meglio rifiutare lo step-up che produrre un binding ambiguo).
     *
     * L'importo è normalizzato a 2 decimali con punto e senza separatore migliaia, così
     * l'imprecisione binaria del float collassa a una stringa stabile (es. 0.1+0.2 → "0.30").
     * La canonicalizzazione è IDENTICA in start() e confirm()/validConfirmation(): il binding
     * è auto-consistente — stessa transazione ⇒ stesso hash, transazione diversa ⇒ hash diverso.
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
