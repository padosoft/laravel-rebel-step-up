# Laravel Rebel — Step-Up

> **Ask for a strong re-confirmation only when it truly matters.** The user is already logged in, but is about to perform a sensitive action (change their email, download an invoice, confirm a credit order): Rebel Step-Up asks them for a **targeted second factor** (email OTP, passkey, TOTP…), with the **AAL/AMR** security level chosen for that action and — for payments — **PSD2/SCA dynamic linking** (the confirmation is bound to amount+payee). It is part of the `padosoft/laravel-rebel-*` suite.

<p align="center">
  <img src="resources/screenshoots/Laravel-Rebel-banner.png" alt="Laravel Rebel" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12|13">
  <img src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/PHPStan-max-2A6FDB?style=flat-square" alt="PHPStan max">
  <img src="https://img.shields.io/badge/tests-Pest%204-22C55E?style=flat-square" alt="Pest 4">
  <img src="https://img.shields.io/badge/PSD2%2FSCA-dynamic%20linking-8B5CF6?style=flat-square" alt="PSD2 SCA">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT">
</p>

---

## Table of contents

- [What it is (and what it is NOT)](#what-it-is-and-what-it-is-not)
- [Quick glossary (read it, it takes 1 minute)](#quick-glossary-read-it-it-takes-1-minute)
- [Why Rebel Step-Up — the moats](#why-rebel-step-up--the-moats)
- [Rebel Step-Up vs the "do-it-yourself"](#rebel-step-up-vs-the-do-it-yourself)
- [How it works (the flow, step by step)](#how-it-works-the-flow-step-by-step)
- [Installation (junior-proof)](#installation-junior-proof)
- [Configuration (every option)](#configuration-every-option)
- [Usage examples](#usage-examples)
  - [1. Protect a route with the middleware](#1-protect-a-route-with-the-middleware)
  - [2. Manual control (without middleware)](#2-manual-control-without-middleware)
  - [3. Payment with PSD2/SCA dynamic linking](#3-payment-with-psd2sca-dynamic-linking)
  - [4. Start and confirm a challenge (API/mobile)](#4-start-and-confirm-a-challenge-apimobile)
  - [5. Choose the driver (passkey-first, OTP fallback)](#5-choose-the-driver-passkey-first-otp-fallback)
  - [6. Bind the confirmation to the device](#6-bind-the-confirmation-to-the-device)
- [Validating the config in CI](#validating-the-config-in-ci)
- [`.env.example`](#envexample)
- [Security (what it guarantees you)](#security-what-it-guarantees-you)
- [Testing & License](#testing--license)

---

## What it is (and what it is NOT)

**It is** the "control plane" that decides **when** an already-authenticated user must **re-prove** who they are before a sensitive action, **with what strength** (assurance), and **binding** that confirmation to the specific action (for payments: to amount and payee). You declare a policy for each *purpose* (action) and Rebel enforces the rule — via middleware or via API.

**It is NOT**:
- a **login** system (to sign in there is `laravel-rebel-email-otp`, or Fortify via `laravel-rebel-bridge-fortify`); step-up assumes a user **already logged in**;
- a standalone OTP generator: for email OTP it uses the engine of `laravel-rebel-email-otp`; for passkey/TOTP it uses the drivers of `laravel-rebel-bridge-fortify`. Step-Up **orchestrates** them, it does not reimplement them.

It depends on [`padosoft/laravel-rebel-core`](https://github.com/padosoft/laravel-rebel-core) (assurance, contracts, keyed hashing) and on [`padosoft/laravel-rebel-email-otp`](https://github.com/padosoft/laravel-rebel-email-otp) (default OTP driver). For the **big picture** of the ecosystem, start from the core README.

---

## Quick glossary (read it, it takes 1 minute)

| Term | In plain words |
|---|---|
| **Step-up** | "You're already in, but for THIS thing I'm asking you for one more proof." |
| **Purpose** | The name of the protected action, e.g. `change-email`, `download-invoice`, `checkout-credit-order`. You associate a rule with each purpose. |
| **AAL** (Authenticator Assurance Level) | How "strong" the proof is, per the NIST standard. `aal1` = one factor (e.g. email OTP); `aal2` = two factors / more robust. |
| **AMR** | *Authentication Methods References*: the list of methods used, e.g. `['otp','email']`, `['webauthn']`. |
| **Phishing-resistant** | A proof that phishing cannot steal: typically **passkey/FIDO2**. An email OTP is **not**. |
| **Driver** | The "way" the proof is performed: `email_otp`, `fortify_passkey_confirm`, `fortify_totp`… Each one declares its own assurance. |
| **Binding / Dynamic linking** | The confirmation is **glued** to the details of the operation (amount, currency, payee, order). If they change, the confirmation lapses: this is mandated by the European **PSD2/SCA** for payments. |
| **Challenge** | The open step-up "case": it has an id, an expiry, attempts, a status. |
| **Confirmation window (TTL)** | How long a confirmation stays valid after success (then it must be redone). |

---

## Why Rebel Step-Up — the moats

| ★ | What | In short |
|---|---|---|
| ★★★ | **Per-purpose policy** | Decide for each action the required level, the allowed drivers, the TTL. No `if`s scattered through the code. |
| ★★★ | **Assurance enforcement** | A driver **below the threshold** is rejected upfront. And if you raise the policy, the **older, weaker confirmations lapse** immediately. |
| ★★★ | **PSD2/SCA dynamic linking** | Confirmation bound to amount+payee with a keyed hash; **anti-injection** canonicalization (no collisions from separators). |
| ★★ | **Pluggable drivers** | Email OTP included; passkey/TOTP via bridge-fortify; your own custom drivers by implementing an interface. |
| ★★ | **Atomic & anti-replay** | Verification in a transaction with `lockForUpdate`, single-use, max attempts, expiry. |
| ★★ | **Device binding** | The confirmation can be bound to the device: no cross-device reuse. |
| ★★ | **Multi-tenant & audit** | Everything is scoped per tenant; every step (`StepUpRequired/Verified/Failed`) is audited. |
| ★ | **Config validated in CI** | `php artisan rebel:validate-config` blocks insecure configurations before deploy. |

---

## Rebel Step-Up vs the "do-it-yourself"

| | **Rebel Step-Up** | Shopify | Laravel's `password.confirm` middleware | Fortify-native password confirmation | Hand-rolled "re-enter password" |
|---|---|---|---|---|---|
| Configurable strength per action (AAL/AMR) | ✅ | ❌ | ❌ (password only) | ❌ (password only) | ❌ |
| Passkey / TOTP / email OTP interchangeable | ✅ | ❌ | ❌ | ❌ | ❌ |
| PSD2/SCA dynamic linking (amount+payee) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Confirmation that lapses if the amount changes | ✅ | ❌ | ❌ | ❌ | ❌ |
| Device binding | ✅ | ➖ | ❌ | ❌ | ❌ |
| Per-purpose, multiple protected actions | ✅ | ❌ | ➖ (single global window) | ➖ (single global window) | ❌ |
| Multi-tenant + audit trail | ✅ | ❌ | ❌ | ❌ | ❌ |
| Config validation in CI | ✅ | ❌ | ❌ | ❌ | ❌ |

> Legend: ✅ built-in · ➖ partial / hosted-only / not exposed to you · ❌ not available.
> Note on Shopify: it is a **hosted, closed commerce platform** you can neither self-host nor extend — it exposes none of these step-up primitives to your own Laravel app, so it's a black box you don't control.

---

## How it works (the flow, step by step)

```
Logged-in user → wants to perform a "purpose" action (e.g. checkout-credit-order)
        │
        ▼
[1] The middleware rebel.stepup:checkout-credit-order intercepts
        │
        ├─ is there already a VALID confirmation (within TTL, binding ok, device ok,
        │  assurance ≥ CURRENT policy)?  ── yes ──► pass through, run the action
        │
        └─ no ──► responds with 423 (JSON) or redirects to the confirmation page,
                  listing the drivers available for that purpose
        ▼
[2] The client starts the challenge:  RebelStepUp::start($ctx)
        │   - picks the best driver allowed by the policy
        │   - for payments, computes binding_hash = HMAC(amount|currency|payee|order)
        │   - the driver sends the factor (e.g. email with OTP)  → creates the challenge
        ▼
[3] The user enters the code:  RebelStepUp::confirm($challengeId, $code, $ctx)
        │   - transaction + lockForUpdate (atomic, single-use)
        │   - re-verifies the binding (amount/payee MUST NOT have changed)
        │   - delegates factor verification to the driver
        │   - if ok: status=verified, saves the ACHIEVED assurance, audits
        ▼
[4] Now isConfirmed($ctx) = true for the TTL window → the middleware lets it through
```

**What happens if…**
- *the user gets the code wrong too many times* → the challenge goes to `failed` (max attempts configurable);
- *the amount changes between `start` and `confirm`* → `binding_mismatch`, you start over (the SCA mandates it);
- *you raise the policy from `aal1` to `aal2` after a confirmation* → the old `aal1` confirmation **no longer counts**;
- *the factor provider goes down during `start`* → the challenge is **cancelled** (no orphan "pending" entries).

---

## Installation (junior-proof)

> Prerequisites: Laravel **12 or 13**, PHP **8.3+**, with `padosoft/laravel-rebel-core` and `padosoft/laravel-rebel-email-otp` already installed (they are pulled in as dependencies).

**1) Require the package**

```bash
composer require padosoft/laravel-rebel-step-up
```

**2) Publish config and migration**

```bash
php artisan vendor:publish --tag="rebel-step-up-config"
php artisan vendor:publish --tag="rebel-step-up-migrations"
php artisan migrate
```

**3) Configure the pepper (if you haven't already done so for the core)**

Step-up uses the core's keyed hashing for the SCA binding. In your `.env`:

```dotenv
REBEL_PEPPER_CURRENT=1
REBEL_PEPPER_1=put-a-long-and-random-secret-here
```

**4) Define your protected actions** in `config/rebel-step-up.php` (see below) and protect a route:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rebel.stepup:change-email'])
    ->post('/account/email', [AccountController::class, 'updateEmail']);
```

Done: the route now requires a step-up for the `change-email` purpose.

---

## Configuration (every option)

File `config/rebel-step-up.php`. Global keys:

| Key | Default | What it does | When to change it |
|---|---|---|---|
| `default_ttl_seconds` | `600` | Default duration of the **confirmation window** (how long a successful confirmation stays valid). | Very sensitive actions → lower it (e.g. 120). |
| `challenge_ttl_seconds` | `300` | Expiry of the **single challenge** (how long you have to enter the code). | Align it with the channel's OTP duration. |
| `max_attempts` | `5` | Wrong attempts before marking the challenge `failed`. | Stricter → lower it to 3. |
| `redirect_route` | `null` | For **web** (non-JSON) requests: the route name of the confirmation page. `null` ⇒ `abort(423)`. | Set your own challenge route. |
| `purposes` | see below | Your **protected actions** and their respective rules. | Always: this is where you declare what to protect. |

Each `purposes` entry accepts:

| Purpose key | Default | What it does |
|---|---|---|
| `required_assurance` | `aal1` | Minimum required AAL level (`aal1` / `aal2`). |
| `require_phishing_resistant` | `false` | If `true`, allows **only** phishing-resistant drivers (e.g. passkey). |
| `reject_restricted` | `false` | If `true`, rejects NIST "restricted" authenticators (e.g. SMS). |
| `drivers` | `['email_otp']` | Allowed drivers, **in order of preference**. The first available and eligible one is chosen. |
| `ttl_seconds` | `default_ttl_seconds` | Override of the confirmation window for THIS purpose. |
| `always_require` | `true` | **Reserved** for the risk-based hook (coming soon): today step-up is **always** required. Setting `false` does not yet skip verification — it will once the risk evaluator is wired up. |
| `sca.dynamic_linking` | `false` | If `true`, enables **binding** to amount+payee (for payments). |

Example:

```php
'purposes' => [
    'change-email' => [
        'required_assurance' => 'aal1',
        'drivers' => ['email_otp'],
    ],

    'download-invoice' => [
        'required_assurance' => 'aal1',
        'drivers' => ['email_otp'],
        'ttl_seconds' => 900, // a quarter of an hour, it's low-sensitivity
    ],

    'checkout-credit-order' => [
        'required_assurance' => 'aal2',
        'require_phishing_resistant' => true,           // demand a passkey…
        'drivers' => ['fortify_passkey_confirm', 'email_otp'], // …with OTP fallback
        'sca' => ['dynamic_linking' => true],           // PSD2: bind to amount+payee
    ],
],
```

> ⚠️ If a purpose requires `aal2` + `require_phishing_resistant` but lists only `email_otp` (which is `aal1`, not phishing-resistant), the config is **insecure**: `rebel:validate-config` fails in CI before deploy (see below).

---

## Usage examples

### 1. Protect a route with the middleware

```php
// routes/web.php
Route::middleware(['auth', 'rebel.stepup:change-email'])->group(function () {
    Route::post('/account/email', [AccountController::class, 'updateEmail']);
});
```

- **JSON / API request** without a valid confirmation → `423 Locked`:

```json
{
  "error": "step_up_required",
  "purpose": "change-email",
  "required_assurance": "aal1",
  "drivers": ["email_otp"]
}
```

- **Web request** without a confirmation → redirect to `redirect_route` (if set) or `abort(423)`.

### 2. Manual control (without middleware)

When you want to handle the flow yourself in a controller:

```php
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\StepUp\RebelStepUp;
use Padosoft\Rebel\StepUp\StepUpContext;

public function updateEmail(Request $request, RebelStepUp $stepUp)
{
    $ctx = new StepUpContext(
        subject: $request->user(),
        purpose: 'change-email',
        security: SecurityContext::fromRequest($request),
    );

    if (! $stepUp->isConfirmed($ctx)) {
        // start the challenge and tell the client to show the code form
        $start = $stepUp->start($ctx);

        return response()->json([
            'step_up' => 'required',
            'challenge_id' => $start->challengeId,
            'driver' => $start->driver,
        ], 423);
    }

    // valid confirmation: proceed
    $request->user()->update(['email' => $request->input('email')]);

    return response()->json(['ok' => true]);
}
```

### 3. Payment with PSD2/SCA dynamic linking

The confirmation is **bound** to amount+currency+payee+order. If the user confirms €100 and then someone tries to push the order through at €999, the confirmation **does not count**.

```php
use Padosoft\Rebel\StepUp\Sca\TransactionContext;

$ctx = new StepUpContext(
    subject: $request->user(),
    purpose: 'checkout-credit-order',
    security: SecurityContext::fromRequest($request),
    transaction: new TransactionContext(
        amount:   1250.00,
        currency: 'EUR',
        payee:    'ACME Srl',
        orderRef: 'ORD-2026-0042',
    ),
);

$start = $stepUp->start($ctx);          // computes and freezes the binding_hash
// …the user enters the code / uses the passkey…
$result = $stepUp->confirm($start->challengeId, $code, $ctx);

if (! $result->success) {
    // $result->reason may be 'binding_mismatch' if amount/payee changed
    return back()->withErrors(__('The transaction changed, please re-confirm.'));
}
```

### 4. Start and confirm a challenge (API/mobile)

A two-endpoint pattern, perfect for mobile apps (Sanctum tokens):

```php
// POST /api/step-up/start
$start = $stepUp->start($ctx);
return ['challenge_id' => $start->challengeId, 'driver' => $start->driver];

// POST /api/step-up/confirm   { challenge_id, code }
$result = $stepUp->confirm($request->string('challenge_id'), $request->string('code'), $ctx);
return $result->success
    ? response()->json(['confirmed' => true])
    : response()->json(['error' => $result->reason], 422);
```

### 5. Choose the driver (passkey-first, OTP fallback)

The policy lists the drivers in order of preference; you can also force one:

```php
// use the preferred available driver (e.g. passkey if the user has one)
$start = $stepUp->start($ctx);

// or explicitly force the email OTP fallback
$start = $stepUp->start($ctx, driverKey: 'email_otp');

// which drivers are usable RIGHT NOW for this user/purpose?
foreach ($stepUp->availableDrivers($ctx) as $driver) {
    echo $driver->key();
}
```

### 6. Bind the confirmation to the device

Pass a `deviceId` (e.g. derived from the Sanctum token or from `hash(ip|user-agent)`): the confirmation will count **only** for that device.

```php
$ctx = new StepUpContext(
    subject:  $request->user(),
    purpose:  'checkout-credit-order',
    security: SecurityContext::fromRequest($request),
    deviceId: $request->user()->currentAccessToken()?->id
        ? 'tok-'.$request->user()->currentAccessToken()->id
        : null,
);
```

A confirmation made on device A does **not** unlock the action on device B.

---

## Validating the config in CI

Step-up extends the core's command:

```bash
php artisan rebel:validate-config
```

It exits with a code **≠ 0** if a purpose is configured insecurely, for example:
- it requires an assurance that **none** of the listed drivers can reach;
- it demands `phishing_resistant` but lists only non-phishing-resistant drivers;
- it points to an **unregistered** driver.

Put it in your CI pipeline so you don't ship rules to production that can't be satisfied:

```yaml
- name: Validate the Rebel config
  run: php artisan rebel:validate-config
```

---

## `.env.example`

The package commits an `.env.example` with all the variables used. The essential ones:

```dotenv
# --- Keyed hashing (shared with the core): needed for the SCA binding ---
# The pepper version currently in use.
REBEL_PEPPER_CURRENT=1
# The pepper secret(s) (one per version). Long, random, NEVER committed.
REBEL_PEPPER_1=change-this-with-a-long-and-random-secret

# --- Step-up (optional: they have sensible defaults in the config) ---
# Default confirmation window, in seconds.
REBEL_STEPUP_TTL=600
# Expiry of the single challenge, in seconds.
REBEL_STEPUP_CHALLENGE_TTL=300
# Maximum attempts before locking the challenge.
REBEL_STEPUP_MAX_ATTEMPTS=5
# (optional) Route name of the confirmation page for web requests.
REBEL_STEPUP_REDIRECT_ROUTE=
```

---

## Security (what it guarantees you)

- **Atomic & single-use verification**: `confirm` runs in a transaction with `lockForUpdate`; two concurrent confirmations don't both pass.
- **Assurance enforcement against the CURRENT policy**: a successful confirmation saves the *achieved* assurance; if the policy is raised, the "old", weaker confirmation lapses.
- **PSD2/SCA dynamic linking**: keyed `HMAC` binding (with `key_version` for rotation) over amount+currency+payee+order; **anti-injection JSON** canonicalization (no collisions from separators in the fields).
- **Symmetric device binding**: a context without a device ⇒ only deviceless confirmations; with a device ⇒ only that device. No cross reuse.
- **Tenant isolation**: every query is scoped per tenant (null-safe).
- **Fail-closed**: missing/corrupted assurance data ⇒ the confirmation is **not** valid; an invalid amount (NaN/∞/negative) ⇒ an immediate exception.
- **Audit**: `StepUpRequired`, `StepUpVerified`, `StepUpFailed` recorded via the core's `AuditLogger`.

---

## Testing & License

```bash
composer test      # Pest (manager flows, SCA, TTL, middleware, config, real OTP driver)
composer phpstan   # static analysis, max level
composer pint      # code style
```

The suite covers: start/confirm, wrong code + max attempts, no eligible driver, **dynamic linking** (amount change, separator collision), TTL expiry, **policy raising**, **device binding**, cancellation on driver crash, middleware 423→OK, config validation, and the **real** integration with the `email_otp` driver.

**License:** MIT — see [LICENSE](LICENSE). Part of the [`padosoft/laravel-rebel`](https://github.com/padosoft) suite.
