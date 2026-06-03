# Changelog

Tutte le modifiche rilevanti a `padosoft/laravel-rebel-step-up` sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
[Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

## [0.1.0] - 2026-06-03

### Added
- **Manager `RebelStepUp`**: `start()`, `confirm()`, `isConfirmed()`, `availableDrivers()`, `policy()`.
- **Policy per-purpose** da config (`required_assurance`, `require_phishing_resistant`,
  `reject_restricted`, `drivers`, `ttl_seconds`, `always_require`, `sca.dynamic_linking`),
  con `PolicyRepository` type-safe.
- **Enforcement dell'assurance**: i driver sotto soglia vengono rifiutati; le conferme
  esistenti sono ri-validate contro la policy **corrente** (una policy innalzata invalida
  le conferme più deboli).
- **PSD2/SCA dynamic linking**: `TransactionContext` con canonicalizzazione **JSON
  anti-injection** e `binding_hash` HMAC keyed (con `key_version` per la rotazione);
  `confirm()` rifiuta con `binding_mismatch` se importo/beneficiario cambiano.
- **Driver pluggabili**: contratto `StepUpDriver`, `DriverRegistry`, driver `email_otp`
  (su engine `laravel-rebel-email-otp`), `FakeStepUpDriver` per i test.
- **Verifica atomica**: transazione + `lockForUpdate`, single-use, max tentativi, scadenza.
- **Device binding simmetrico**: nessun riuso incrociato tra dispositivi.
- **Middleware** `rebel.stepup:{purpose}` (web redirect / JSON 423 con i driver disponibili).
- **Validazione config** `rebel:validate-config` estesa (purpose/driver/assurance).
- **Audit** degli eventi `StepUpRequired` / `StepUpVerified` / `StepUpFailed`.
- Multi-tenant (scoping null-safe), migration `rebel_step_up_challenges` (ULID).
- Suite Pest (manager, SCA, TTL, middleware, config, integrazione driver OTP reale),
  PHPStan livello max, Pint, CI matrix PHP 8.3/8.4/8.5 × Laravel 12/13.

[Unreleased]: https://github.com/padosoft/laravel-rebel-step-up/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/laravel-rebel-step-up/releases/tag/v0.1.0
