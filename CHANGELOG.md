# Changelog

All notable changes to `padosoft/laravel-rebel-step-up` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-06-03

### Added
- **`RebelStepUp` manager**: `start()`, `confirm()`, `isConfirmed()`, `availableDrivers()`, `policy()`.
- **Per-purpose policy** from config (`required_assurance`, `require_phishing_resistant`,
  `reject_restricted`, `drivers`, `ttl_seconds`, `always_require`, `sca.dynamic_linking`),
  with a type-safe `PolicyRepository`.
- **Assurance enforcement**: drivers below the threshold are rejected; existing
  confirmations are re-validated against the **current** policy (a raised policy invalidates
  the weaker confirmations).
- **PSD2/SCA dynamic linking**: `TransactionContext` with **anti-injection JSON**
  canonicalization and a keyed HMAC `binding_hash` (with `key_version` for rotation);
  `confirm()` rejects with `binding_mismatch` if the amount/payee changes.
- **Pluggable drivers**: `StepUpDriver` contract, `DriverRegistry`, `email_otp` driver
  (on the `laravel-rebel-email-otp` engine), `FakeStepUpDriver` for tests.
- **Atomic verification**: transaction + `lockForUpdate`, single-use, max attempts, expiry.
- **Symmetric device binding**: no cross-device reuse.
- **Middleware** `rebel.stepup:{purpose}` (web redirect / JSON 423 with the available drivers).
- **Config validation** `rebel:validate-config` extended (purpose/driver/assurance).
- **Audit** of the `StepUpRequired` / `StepUpVerified` / `StepUpFailed` events.
- Multi-tenant (null-safe scoping), `rebel_step_up_challenges` migration (ULID).
- Pest suite (manager, SCA, TTL, middleware, config, real OTP driver integration),
  PHPStan max level, Pint, CI matrix PHP 8.3/8.4/8.5 × Laravel 12/13.

[Unreleased]: https://github.com/padosoft/laravel-rebel-step-up/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/laravel-rebel-step-up/releases/tag/v0.1.0
