# Upgrading from 1.x to 2.0

v2.0.0 is standalone: the `multek/laravel-customer-engagement` dependency is gone.
If you still need the driver architecture, stay on `^1.1`.

## 1. Composer

- `composer remove multek/laravel-customer-engagement`
- `composer require multek/laravel-onesignal:^2.0`

## 2. Trait and method renames

| v1 (HasCustomerEngagement) | v2 (HasOneSignal) |
|---|---|
| `syncToEngagement()` | `syncToOneSignal()` |
| `syncToEngagementAsync()` | `syncToOneSignalAsync()` |
| `trackEngagementEvent()` | `trackOneSignalEvent()` |
| `deleteFromEngagement()` | `deleteFromOneSignal()` |
| `getEngagementExternalId()` | `getOneSignalExternalId()` |
| `getEngagementLanguage()/Timezone()/Country()` | `getOneSignalLanguage()/Timezone()/Country()` |

Swap the trait import from `Multek\CustomerEngagement\Concerns\HasCustomerEngagement` to
`Multek\OneSignal\Concerns\HasOneSignal` and rename every call site accordingly.

## 3. Config moves

Delete `config/customer-engagement.php`. In `config/onesignal.php` / `.env`:

| v1 | v2 |
|---|---|
| `ENGAGEMENT_DRIVER=onesignal` | `ONESIGNAL_ENABLED=true` |
| `ENGAGEMENT_DRIVER=null` | `ONESIGNAL_ENABLED=false` (or just leave `ONESIGNAL_APP_ID` empty) |
| `ENGAGEMENT_QUEUE` | `ONESIGNAL_QUEUE` |
| `drivers.onesignal.capabilities.events` | `ONESIGNAL_TRACK_EVENTS` (default false) |

## 4. BREAKING: identity fields are no longer tags

v1 force-copied email/phone/name into data tags. v2 never touches your tags:

- email/phone now become native Email/SMS **subscriptions** (reachable channels),
  read from `getOneSignalEmail()` / `getOneSignalPhone()` (defaults: the model's
  `email`/`phone` attributes; phone must be E.164)
- if you segment on name/email tags today, add them explicitly in `getOneSignalTags()`

## 5. Tests

`syncToOneSignalAsync()` no longer dispatches when the package is disabled.
Dispatch assertions with `Bus::fake()` need `config(['onesignal.enabled' => true])`
and a non-empty `onesignal.app_id`.

---

# Upgrading from 2.1 to 2.2

v2.2 is additive for normal use: add `#[ObservedBy(OneSignalObserver::class)]` to your
model and the observer does the rest. Three things are worth checking before you upgrade.

## 1. If you overrode `validatedOneSignalPhone()`

That `protected` helper is gone, replaced by `normalizedOneSignalPhone()`, which returns
the E.164 phone or `null` **silently** — the non-E.164 warning now fires once in
`syncToOneSignal()` instead. This split exists because the payload is built twice for the
resync diff, and the old method would have logged the warning twice.

It was never a documented extension point (it is absent from the trait contract table in
the README), so most apps are unaffected. But if you did override it, your override no
longer runs and no error is raised — rename it to `normalizedOneSignalPhone()` and drop
any logging from it.

## 2. Both queued jobs are now `afterCommit`

`SyncUserToOneSignal` and `DeleteUserFromOneSignal` wait for the surrounding
`DB::transaction()` to commit before a worker can pick them up. Previously a worker could
read a pre-commit row and sync stale data, or erase a profile whose delete was then rolled
back. This is a no-op outside a transaction.

If you relied on a sync job running mid-transaction, that no longer happens — and it was
racing the commit, so relying on it was never safe.

## 3. If you construct `OneSignalManager` directly

`enabled`, `app_id` and `track_events` are now read from `config()` at call time rather
than snapshotted when the singleton is built, so a `config()->set()` after resolution takes
effect and tests no longer need `app()->forgetInstance(OneSignalManager::class)`.

Constructor arguments still win over config, but they are now optional. One consequence:
`new OneSignalManager($api, 'app-id')` used to force `enabled = true` and now follows
`onesignal.enabled` (default `true`). Pass `enabled: true` explicitly if you need the old
behavior.

Resolving the manager no longer builds the SDK client — it is created on first use, so a
disabled package never constructs a Guzzle client at all.
