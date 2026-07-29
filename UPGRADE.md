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
