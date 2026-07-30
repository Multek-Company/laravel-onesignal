# Changelog

All notable changes to `multek/laravel-onesignal` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-07-29

See [UPGRADE.md](UPGRADE.md#upgrading-from-21-to-22) for the three things to check before
upgrading: a removed `protected` phone helper, both jobs becoming `afterCommit`, and direct
`OneSignalManager` construction now following config.

### Added

- `HasOneSignal::deleteFromOneSignalAsync()` and `Jobs\DeleteUserFromOneSignal` — the async
  half of the delete path, mirroring `syncToOneSignalAsync()`: same enablement gate, same
  `tries = 3` / `backoff = [10, 60, 300]` policy, same `onesignal.queue`. Consuming apps no
  longer hand-roll a queued closure with no retry policy, which could permanently orphan a
  profile on a transient 5xx. The job takes the external id (a string), not the model, so it
  works from a `deleted` hook after the row is gone. A `404` from OneSignal is treated as a
  completed erasure — logged at `debug`, not retried. ([#11](https://github.com/Multek-Company/laravel-onesignal/issues/11))
- `HasOneSignal::toOneSignalPayload()` and `oneSignalPayloadChanged()` — the sync
  payload as a first-class value, plus a derived answer to "does this save need a
  resync?". Replaces hand-maintained watched-attribute lists in consuming apps:
  a new tag or getter is covered the day it is added. Both sides of the diff are
  built from clones with relations cleared, so a changed foreign key is detected
  even when the relation was already eager-loaded.
- `Observers\OneSignalObserver` — attach with
  `#[ObservedBy(OneSignalObserver::class)]` and the whole app-side integration is
  that one attribute. Handles create, update, delete (leaving soft-deleted
  profiles intact until `forceDelete()`) and restore. See the README for what it
  covers and what still needs an observer on the related model.
  ([#12](https://github.com/Multek-Company/laravel-onesignal/issues/12))

### Fixed

- `SyncUserToOneSignal` sets `deleteWhenMissingModels`, so a user deleted between
  dispatch and execution drops the job instead of putting a
  `ModelNotFoundException` in `failed_jobs`. ([#12](https://github.com/Multek-Company/laravel-onesignal/issues/12))
- Both queued jobs now dispatch after the surrounding database transaction commits.
  Previously a worker could pick up `SyncUserToOneSignal` before the commit and sync
  the pre-update row — and because the job succeeded, nothing retried it — or run
  `DeleteUserFromOneSignal` for a delete that was then rolled back, leaving no
  OneSignal profile for a row that still exists. A no-op outside a transaction.

### Changed

- **Removed the `protected validatedOneSignalPhone()` helper**, replaced by
  `normalizedOneSignalPhone()`, which returns the E.164 phone or `null` silently.
  The non-E.164 warning now fires once from `syncToOneSignal()` instead, because the
  payload is built twice for the resync diff and the old method would have logged
  twice. It was never a documented extension point, but an override of it will now
  silently stop running — see [UPGRADE.md](UPGRADE.md#upgrading-from-21-to-22).

- `OneSignalManager` reads `enabled`, `app_id` and `track_events` from `config()`
  at call time instead of snapshotting them at first resolution, and resolves the
  SDK client on first use. Constructor arguments still override config, so
  passing an explicit `enabled:` argument behaves as before; constructing
  without one now follows `onesignal.enabled` (default `true`) instead of
  always forcing `true`, so only a consumer relying on that forced-`true`
  default is affected. Tests no longer need
  `app()->forgetInstance(OneSignalManager::class)` to observe a config change.
  ([#12](https://github.com/Multek-Company/laravel-onesignal/issues/12))

## [2.1.0] - 2026-07-29

### Changed

- `onesignal:backfill` no longer requires `ONESIGNAL_SYNC_MODEL`. When
  `onesignal.sync_model` is empty it falls back to the auth provider model
  (`config('auth.providers.users.model')`) and then to `App\Models\User`. Setting
  `ONESIGNAL_SYNC_MODEL` still overrides both — use it when the syncable model isn't
  the authenticated user.

## [2.0.1] - 2026-07-29

### Documentation

- Added `docs/client-sdk-integration.md`: how this package (the server half) pairs with
  OneSignal's web and mobile SDKs (the device half) — `external_id` as the join key,
  Web SDK setup, mobile apps that wrap the site in a WebView (where web push does not
  work), and the rules that keep a multi-SDK setup consistent.
- README links the guide from a new "Client-side setup (web & mobile)" section.

No code changes.

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
