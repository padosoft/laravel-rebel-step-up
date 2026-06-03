<?php

declare(strict_types=1);

return [

    // Durata (secondi) di validità di una conferma step-up (finestra di "freschezza").
    'default_ttl_seconds' => (int) env('REBEL_STEPUP_TTL', 600),

    // Tempo (secondi) entro cui completare una sfida di step-up avviata.
    'challenge_ttl_seconds' => (int) env('REBEL_STEPUP_CHALLENGE_TTL', 300),

    // Tentativi massimi di conferma per sfida.
    'max_attempts' => (int) env('REBEL_STEPUP_MAX_ATTEMPTS', 5),

    // Route name della schermata di step-up (web). Null → 423 nudo.
    'redirect_route' => env('REBEL_STEPUP_REDIRECT_ROUTE'),

    /*
    |--------------------------------------------------------------------------
    | Purpose policies
    |--------------------------------------------------------------------------
    | Ogni "purpose" (azione sensibile) dichiara: assurance richiesta, se serve
    | phishing-resistant, i driver ammessi (in ordine di preferenza), il TTL, e se
    | attiva il PSD2/SCA dynamic linking.
    |
    | NOTA SICUREZZA: di default usiamo `email_otp` (AAL1) così la config è VALIDA
    | out-of-box. Per azioni forti alza `required_assurance` ad `aal2` + aggiungi
    | driver phishing-resistant (es. `fortify_passkey_confirm` dal bridge-fortify):
    | `rebel:validate-config` fallirà se nessun driver soddisfa la soglia.
    */
    'purposes' => [

        'change-email' => [
            'required_assurance' => 'aal1',
            'require_phishing_resistant' => false,
            'drivers' => ['email_otp'],
            'always_require' => true,
        ],

        'download-invoice' => [
            'required_assurance' => 'aal1',
            'drivers' => ['email_otp'],
            'always_require' => true,
        ],

        'checkout-credit-order' => [
            'required_assurance' => 'aal1',
            'drivers' => ['email_otp'],
            'always_require' => true,
            'sca' => [
                'dynamic_linking' => true,
            ],
        ],

    ],

];
