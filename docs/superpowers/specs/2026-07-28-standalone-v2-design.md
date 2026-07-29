# laravel-onesignal v2.0.0 — Standalone Package Design

**Date:** 2026-07-28
**Issue:** [Multek-Company/laravel-onesignal#5](https://github.com/Multek-Company/laravel-onesignal/issues/5)
**Status:** Approved by Rodrigo (brainstorming session 2026-07-28)

## Context

The engagement abstraction experiment is over: `multek/laravel-customer-engagement`
is frozen at v1.1.0. v2.0.0 makes `laravel-onesignal` fully self-contained —
everything a Laravel app needs to integrate OneSignal (profiles, tags, events,
notifications, email/SMS reachability) lives here, modeled the way OneSignal
itself models it.

Known consumer: Corbi (Laravel 12 monolith), migrating right after release.

## Decisions (from brainstorming)

1. **Enabled gate lives inside `OneSignalManager`** — every public method checks
   it at the top; return types go nullable where needed. Single choke point.
2. **`identity_fields_as_tags` does not exist** — no compat flag. The v1
   "identity as tags" behavior was an abstraction artifact; v2 follows
   OneSignal's native model. Migrating consumers who want email/name as tags add
   them explicitly in `getOneSignalTags()`.
3. **Email/phone are supported natively as Subscriptions** (delivery channels),
   opt-out — defaults read `email`/`phone` from the model.
4. **Sync is a single `createUser` upsert call** — no try/update/catch/create,
   no separate subscription calls. Upsert semantics (no subscription
   duplication) must be proven by the live smoke test; fallback plan documented
   below.
5. **Disabled mode dispatches nothing** — `syncToOneSignalAsync()` gates before
   dispatch; no queue noise. The job's `handle()` keeps the gate as a seatbelt.
6. **Backfill chunk default is 250** (rate-limit and memory friendly),
   overridable via `--chunk=`.

## OneSignal's user model (why this shape)

A OneSignal User has three parts:

- **Identity** — `external_id` + aliases (who they are)
- **Properties** — tags, language, timezone_id, country, … (profile /
  segmentation data)
- **Subscriptions** — delivery channels: each push device, an Email
  subscription, an SMS subscription (how to reach them)

Therefore: email/phone are **subscriptions**, never tags. Language, timezone
and country are **native properties**, never tags. Tags are **exclusively
consumer-defined segmentation** (plan limits: Free 2 / Growth 10 /
Professional 100).

## What is removed

- `src/OneSignalDriver.php` (entire file)
- Driver auto-registration block in `OneSignalServiceProvider::boot()`
  (the `EngagementManager::extend('onesignal', …)` call)
- `multek/laravel-customer-engagement` from `require`, and the path repository
  entry in `composer.json`
- The forced copy of email/phone/name into data tags (v1
  `OneSignalDriver::customerTags()` behavior) — removed with **no** compat flag

## What is kept unchanged

- `NotificationBuilder` (fluent API), `OneSignalChannel`, `OneSignalMessage`
- `NotificationSent` / `NotificationFailed` events
- `SyncUserToOneSignal` job semantics: `tries = 3`, backoff `[10, 60, 300]`,
  configurable queue
- The `OneSignal` facade
- v1.0.1 type fixes (`UpdateUserRequest` + correct return types)

## Config (`config/onesignal.php`)

```php
'enabled'               => env('ONESIGNAL_ENABLED', true),
'app_id'                => env('ONESIGNAL_APP_ID'),
'rest_api_key'          => env('ONESIGNAL_REST_API_KEY'),
'organization_api_key'  => env('ONESIGNAL_ORGANIZATION_API_KEY'),
'track_events'          => env('ONESIGNAL_TRACK_EVENTS', false),
'sync_model'            => env('ONESIGNAL_SYNC_MODEL'),  // e.g. App\Models\User::class
'default_tags'          => [],                            // kept from v1
'queue'                 => env('ONESIGNAL_QUEUE', 'default'),
```

The package is **disabled** when `enabled` is `false` **or** `app_id` is empty.
Local/test environments need zero keys and produce zero HTTP calls.

`track_events` defaults to `false` because the Free plan rejects custom events
with `403 permission denied (entitlement)` (verified live). Flipping the env
after a plan upgrade requires no deploy.

## `OneSignalManager` changes

- Constructor receives `bool $enabled` (computed in the service provider:
  `config('onesignal.enabled') && filled(config('onesignal.app_id'))`) and
  `bool $trackEvents`.
- **Every public API-touching method** starts with the gate: if disabled →
  `Log::debug('OneSignal disabled, skipping <operation>')` and return a neutral
  value. Return types become nullable where the SDK returns objects:
  - `getUser(): ?User`, `createUser(): ?User`, `updateUser(): ?PropertiesBody`,
    `updateUserTags(): ?PropertiesBody`, `removeUserTags(): ?PropertiesBody`
  - `send*` methods already return `array` → return `[]`
  - `void` methods simply return
- `trackEvent` / `trackEvents` / `trackEventForUsers` have a **second** guard:
  if `track_events` is off → no-op + `Log::debug`.
- `createUser` gains the extended signature:

```php
public function createUser(
    string $externalId,
    array $tags = [],
    array $properties = [],   // language, timezone_id, country
    ?string $email = null,
    ?string $phone = null,
): ?User
```

  When `$email`/`$phone` are present, the SDK `User` gets a `subscriptions`
  array with entries of type `Email` / `SMS`. Identity, properties and
  subscriptions travel in the **same** request.

## Trait `HasOneSignal` (full contract)

```php
trait HasOneSignal
{
    // Identity
    public function getOneSignalExternalId(): string;        // default: (string) $this->getKey()
    public function routeNotificationForOnesignal(): string; // kept for the channel

    // Subscriptions (delivery channels)
    public function getOneSignalEmail(): ?string;  // default: data_get($this, 'email')
    public function getOneSignalPhone(): ?string;  // default: data_get($this, 'phone'); must be E.164

    // Native properties
    public function getOneSignalLanguage(): ?string;  // ISO 639-1;      default: null
    public function getOneSignalTimezone(): ?string;  // IANA;           default: null
    public function getOneSignalCountry(): ?string;   // ISO 3166-1 α-2; default: null

    // Tags — custom segmentation ONLY (docblock documents plan limits)
    public function getOneSignalTags(): array;  // default: built from config('onesignal.default_tags')

    // Actions
    public function syncToOneSignal(): void;       // single createUser upsert with everything above
    public function syncToOneSignalAsync(): void;  // gate → dispatch(SyncUserToOneSignal)
    public function deleteFromOneSignal(): void;
    public function trackOneSignalEvent(string $name, array $payload = [], ?\DateTimeInterface $timestamp = null): void;
    public function sendPush(string $message, array $data = []): array;  // kept
}
```

Rules:

- **Phone validation:** anything not matching E.164 (`+` followed by digits) is
  omitted from the payload with a `Log::warning` explaining why — the rest of
  the sync proceeds. A malformed phone never breaks a sync.
- Language/timezone/country map to
  `PropertiesObject::setLanguage/setTimezoneId/setCountry` — never to tags.
- `getOneSignalTags()` output is sent as-is; the package never injects
  identity fields into it.

## Sync data flow

```
$user->syncToOneSignal()
  └─ trait collects: externalId, tags, language/timezone/country, email, phone(E.164-validated)
      └─ OneSignalManager::createUser(...)          [gate: enabled?]
          └─ builds SDK User: identity + PropertiesObject + subscriptions[]
              └─ POST /apps/{app_id}/users          (upsert — exactly 1 call)
```

`syncToOneSignalAsync()`: if disabled → `Log::debug` + return (no dispatch).
Otherwise dispatches `SyncUserToOneSignal` on the configured queue. Tests that
assert dispatch must set `ONESIGNAL_ENABLED=true` (documented in UPGRADE.md).

**Upsert contingency:** if the live smoke test shows `createUser` does not
merge cleanly (e.g. duplicate email subscriptions on re-sync), fall back to:
`updateUser` for properties + `GET user` + `createSubscription` for missing
channels — isolated inside the manager so the trait/public API is unaffected.

## Backfill command

`src/Commands/BackfillCommand.php`, registered in the service provider
(console-only):

```
php artisan onesignal:backfill {--dry-run} {--chunk=250}
```

- Resolves `config('onesignal.sync_model')`; empty or non-existent class →
  clear error message, exit code 1.
- If package disabled → warn `OneSignal disabled — nothing to do`, exit 0.
- `Model::query()->chunkById($chunk)` → dispatches `SyncUserToOneSignal` per
  record on the configured queue. Idempotent (sync is an upsert).
- `--dry-run`: counts and reports what would be dispatched; zero jobs, zero
  HTTP.
- Progress bar + final summary (`Dispatched N sync jobs`).

## Error handling (single package-wide policy)

| Situation | Behavior |
|---|---|
| Package disabled | Silent no-op + `Log::debug` (never throws) |
| `track_events` off | No-op + `Log::debug` |
| Invalid phone (non-E.164) | Omitted from payload + `Log::warning`, sync proceeds |
| API error during sync (4xx/5xx) | Exception propagates → job retries (`tries=3`, backoff 10/60/300) |
| API error sending notification | Kept: `NotificationFailed` event + rethrow |

No control-flow try/catch anywhere: the v1 `try update → catch → create`
pattern is gone. An exception now always means a real error.

## Testing

### Mock suite (Pest + Orchestra Testbench — runs always, CI)

- Delete `OneSignalDriverTest`; remove driver-registration assertions from
  `ServiceProviderTest`.
- New/updated cases:
  - Gate: disabled → no manager method touches the API (mock never called),
    neutral returns, debug log emitted
  - `track_events=false` → `trackEvent` no-ops; `true` → calls
    `createCustomEvents`
  - Trait: sync payload correct — tags contain **no** email/phone/name,
    native properties mapped, Email/SMS subscriptions present when getters
    return values
  - Invalid phone → omitted + warning, sync proceeds
  - `syncToOneSignalAsync` while disabled → `Bus::assertNothingDispatched()`
  - Backfill: dry-run dispatches nothing; normal run dispatches N jobs;
    disabled aborts; invalid `sync_model` exits 1

### Live smoke suite (`tests/Live/`, env-gated)

- Auto-skips unless `ONESIGNAL_TEST_APP_ID` + `ONESIGNAL_TEST_REST_API_KEY`
  are set.
- Cycle: `create → get → sync again (proves upsert: no duplicated
  subscriptions) → update tags → trackEvent (skips on Free/403) → delete`.
- Asserts tags AND native properties AND subscriptions. This suite is the
  acceptance test for the single-call upsert decision.

## Release deliverables

1. **`UPGRADE.md`** — v1 → v2 consumer migration:
   - `HasCustomerEngagement` → `HasOneSignal`; method renames
     (`syncToEngagement` → `syncToOneSignal`, `trackEngagementEvent` →
     `trackOneSignalEvent`, `deleteFromEngagement` → `deleteFromOneSignal`, …)
   - `config/customer-engagement.php` deleted; keys move to `onesignal.php`
     (`enabled`, `track_events`, `queue`, `sync_model`)
   - `ENGAGEMENT_DRIVER` env replaced by `ONESIGNAL_ENABLED`
   - Email/name as tags is now an explicit opt-in inside `getOneSignalTags()`
   - `Bus::fake()` dispatch assertions need `ONESIGNAL_ENABLED=true`
2. **README** rewritten standalone-first (no mention of driver architecture)
3. **v2.0.0 tag** on Packagist
4. `laravel-customer-engagement` frozen at v1.1.0 (freeze note in that repo's
   README — outside this repo's scope)

## Corbi migration notes (known consumer)

Current data model carries over unchanged: tags `role` + `phone_verified`,
native language/timezone, events off on Free. Migration steps are exactly the
UPGRADE.md items above. New in v2 for Corbi: users become email/SMS-reachable
automatically via the subscription defaults (model `email`/`phone` columns).
