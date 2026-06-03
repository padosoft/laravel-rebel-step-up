# Laravel Rebel — Step-Up

> **Chiedi una ri-conferma forte solo quando serve davvero.** L'utente è già loggato, ma sta per fare un'azione sensibile (cambiare email, scaricare una fattura, confermare un ordine a credito): Rebel Step-Up gli chiede un **secondo fattore mirato** (OTP email, passkey, TOTP…), con livello di sicurezza **AAL/AMR** scelto per quell'azione e — per i pagamenti — **PSD2/SCA dynamic linking** (la conferma è legata a importo+beneficiario). Fa parte della suite `padosoft/laravel-rebel-*`.

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

## Indice

- [Cos'è (e cosa NON è)](#cosè-e-cosa-non-è)
- [Glossario rapido (leggilo, dura 1 minuto)](#glossario-rapido-leggilo-dura-1-minuto)
- [Perché Rebel Step-Up — i moat](#perché-rebel-step-up--i-moat)
- [Rebel Step-Up vs il "fai-da-te"](#rebel-step-up-vs-il-fai-da-te)
- [Come funziona (il flusso, passo-passo)](#come-funziona-il-flusso-passo-passo)
- [Installazione (a prova di junior)](#installazione-a-prova-di-junior)
- [Configurazione (ogni opzione)](#configurazione-ogni-opzione)
- [Esempi d'uso](#esempi-duso)
  - [1. Proteggere una rotta con il middleware](#1-proteggere-una-rotta-con-il-middleware)
  - [2. Controllo manuale (senza middleware)](#2-controllo-manuale-senza-middleware)
  - [3. Pagamento con PSD2/SCA dynamic linking](#3-pagamento-con-psd2sca-dynamic-linking)
  - [4. Avviare e confermare una sfida (API/mobile)](#4-avviare-e-confermare-una-sfida-apimobile)
  - [5. Scegliere il driver (passkey-first, fallback OTP)](#5-scegliere-il-driver-passkey-first-fallback-otp)
  - [6. Legare la conferma al device](#6-legare-la-conferma-al-device)
- [Validazione della config in CI](#validazione-della-config-in-ci)
- [`.env.example`](#envexample)
- [Sicurezza (cosa ti garantisce)](#sicurezza-cosa-ti-garantisce)
- [Testing & Licenza](#testing--licenza)

---

## Cos'è (e cosa NON è)

**È** il "control plane" che decide **quando** un utente già autenticato deve **ri-provare** chi è prima di un'azione delicata, **con quale forza** (assurance), e **legando** quella conferma all'azione specifica (per i pagamenti: a importo e beneficiario). Tu dichiari una policy per ogni *purpose* (azione) e Rebel fa rispettare la regola — via middleware o via API.

**Non è**:
- un sistema di **login** (per entrare c'è `laravel-rebel-email-otp` o Fortify via `laravel-rebel-bridge-fortify`); lo step-up presuppone un utente **già loggato**;
- un generatore di OTP a sé: per l'OTP via email usa l'engine di `laravel-rebel-email-otp`; per passkey/TOTP usa i driver di `laravel-rebel-bridge-fortify`. Step-Up li **orchestra**, non li reimplementa.

Dipende da [`padosoft/laravel-rebel-core`](https://github.com/padosoft/laravel-rebel-core) (assurance, contratti, hashing keyed) e da [`padosoft/laravel-rebel-email-otp`](https://github.com/padosoft/laravel-rebel-email-otp) (driver OTP di default). Per la **visione d'insieme** dell'ecosistema parti dal README del core.

---

## Glossario rapido (leggilo, dura 1 minuto)

| Termine | In parole semplici |
|---|---|
| **Step-up** | "Sei già dentro, ma per QUESTA cosa ti richiedo una prova in più." |
| **Purpose** | Il nome dell'azione protetta, es. `change-email`, `download-invoice`, `checkout-credit-order`. Ad ogni purpose associ una regola. |
| **AAL** (Authenticator Assurance Level) | Quanto è "forte" la prova, secondo lo standard NIST. `aal1` = un fattore (es. OTP email); `aal2` = due fattori / più robusto. |
| **AMR** | *Authentication Methods References*: la lista dei metodi usati, es. `['otp','email']`, `['webauthn']`. |
| **Phishing-resistant** | Una prova che il phishing non può rubare: tipicamente **passkey/FIDO2**. Un OTP via email **non** lo è. |
| **Driver** | Il "modo" con cui si fa la prova: `email_otp`, `fortify_passkey_confirm`, `fortify_totp`… Ognuno dichiara la propria assurance. |
| **Binding / Dynamic linking** | La conferma è **incollata** ai dettagli dell'operazione (importo, valuta, beneficiario, ordine). Se cambiano, la conferma decade: lo impone la **PSD2/SCA** europea per i pagamenti. |
| **Challenge** | La "pratica" di step-up aperta: ha id, scadenza, tentativi, stato. |
| **Confirmation window (TTL)** | Per quanto tempo una conferma resta valida dopo il successo (poi va rifatta). |

---

## Perché Rebel Step-Up — i moat

| ★ | Cosa | In breve |
|---|---|---|
| ★★★ | **Policy per-purpose** | Decidi per ogni azione il livello richiesto, i driver ammessi, il TTL. Niente `if` sparsi nel codice. |
| ★★★ | **Enforcement dell'assurance** | Un driver **sotto soglia** viene rifiutato a priori. E se alzi la policy, le **conferme vecchie più deboli decadono** subito. |
| ★★★ | **PSD2/SCA dynamic linking** | Conferma legata a importo+beneficiario con hash keyed; canonicalizzazione **anti-injection** (niente collisioni da separatori). |
| ★★ | **Driver pluggabili** | OTP email incluso; passkey/TOTP via bridge-fortify; i tuoi driver custom implementando un'interfaccia. |
| ★★ | **Atomico & anti-replay** | Verifica in transazione con `lockForUpdate`, single-use, max tentativi, scadenza. |
| ★★ | **Device binding** | La conferma può essere legata al dispositivo: nessun riuso incrociato tra device. |
| ★★ | **Multi-tenant & audit** | Tutto è scoped per tenant; ogni passo (`StepUpRequired/Verified/Failed`) è auditato. |
| ★ | **Config validata in CI** | `php artisan rebel:validate-config` blocca configurazioni insicure prima del deploy. |

---

## Rebel Step-Up vs il "fai-da-te"

| | **Rebel Step-Up** | Middleware `password.confirm` di Laravel | "Reinserisci la password" fatto a mano |
|---|---|---|---|
| Forza configurabile per azione (AAL/AMR) | ✅ | ❌ (solo password) | ❌ |
| Passkey / TOTP / OTP email intercambiabili | ✅ | ❌ | ❌ |
| PSD2/SCA dynamic linking (importo+payee) | ✅ | ❌ | ❌ |
| Conferma che decade se cambia l'importo | ✅ | ❌ | ❌ |
| Device binding | ✅ | ❌ | ❌ |
| Multi-tenant + audit trail | ✅ | ➖ | ❌ |
| Validazione config in CI | ✅ | ❌ | ❌ |

---

## Come funziona (il flusso, passo-passo)

```
Utente loggato → vuole fare un'azione "purpose" (es. checkout-credit-order)
        │
        ▼
[1] Il middleware rebel.stepup:checkout-credit-order intercetta
        │
        ├─ esiste già una conferma VALIDA (entro TTL, binding ok, device ok,
        │  assurance ≥ policy CORRENTE)?  ── sì ──► passa, esegui l'azione
        │
        └─ no ──► risponde 423 (JSON) o redirect alla pagina di conferma,
                  elencando i driver disponibili per quel purpose
        ▼
[2] Il client avvia la sfida:  RebelStepUp::start($ctx)
        │   - sceglie il driver migliore ammesso dalla policy
        │   - per i pagamenti calcola il binding_hash = HMAC(importo|valuta|payee|ordine)
        │   - il driver invia il fattore (es. email con OTP)  → crea la challenge
        ▼
[3] L'utente inserisce il codice:  RebelStepUp::confirm($challengeId, $code, $ctx)
        │   - transazione + lockForUpdate (atomico, single-use)
        │   - ri-verifica il binding (importo/payee NON devono essere cambiati)
        │   - delega al driver la verifica del fattore
        │   - se ok: status=verified, salva l'assurance RAGGIUNTA, audita
        ▼
[4] Ora isConfirmed($ctx) = true per la finestra TTL → il middleware fa passare
```

**Cosa succede se…**
- *l'utente sbaglia il codice troppe volte* → la challenge va in `failed` (max tentativi configurabile);
- *l'importo cambia tra `start` e `confirm`* → `binding_mismatch`, si rifà da capo (lo impone la SCA);
- *alzi la policy da `aal1` a `aal2` dopo una conferma* → la vecchia conferma `aal1` **non vale più**;
- *il provider del fattore va giù durante `start`* → la challenge viene **annullata** (niente "pending" orfani).

---

## Installazione (a prova di junior)

> Prerequisiti: Laravel **12 o 13**, PHP **8.3+**, già installati `padosoft/laravel-rebel-core` e `padosoft/laravel-rebel-email-otp` (vengono tirati come dipendenze).

**1) Richiedi il package**

```bash
composer require padosoft/laravel-rebel-step-up
```

**2) Pubblica config e migration**

```bash
php artisan vendor:publish --tag="rebel-step-up-config"
php artisan vendor:publish --tag="rebel-step-up-migrations"
php artisan migrate
```

**3) Configura il pepper (se non l'hai già fatto per il core)**

Lo step-up usa l'hashing keyed del core per il binding SCA. Nel tuo `.env`:

```dotenv
REBEL_PEPPER_CURRENT=1
REBEL_PEPPER_1=metti-qui-un-segreto-lungo-e-casuale
```

**4) Definisci le tue azioni protette** in `config/rebel-step-up.php` (vedi sotto) e proteggi una rotta:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rebel.stepup:change-email'])
    ->post('/account/email', [AccountController::class, 'updateEmail']);
```

Fatto: la rotta ora richiede uno step-up per il purpose `change-email`.

---

## Configurazione (ogni opzione)

File `config/rebel-step-up.php`. Chiavi globali:

| Chiave | Default | Cosa fa | Quando cambiarla |
|---|---|---|---|
| `default_ttl_seconds` | `600` | Durata di default della **finestra di conferma** (quanto resta valida una conferma riuscita). | Azioni molto sensibili → abbassa (es. 120). |
| `challenge_ttl_seconds` | `300` | Scadenza della **singola sfida** (entro quanto va inserito il codice). | Allinea alla durata dell'OTP del canale. |
| `max_attempts` | `5` | Tentativi sbagliati prima di marcare la sfida `failed`. | Più severo → abbassa a 3. |
| `redirect_route` | `null` | Per le richieste **web** (non-JSON): nome rotta della pagina di conferma. `null` ⇒ `abort(423)`. | Imposta la tua route di challenge. |
| `purposes` | vedi sotto | Le tue **azioni protette** e le rispettive regole. | Sempre: qui dichiari cosa proteggere. |

Ogni voce di `purposes` accetta:

| Chiave del purpose | Default | Cosa fa |
|---|---|---|
| `required_assurance` | `aal1` | Livello AAL minimo richiesto (`aal1` / `aal2`). |
| `require_phishing_resistant` | `false` | Se `true`, ammette **solo** driver phishing-resistant (es. passkey). |
| `reject_restricted` | `false` | Se `true`, rifiuta autenticatori "restricted" NIST (es. SMS). |
| `drivers` | `['email_otp']` | Driver ammessi, **in ordine di preferenza**. Il primo disponibile e idoneo viene scelto. |
| `ttl_seconds` | `default_ttl_seconds` | Override della finestra di conferma per QUESTO purpose. |
| `always_require` | `true` | **Riservato** all'hook risk-based (in arrivo): oggi lo step-up è **sempre** richiesto. Impostare `false` non salta ancora la verifica — lo farà quando il risk evaluator sarà collegato. |
| `sca.dynamic_linking` | `false` | Se `true`, attiva il **binding** a importo+beneficiario (per i pagamenti). |

Esempio:

```php
'purposes' => [
    'change-email' => [
        'required_assurance' => 'aal1',
        'drivers' => ['email_otp'],
    ],

    'download-invoice' => [
        'required_assurance' => 'aal1',
        'drivers' => ['email_otp'],
        'ttl_seconds' => 900, // un quarto d'ora, è poco sensibile
    ],

    'checkout-credit-order' => [
        'required_assurance' => 'aal2',
        'require_phishing_resistant' => true,           // pretendi passkey…
        'drivers' => ['fortify_passkey_confirm', 'email_otp'], // …con fallback OTP
        'sca' => ['dynamic_linking' => true],           // PSD2: lega a importo+payee
    ],
],
```

> ⚠️ Se un purpose richiede `aal2` + `require_phishing_resistant` ma elenca solo `email_otp` (che è `aal1`, non phishing-resistant), la config è **insicura**: `rebel:validate-config` fallisce in CI prima del deploy (vedi sotto).

---

## Esempi d'uso

### 1. Proteggere una rotta con il middleware

```php
// routes/web.php
Route::middleware(['auth', 'rebel.stepup:change-email'])->group(function () {
    Route::post('/account/email', [AccountController::class, 'updateEmail']);
});
```

- **Richiesta JSON / API** senza conferma valida → `423 Locked`:

```json
{
  "error": "step_up_required",
  "purpose": "change-email",
  "required_assurance": "aal1",
  "drivers": ["email_otp"]
}
```

- **Richiesta web** senza conferma → redirect a `redirect_route` (se impostata) o `abort(423)`.

### 2. Controllo manuale (senza middleware)

Quando vuoi gestire tu il flusso in un controller:

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
        // avvia la sfida e dì al client di mostrare il form del codice
        $start = $stepUp->start($ctx);

        return response()->json([
            'step_up' => 'required',
            'challenge_id' => $start->challengeId,
            'driver' => $start->driver,
        ], 423);
    }

    // conferma valida: procedi
    $request->user()->update(['email' => $request->input('email')]);

    return response()->json(['ok' => true]);
}
```

### 3. Pagamento con PSD2/SCA dynamic linking

La conferma viene **legata** a importo+valuta+beneficiario+ordine. Se l'utente conferma 100 € e poi qualcuno prova a far passare l'ordine a 999 €, la conferma **non vale**.

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

$start = $stepUp->start($ctx);          // calcola e congela il binding_hash
// …l'utente inserisce il codice / usa la passkey…
$result = $stepUp->confirm($start->challengeId, $code, $ctx);

if (! $result->success) {
    // $result->reason può essere 'binding_mismatch' se importo/payee sono cambiati
    return back()->withErrors(__('La transazione è cambiata, riconferma.'));
}
```

### 4. Avviare e confermare una sfida (API/mobile)

Pattern a due endpoint, perfetto per app mobile (token Sanctum):

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

### 5. Scegliere il driver (passkey-first, fallback OTP)

La policy elenca i driver in ordine di preferenza; puoi anche forzarne uno:

```php
// usa il driver preferito disponibile (es. passkey se l'utente ce l'ha)
$start = $stepUp->start($ctx);

// oppure forza esplicitamente il fallback OTP email
$start = $stepUp->start($ctx, driverKey: 'email_otp');

// quali driver sono utilizzabili ORA per questo utente/purpose?
foreach ($stepUp->availableDrivers($ctx) as $driver) {
    echo $driver->key();
}
```

### 6. Legare la conferma al device

Passa un `deviceId` (es. derivato dal token Sanctum o da `hash(ip|user-agent)`): la conferma varrà **solo** per quel device.

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

Una conferma fatta sul device A **non** sblocca l'azione sul device B.

---

## Validazione della config in CI

Lo step-up estende il comando del core:

```bash
php artisan rebel:validate-config
```

Esce con codice **≠ 0** se un purpose è configurato in modo insicuro, ad esempio:
- richiede un'assurance che **nessun** driver elencato può raggiungere;
- esige `phishing_resistant` ma elenca solo driver non phishing-resistant;
- punta a un driver **non registrato**.

Mettilo nella tua pipeline CI per non mandare in produzione regole che non si possono soddisfare:

```yaml
- name: Valida la config Rebel
  run: php artisan rebel:validate-config
```

---

## `.env.example`

Il package committa un `.env.example` con tutte le variabili usate. Le essenziali:

```dotenv
# --- Hashing keyed (condiviso col core): serve al binding SCA ---
# Versione del pepper attualmente in uso.
REBEL_PEPPER_CURRENT=1
# Il/i segreto/i pepper (uno per versione). Lungo, casuale, MAI committato.
REBEL_PEPPER_1=cambia-questo-con-un-segreto-lungo-e-casuale

# --- Step-up (opzionali: hanno default sensati nel config) ---
# Finestra di conferma di default, in secondi.
REBEL_STEPUP_TTL=600
# Scadenza della singola sfida, in secondi.
REBEL_STEPUP_CHALLENGE_TTL=300
# Tentativi massimi prima del blocco della sfida.
REBEL_STEPUP_MAX_ATTEMPTS=5
# (opzionale) Route name della pagina di conferma per le richieste web.
REBEL_STEPUP_REDIRECT_ROUTE=
```

---

## Sicurezza (cosa ti garantisce)

- **Verifica atomica & single-use**: `confirm` gira in transazione con `lockForUpdate`; due conferme concorrenti non passano entrambe.
- **Enforcement dell'assurance sulla policy CORRENTE**: una conferma riuscita salva l'assurance *raggiunta*; se la policy viene innalzata, la conferma "vecchia" più debole decade.
- **PSD2/SCA dynamic linking**: binding `HMAC` keyed (con `key_version` per la rotazione) su importo+valuta+beneficiario+ordine; canonicalizzazione **JSON anti-injection** (nessuna collisione da separatori nei campi).
- **Device binding simmetrico**: contesto senza device ⇒ solo conferme senza device; con device ⇒ solo quel device. Nessun riuso incrociato.
- **Isolamento tenant**: ogni query è scoped per tenant (null-safe).
- **Fail-closed**: dati di assurance mancanti/corrotti ⇒ la conferma **non** è valida; importo non valido (NaN/∞/negativo) ⇒ eccezione subito.
- **Audit**: `StepUpRequired`, `StepUpVerified`, `StepUpFailed` registrati via l'`AuditLogger` del core.

---

## Testing & Licenza

```bash
composer test      # Pest (flussi manager, SCA, TTL, middleware, config, driver OTP reale)
composer phpstan   # analisi statica, livello max
composer pint      # code style
```

La suite copre: avvio/conferma, codice errato + max tentativi, nessun driver idoneo, **dynamic linking** (cambio importo, collisione da separatori), scadenza TTL, **innalzamento della policy**, **device binding**, annullamento su crash del driver, middleware 423→OK, validazione config, e l'integrazione **reale** col driver `email_otp`.

**Licenza:** MIT — vedi [LICENSE](LICENSE). Fa parte della suite [`padosoft/laravel-rebel`](https://github.com/padosoft).
