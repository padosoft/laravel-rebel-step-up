<?php

declare(strict_types=1);

return [

    // Duration (seconds) a step-up confirmation stays valid (the "freshness" window).
    'default_ttl_seconds' => (int) env('REBEL_STEPUP_TTL', 600),

    // Time (seconds) within which a started step-up challenge must be completed.
    'challenge_ttl_seconds' => (int) env('REBEL_STEPUP_CHALLENGE_TTL', 300),

    // Maximum confirmation attempts per challenge.
    'max_attempts' => (int) env('REBEL_STEPUP_MAX_ATTEMPTS', 5),

    // Route name of the step-up screen (web). Null → bare 423.
    'redirect_route' => env('REBEL_STEPUP_REDIRECT_ROUTE'),

    /*
    |--------------------------------------------------------------------------
    | Purpose policies
    |--------------------------------------------------------------------------
    | Each "purpose" (sensitive action) declares: the required assurance, whether
    | phishing-resistant is required, the allowed drivers (in order of preference),
    | the TTL, and whether PSD2/SCA dynamic linking is enabled.
    |
    | SECURITY NOTE: by default we use `email_otp` (AAL1) so the config is VALID
    | out-of-the-box. For strong actions, raise `required_assurance` to `aal2` and add
    | a phishing-resistant driver (e.g. `fortify_passkey_confirm` from bridge-fortify):
    | `rebel:validate-config` will fail if no driver meets the threshold.
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
