# Laravel OneSignal

Laravel wrapper for the official [OneSignal PHP SDK](https://github.com/OneSignal/onesignal-php-api). Send push notifications through a clean, fluent API, and sync OneSignal users — identity, native properties, tags, and Email/SMS subscriptions — so they're reachable through OneSignal's own email/SMS messaging (dashboard campaigns, journeys). Also tracks custom events for analytics and segmentation.

## Features

- **User Management** — create/update/delete OneSignal users with a single upsert call carrying tags, native properties (language, timezone, country), and Email/SMS subscriptions
- **Model Trait** — `HasOneSignal` trait for syncing Eloquent models to OneSignal, sync or queued
- **Push Notifications** — send to users, segments, or build complex payloads with a fluent builder
- **Laravel Notification Channel** — send via Laravel's native notification system with `OneSignalMessage`
- **Custom Event Tracking** — track events for analytics and segmentation, gated by plan support
- **Backfill Command** — `onesignal:backfill` to sync existing records in chunks
- **Zero-config local dev** — disabled automatically when unconfigured; every call becomes a logged no-op
- **Events** — `NotificationSent` and `NotificationFailed` events for observability

## Requirements

- PHP 8.2+
- Laravel 11.0+

## Installation

```bash
composer require multek/laravel-onesignal
```

Publish the config file:

```bash
php artisan vendor:publish --tag=onesignal-config
```

Add your credentials to `.env`:

```env
ONESIGNAL_APP_ID=your-app-id
ONESIGNAL_REST_API_KEY=your-rest-api-key
```

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `ONESIGNAL_APP_ID` | — | Your OneSignal App ID (Dashboard → Settings → Keys & IDs) |
| `ONESIGNAL_REST_API_KEY` | — | REST API key used for server-side calls |
| `ONESIGNAL_ENABLED` | `true` | Master switch. `false` (or an empty `ONESIGNAL_APP_ID`) turns every call into a no-op |
| `ONESIGNAL_TRACK_EVENTS` | `false` | Enables `trackEvent()`/`trackOneSignalEvent()`. Custom events are rejected with a 403 on OneSignal's Free plan — leave this off unless your plan supports them |
| `ONESIGNAL_QUEUE` | `default` | Queue name used for async user sync (`syncToOneSignalAsync()`, `onesignal:backfill`). Set to `null` to run inline |
| `ONESIGNAL_SYNC_MODEL` | — | Fully-qualified model class used by `onesignal:backfill`, e.g. `App\Models\User::class` |

`ONESIGNAL_ORGANIZATION_API_KEY` is also available for app-level management calls; most projects won't need it.

## Zero-config local dev

The package is safe to leave unconfigured. If `ONESIGNAL_APP_ID` is empty, or `ONESIGNAL_ENABLED=false` is set explicitly, `OneSignalManager::isEnabled()` returns `false` and every write operation (`createUser`, `updateUser`, `deleteUser`, `sendNotification`, `trackEvents`, `syncToOneSignalAsync()`'s dispatch) becomes a no-op that writes a `debug`-level log line instead of calling the API. This means:

- new environments (local, CI, preview apps) work out of the box with no OneSignal account
- `$user->syncToOneSignal()` and friends are safe to call unconditionally from model events/observers
- turning OneSignal on in an environment is just setting `ONESIGNAL_APP_ID` and `ONESIGNAL_REST_API_KEY`

## The `HasOneSignal` trait

Add the trait to any Eloquent model you want to sync to OneSignal:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Multek\OneSignal\Concerns\HasOneSignal;

class User extends Authenticatable
{
    use HasOneSignal;

    /**
     * Tags are custom segmentation data, separate from native properties.
     * Plan limits: Free 2 tags/user, Growth 10, Professional 100 — keep this list lean.
     */
    public function getOneSignalTags(): array
    {
        return [
            'plan' => $this->subscription_plan,
            'role' => $this->role?->name,
        ];
    }

    public function getOneSignalLanguage(): ?string
    {
        return $this->locale; // ISO 639-1, e.g. 'pt', 'en'
    }
}
```

Contract (all overridable, all have sane defaults):

| Method | Default | Notes |
|---|---|---|
| `getOneSignalExternalId(): string` | `$this->getKey()` | Identity used for every OneSignal call |
| `getOneSignalEmail(): ?string` | `$this->email` | Becomes a native **Email subscription**, not a tag |
| `getOneSignalPhone(): ?string` | `$this->phone` | Becomes a native **SMS subscription**, not a tag. Must be **E.164** (e.g. `+5511999999999`); non-conforming values are omitted with a `warning` log instead of failing the sync |
| `getOneSignalLanguage(): ?string` | `null` | ISO 639-1 (`pt`, `en`) — native property |
| `getOneSignalTimezone(): ?string` | `null` | IANA id (`America/Sao_Paulo`) — native property |
| `getOneSignalCountry(): ?string` | `null` | ISO 3166-1 alpha-2 (`BR`, `US`) — native property |
| `getOneSignalTags(): array` | built from `config('onesignal.default_tags')` | Custom segmentation tags only — plan-limited (Free 2 / Growth 10 / Professional 100) |

Identity fields (email, phone, language, timezone, country) are **never** auto-written as tags — only `getOneSignalTags()` controls tags.

Sync methods:

```php
$user->syncToOneSignal();       // synchronous: single upsert (tags + properties + subscriptions)
$user->syncToOneSignalAsync();  // dispatches SyncUserToOneSignal on the configured queue

$user->sendPush('Your order shipped!', ['order_id' => 456]);

$user->trackOneSignalEvent('purchase', ['amount' => 99.90]);

$user->deleteFromOneSignal();
```

`syncToOneSignalAsync()` checks `OneSignalManager::isEnabled()` before dispatching — when the package is disabled, no job is queued at all (not even a no-op job).

## Sending notifications

### Facade one-liners

```php
use Multek\OneSignal\Facades\OneSignal;

OneSignal::sendToUser('user_123', 'Hello!');
OneSignal::sendToUsers(['user_1', 'user_2'], 'Hello everyone!');
OneSignal::sendToSegment('Active Users', 'New feature available!');
```

### Fluent builder

```php
OneSignal::notification()
    ->toUser('user_123')
    ->heading('Order Shipped')
    ->body('Your order #456 has been shipped.')
    ->data(['order_id' => 456])
    ->image('https://example.com/banner.png')
    ->url('https://example.com/orders/456')
    ->send();
```

The builder also supports `toUsers()`, `toSegments()`, `excludeSegments()`, `withFilters()`, `subtitle()`, `addButton()`, `sendAfter()`, `throttle()`, `priority()`, `ttl()`, `template()`, `name()`, and `raw()` (escape hatch to the underlying SDK `Notification` object).

### Laravel notification channel

```php
use Multek\OneSignal\Messages\OneSignalMessage;

class OrderShipped extends Notification
{
    public function via($notifiable): array
    {
        return ['onesignal'];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        return OneSignalMessage::create('Your order has been shipped.')
            ->heading('Order Shipped')
            ->data(['order_id' => 456]);
    }
}
```

Models routing notifications use `routeNotificationForOnesignal()`, provided automatically by `HasOneSignal` (returns `getOneSignalExternalId()`).

### Raw SDK access

```php
$api = OneSignal::api(); // Returns onesignal\client\api\DefaultApi
```

## Custom events

```php
OneSignal::trackEvent('user_123', 'purchase', ['amount' => 99.90]);
OneSignal::trackEventForUsers(['user_1', 'user_2'], 'promo_viewed', ['campaign' => 'summer']);

// or from a model using HasOneSignal
$user->trackOneSignalEvent('purchase', ['amount' => 99.90]);
```

Event tracking is gated by `ONESIGNAL_TRACK_EVENTS` (default `false`). OneSignal's **Free plan rejects custom events with a 403** — leave the flag off there. When the flag is off, `trackEvent()`/`trackEvents()` no-op with a debug log instead of calling the API, so it's safe to call unconditionally from application code once your plan supports events.

## Backfill

Sync every record of a configured model to OneSignal in chunks:

```bash
php artisan onesignal:backfill --dry-run   # count what would be synced, dispatch nothing
php artisan onesignal:backfill             # dispatch a sync job per record
php artisan onesignal:backfill --chunk=500 # override the default chunk size (250)
```

Requires `ONESIGNAL_SYNC_MODEL` to point at a model class (e.g. `App\Models\User::class`) that uses `HasOneSignal`. The command exits early with an error if the class doesn't exist, and warns (without failing) if OneSignal is disabled.

## Testing

Run the mocked unit/feature suite (no network calls, safe in CI):

```bash
composer test
```

### Live suite

`tests/Live` exercises the full user lifecycle (create, get, re-sync/upsert, update tags, track event, delete) against the **real** OneSignal API. It's skipped automatically unless both env vars are set:

```env
ONESIGNAL_TEST_APP_ID=your-test-app-id
ONESIGNAL_TEST_REST_API_KEY=your-test-rest-api-key
# optional:
ONESIGNAL_TEST_TRACK_EVENTS=false
```

Use a dedicated OneSignal test app — the suite creates and deletes real users.

## Configuration

See `config/onesignal.php` for the full option reference, including default tags, queue name, sync model, and organization API key.

## License

MIT
