# Changelog

All notable changes to `multek/laravel-onesignal` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-07-28

**laravel-onesignal is now standalone.** See [UPGRADE.md](UPGRADE.md) for the full 1.x → 2.0 migration guide.

### Breaking

- Dropped the `multek/laravel-customer-engagement` dependency and driver registration
  (`EngagementDriver`, `SyncsUsers`, `SendsNotifications`, `TracksEvents`). The
  `HasCustomerEngagement` trait is replaced by `HasOneSignal`, with renamed methods
  (`syncToOneSignal()`, `syncToOneSignalAsync()`, `trackOneSignalEvent()`,
  `deleteFromOneSignal()`, `getOneSignalExternalId()`, `getOneSignalLanguage()/Timezone()/Country()`).
  `config/customer-engagement.php` is no longer read; everything now lives in
  `config/onesignal.php`.
- Identity fields are no longer copied into data tags. `getOneSignalEmail()` and
  `getOneSignalPhone()` now become native Email/SMS **subscriptions** instead of tags —
  phone must be E.164, non-conforming values are omitted with a warning. If you segment
  on name/email tags today, add them explicitly in `getOneSignalTags()`.
- `OneSignalManager::createUser()`, `updateUser()`, `getUser()` now return `null` (instead
  of throwing or hitting the API) when the package is disabled — check `isEnabled()` or
  handle a nullable return.
- `syncToOneSignalAsync()` no longer dispatches a job at all when OneSignal is disabled
  (previously dispatched a no-op job). `Bus::fake()` dispatch assertions need
  `config(['onesignal.enabled' => true])` and a non-empty `onesignal.app_id`.

### Added

- Enabled/disabled gate (`ONESIGNAL_ENABLED`, defaults to disabled when `ONESIGNAL_APP_ID`
  is empty): every write operation becomes a logged no-op, so the package is safe to use
  unconfigured in local/CI environments.
- `ONESIGNAL_TRACK_EVENTS` guard (default `false`) for custom event tracking — OneSignal's
  Free plan rejects custom events with a 403.
- `OneSignalManager::createUser()` now carries native Email/SMS subscriptions in the same
  upsert call as tags and properties, built from `getOneSignalEmail()`/`getOneSignalPhone()`.
- `onesignal:backfill {--dry-run} {--chunk=250}` artisan command to sync existing models
  configured via `ONESIGNAL_SYNC_MODEL`.
- `tests/Live`: an env-gated live smoke suite (`ONESIGNAL_TEST_APP_ID` /
  `ONESIGNAL_TEST_REST_API_KEY`) exercising the full user lifecycle against the real
  OneSignal API.

## [1.1.0] - 2026-07-28

### Added

- Native profile properties: the engagement driver now maps the `Customer` DTO's
  `language` (ISO 639-1), `timezone` (IANA id) and `country` (ISO 3166-1 alpha-2)
  fields — first-class in `laravel-customer-engagement` v1.1 — to OneSignal's native
  user properties (`PropertiesObject::setLanguage()` / `setTimezoneId()` / `setCountry()`)
  when non-null ([#3](https://github.com/Multek-Company/laravel-onesignal/issues/3)).
  Profile fields are never written as data tags: tags are plan-limited (Free: 2/user),
  native properties are free on all plans. `Customer::$attributes` still flows to tags.
- `OneSignalManager::updateUser($externalId, $tags, $properties)` to update tags and
  native properties in one call; `OneSignalManager::createUser()` accepts an optional
  third `$properties` argument (`language`, `timezone_id`, `country`).

### Changed

- Requires `multek/laravel-customer-engagement` `^1.1`.

## [1.0.1] - 2026-07-28

### Fixed

- `OneSignalManager::updateUserTags()` now sends the correct `UpdateUserRequest` model to
  `DefaultApi::updateUser()` instead of a `User` model. The malformed request body caused
  OneSignal to upsert users **without any data tags** in production ([#1](https://github.com/Multek-Company/laravel-onesignal/issues/1)).
- `updateUserTags()` and `removeUserTags()` now declare the return type the SDK actually
  returns (`PropertiesBody` instead of `User`), fixing a `TypeError` thrown even on
  successful HTTP calls.

### Upgrade notes

If you sync users through the `laravel-customer-engagement` driver, after updating:

```bash
composer update multek/laravel-onesignal
```

re-run your user sync backfill so existing users get their tags applied — the sync is
idempotent, so re-running it is safe.

## [1.0.0] - 2026-04-01

- Initial release: OneSignal manager, notification builder, Laravel notification channel,
  custom events, user management, and `laravel-customer-engagement` driver integration.
