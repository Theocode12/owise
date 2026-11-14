# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton: service provider, `wise` config, Testbench workbench, Pest, Larastan, Pint, Rector and CI.
- Client layer: configured accounts, Laravel HTTP transport, `WiseManager` and the `Wise` facade.
- SDK adapter (`src/Api`): profiles, quotes, recipients, transfers, funding, balances, conversions, statements, webhook
  subscriptions, OAuth token exchange and sandbox simulation, returning immutable DTOs (`src/Data`) with `Brick\Money`
  amounts and the raw payload.
- CalVer `2026Q4` routing: every Wise REST operation resolves through an explicit operation catalogue
  (`Owise\Support\Endpoints`); no legacy `/vN` fallbacks.
- Webhooks: RSA-SHA256 signature verification, stateless ingestion and queued processing.
- OAuth: registration-code import (`useRegistrationCode`) and refresh with encrypted token storage.
- Recipients and the `Payable` trait, the payout workflow (quote → transfer → fund), and treasury (balances, conversions,
  statements).
- Error mapping to `AuthenticationFailed`, `PermissionDenied`, `StrongCustomerAuthenticationRequired`, `RateLimited`,
  `TransferCannotBeCancelled`, `WiseApiError` and `ConnectionFailed`, exposing Wise `errors[]`, trace and correlation ids.
- Strong Customer Authentication signing for UK/EEA personal tokens via `wise.sca.private_key`.
- Added `wise:doctor` diagnostics for configured profiles, credentials, connectivity, SCA and webhook keys, routes, queues, and
  OAuth cache locks; expanded the Laravel About section with Owise runtime configuration.

### Changed

- Depend on `theocode12/wise-php-sdk:^0.2` and use its `Theocode\Wise` namespace.
- Removed webhook persistence before v1: webhook ingestion is now stateless (verify → dispatch queued job → process → fire
  events). The `wise_webhook_events` migration, `Owise\Models\WiseWebhookEvent`, `Owise\Enums\WebhookEventStatus`, the
  `wise:webhooks:prune` command and the `wise.models.webhook_event` / `wise.webhooks.prune_days` config are gone. Anyone on the
  merged WP-04 branch should drop any published `wise_webhook_events` table/migration, remove those config overrides, and retype
  listeners from `WiseWebhookEvent` to `Owise\Webhooks\Payload`.
- `WiseProfile::profiles()` now returns `ProfilesApi`; call `->list()` for the profiles.
- **Breaking:** `PayoutBuilder::send()` and `sendLater()` now require `->idempotencyKey()`; reusing the same key resumes the
  same payout.
- **Breaking:** removed the operations with no `2026Q4` route: no-quote recipient requirements (`RecipientsApi::requirementsFor()`),
  non-JSON statement downloads (`StatementsApi::download()`), and OAuth user registration (`OAuthManager::registerUser()` /
  `resumeRegistration()`).
- **Breaking:** `WiseTransfer` now stores the local recipient under a new `recipient_id` column; `recipient()` resolves the local
  recipient rather than matching `wise_recipient_id` against the local primary key.
- `wise:transfers:sync` now resumes stuck drafts, quotes and pending API funding and reports created transfers with
  UNKNOWN/REJECTED funding; added `PayoutService::retryFunding()` as an operator escape hatch.
