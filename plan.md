# Plan: `multek/laravel-onesignal` — Composer Package

## Purpose

A **thin Laravel wrapper** around the official `onesignal/onesignal-php-api` SDK (v5.x) that adds:

1. **Laravel ergonomics** — ServiceProvider, Facade, `.env` config, publishable config file
2. **Fluent notification builder** — `OneSignal::notification()->toUser('123')->body('Hey')->send()` instead of verbose auto-generated SDK code
3. **Eloquent trait** — `$user->sendPush('message')`, `$user->syncToOneSignal()`
4. **Queue-ready jobs** — async user sync, retries with backoff
5. **Laravel events** — `NotificationSent`, `NotificationFailed`

**The official SDK does ALL the HTTP work.** This package just makes it pleasant to use in Laravel.

---

## Why wrap instead of using the SDK directly?

The official SDK works like this (verbose, no Laravel integration):

```php
// Official SDK — every single time:
$config = Configuration::getDefaultConfiguration()
    ->setRestApiKeyToken('your-key');

$api = new DefaultApi(new GuzzleHttp\Client(), $config);

$content = new StringMap();
$content->setEn('Your appointment is in 1 hour');

$notification = new Notification();
$notification->setAppId('your-app-id');
$notification->setContents($content);
$notification->setIncludeAliases(
    new PlayerNotificationTargetIncludeAliases(['external_id' => ['user_123']])
);
$notification->setTargetChannel('push');
$api->createNotification($notification);
```

With the wrapper:

```php
// Your wrapper:
OneSignal::sendToUser('user_123', 'Your appointment is in 1 hour');

// Or from a User model:
$user->sendPush('Your appointment is in 1 hour');
```

Same API calls underneath. Just ergonomics + Laravel conventions.

---

## Architecture

```
Laravel App
    |
    ├── OneSignal::sendToUser(...)        ← Facade (ergonomics)
    ├── $user->sendPush(...)              ← Eloquent trait
    ├── OneSignal::notification()->...    ← Fluent builder
    |
    ▼
multek/laravel-onesignal (this package)
    |  - ServiceProvider, Facade, Builder, Trait, Jobs
    |  - Translates fluent API → SDK model objects
    |
    ▼
onesignal/onesignal-php-api (official SDK)
    |  - DefaultApi, Notification, User, StringMap, etc.
    |  - Handles HTTP, auth, serialization, error responses
    |
    ▼
OneSignal REST API
    |
    ├── FCM/APNs → Mobile devices (Expo native shell)
    └── Web Push → Browser (OneSignal Web SDK)
```

---

## Package Structure

```
multek/laravel-onesignal/
├── src/
│   ├── OneSignalServiceProvider.php    # Auto-discovery, config, singleton
│   ├── Facades/
│   │   └── OneSignal.php              # Facade
│   ├── OneSignalManager.php           # Main service — wraps DefaultApi
│   ├── Builders/
│   │   └── NotificationBuilder.php    # Fluent API → SDK Notification model
│   ├── Concerns/
│   │   └── HasOneSignal.php           # Eloquent trait for User model
│   ├── Jobs/
│   │   └── SyncUserToOneSignal.php    # Queued user sync
│   └── Events/
│       ├── NotificationSent.php
│       └── NotificationFailed.php
├── config/
│   └── onesignal.php                  # Publishable config
├── composer.json
└── README.md
```

Note: **No** `Api/UserApi.php`, `Api/NotificationApi.php`, etc. The official SDK already has `DefaultApi` with `createUser`, `getUser`, `updateUser`, `deleteUser`, `createNotification`, etc. We don't duplicate that — we expose it through the Manager.

---

## Step 1: Project Setup

```bash
mkdir multek-laravel-onesignal && cd multek-laravel-onesignal
composer init --name="multek/laravel-onesignal" --type="library"
```

**`composer.json`:**
```json
{
  "name": "multek/laravel-onesignal",
  "description": "Laravel wrapper for the official OneSignal PHP SDK",
  "require": {
    "php": "^8.2",
    "illuminate/support": "^11.0|^12.0",
    "onesignal/onesignal-php-api": "^5.0"
  },
  "autoload": {
    "psr-4": {
      "Multek\\OneSignal\\": "src/"
    }
  },
  "extra": {
    "laravel": {
      "providers": ["Multek\\OneSignal\\OneSignalServiceProvider"],
      "aliases": { "OneSignal": "Multek\\OneSignal\\Facades\\OneSignal" }
    }
  }
}
```

Key: `onesignal/onesignal-php-api` is a **real dependency**, not replaced.

---

## Step 2: `config/onesignal.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OneSignal App ID
    |--------------------------------------------------------------------------
    | Same App ID used in mobile app (Expo) and web push.
    | Found in OneSignal Dashboard → Settings → Keys & IDs
    */
    'app_id' => env('ONESIGNAL_APP_ID'),

    /*
    |--------------------------------------------------------------------------
    | REST API Key
    |--------------------------------------------------------------------------
    | Used for server-side API calls (sending notifications, managing users).
    | Found in OneSignal Dashboard → Settings → Keys & IDs
    */
    'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Organization API Key (optional)
    |--------------------------------------------------------------------------
    | Only needed for app-level management (creating apps, etc).
    | Most projects won't need this.
    */
    'organization_api_key' => env('ONESIGNAL_ORGANIZATION_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Tags
    |--------------------------------------------------------------------------
    | Tags to sync automatically when calling $user->syncToOneSignal().
    | Maps OneSignal tag keys to User model attributes or closures.
    |
    | Example:
    |   'plan' => 'subscription_plan',             // $user->subscription_plan
    |   'role' => fn($user) => $user->role->name,  // closure
    */
    'default_tags' => [
        // 'plan' => 'subscription_plan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Queue name for async operations (user sync, batch sends).
    | Set to null to run synchronously.
    */
    'queue' => env('ONESIGNAL_QUEUE', 'default'),
];
```

---

## Step 3: `src/OneSignalServiceProvider.php`

```php
<?php

namespace Multek\OneSignal;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use onesignal\client\api\DefaultApi;
use onesignal\client\Configuration;

class OneSignalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onesignal.php', 'onesignal');

        // Register the official SDK's DefaultApi as a singleton
        $this->app->singleton(DefaultApi::class, function () {
            $config = Configuration::getDefaultConfiguration()
                ->setRestApiKeyToken(config('onesignal.rest_api_key'));

            if ($orgKey = config('onesignal.organization_api_key')) {
                $config->setOrganizationApiKeyToken($orgKey);
            }

            return new DefaultApi(new Client(), $config);
        });

        // Register our wrapper as a singleton
        $this->app->singleton(OneSignalManager::class, function ($app) {
            return new OneSignalManager(
                api: $app->make(DefaultApi::class),
                appId: config('onesignal.app_id'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/onesignal.php' => config_path('onesignal.php'),
        ], 'onesignal-config');
    }
}
```

---

## Step 4: `src/Facades/OneSignal.php`

```php
<?php

namespace Multek\OneSignal\Facades;

use Illuminate\Support\Facades\Facade;
use Multek\OneSignal\OneSignalManager;

/**
 * @method static \onesignal\client\api\DefaultApi api()
 * @method static \Multek\OneSignal\Builders\NotificationBuilder notification()
 * @method static array sendToUser(string $externalId, string $message, array $data = [])
 * @method static array sendToUsers(array $externalIds, string $message, array $data = [])
 * @method static array sendToSegment(string $segment, string $message, array $data = [])
 * @method static \onesignal\client\model\User getUser(string $externalId)
 * @method static \onesignal\client\model\User createUser(string $externalId, array $tags = [])
 * @method static \onesignal\client\model\User updateUserTags(string $externalId, array $tags)
 * @method static void deleteUser(string $externalId)
 *
 * @see \Multek\OneSignal\OneSignalManager
 */
class OneSignal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OneSignalManager::class;
    }
}
```

---

## Step 5: `src/OneSignalManager.php` — Main Service

This is the core. It wraps the official SDK's `DefaultApi` with convenience methods.

```php
<?php

namespace Multek\OneSignal;

use Multek\OneSignal\Builders\NotificationBuilder;
use Multek\OneSignal\Events\NotificationFailed;
use Multek\OneSignal\Events\NotificationSent;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\Notification;
use onesignal\client\model\PropertiesObject;
use onesignal\client\model\StringMap;
use onesignal\client\model\User as OneSignalUser;

class OneSignalManager
{
    public function __construct(
        protected DefaultApi $api,
        protected string $appId,
    ) {}

    // ──────────────────────────────────
    // Escape hatch — access the raw SDK
    // ──────────────────────────────────

    /**
     * Access the official SDK's DefaultApi directly for anything
     * not covered by convenience methods.
     */
    public function api(): DefaultApi
    {
        return $this->api;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    // ──────────────────────────────────
    // Notifications
    // ──────────────────────────────────

    /**
     * Start building a notification with the fluent API.
     */
    public function notification(): NotificationBuilder
    {
        return new NotificationBuilder($this);
    }

    /**
     * Send a simple push to a user by external_id.
     */
    public function sendToUser(string $externalId, string $message, array $data = []): array
    {
        return $this->notification()
            ->toUser($externalId)
            ->body($message)
            ->data($data)
            ->send();
    }

    /**
     * Send a push to multiple users by external_id.
     */
    public function sendToUsers(array $externalIds, string $message, array $data = []): array
    {
        return $this->notification()
            ->toUsers($externalIds)
            ->body($message)
            ->data($data)
            ->send();
    }

    /**
     * Send a push to a segment.
     */
    public function sendToSegment(string $segment, string $message, array $data = []): array
    {
        return $this->notification()
            ->toSegment($segment)
            ->body($message)
            ->data($data)
            ->send();
    }

    /**
     * Send a raw SDK Notification object.
     * Used internally by NotificationBuilder::send().
     */
    public function sendNotification(Notification $notification): array
    {
        try {
            $result = $this->api->createNotification($notification);
            $response = json_decode(json_encode($result), true) ?? [];

            event(new NotificationSent(
                notificationId: $response['id'] ?? '',
                response: $response,
            ));

            return $response;
        } catch (\Throwable $e) {
            event(new NotificationFailed(
                message: $e->getMessage(),
                statusCode: method_exists($e, 'getCode') ? (int) $e->getCode() : 0,
            ));

            throw $e;
        }
    }

    // ──────────────────────────────────
    // User Management
    // ──────────────────────────────────

    /**
     * Get a user from OneSignal by external_id.
     */
    public function getUser(string $externalId): OneSignalUser
    {
        return $this->api->getUser($this->appId, 'external_id', $externalId);
    }

    /**
     * Create a user in OneSignal.
     */
    public function createUser(string $externalId, array $tags = []): OneSignalUser
    {
        $user = new OneSignalUser();
        $user->setIdentity(['external_id' => $externalId]);

        if (!empty($tags)) {
            $properties = new PropertiesObject();
            $properties->setTags($tags);
            $user->setProperties($properties);
        }

        return $this->api->createUser($this->appId, $user);
    }

    /**
     * Update tags on a user.
     */
    public function updateUserTags(string $externalId, array $tags): OneSignalUser
    {
        $user = new OneSignalUser();
        $properties = new PropertiesObject();
        $properties->setTags($tags);
        $user->setProperties($properties);

        return $this->api->updateUser($this->appId, 'external_id', $externalId, $user);
    }

    /**
     * Remove tags from a user (sets them to empty string).
     */
    public function removeUserTags(string $externalId, array $tagKeys): OneSignalUser
    {
        return $this->updateUserTags($externalId, array_fill_keys($tagKeys, ''));
    }

    /**
     * Delete a user from OneSignal.
     */
    public function deleteUser(string $externalId): void
    {
        $this->api->deleteUser($this->appId, 'external_id', $externalId);
    }
}
```

---

## Step 6: `src/Builders/NotificationBuilder.php` — Fluent API

Translates fluent method calls into the SDK's `Notification` model object.

```php
<?php

namespace Multek\OneSignal\Builders;

use Multek\OneSignal\OneSignalManager;
use onesignal\client\model\Notification;
use onesignal\client\model\PlayerNotificationTargetIncludeAliases;
use onesignal\client\model\StringMap;

class NotificationBuilder
{
    protected Notification $notification;

    public function __construct(protected OneSignalManager $manager)
    {
        $this->notification = new Notification();
        $this->notification->setAppId($manager->getAppId());
    }

    // ── Targeting ──

    public function toUser(string $externalId): static
    {
        return $this->toUsers([$externalId]);
    }

    public function toUsers(array $externalIds): static
    {
        $aliases = new PlayerNotificationTargetIncludeAliases([
            'external_id' => $externalIds,
        ]);
        $this->notification->setIncludeAliases($aliases);
        $this->notification->setTargetChannel('push');
        return $this;
    }

    public function toSegment(string $segment): static
    {
        return $this->toSegments([$segment]);
    }

    public function toSegments(array $segments): static
    {
        $this->notification->setIncludedSegments($segments);
        return $this;
    }

    public function excludeSegments(array $segments): static
    {
        $this->notification->setExcludedSegments($segments);
        return $this;
    }

    public function withFilters(array $filters): static
    {
        $this->notification->setFilters($filters);
        return $this;
    }

    // ── Content ──

    public function body(string $message, string $locale = 'en'): static
    {
        $contents = $this->notification->getContents() ?? new StringMap();
        $contents->setEn($locale === 'en' ? $message : $contents->getEn());
        // For non-english, use the raw setter
        if ($locale !== 'en') {
            // StringMap supports dynamic locale setting
            $contents[$locale] = $message;
        }
        $this->notification->setContents($contents);
        return $this;
    }

    public function heading(string $title, string $locale = 'en'): static
    {
        $headings = $this->notification->getHeadings() ?? new StringMap();
        $headings->setEn($locale === 'en' ? $title : $headings->getEn());
        if ($locale !== 'en') {
            $headings[$locale] = $title;
        }
        $this->notification->setHeadings($headings);
        return $this;
    }

    public function subtitle(string $subtitle, string $locale = 'en'): static
    {
        $sub = $this->notification->getSubtitle() ?? new StringMap();
        $sub->setEn($locale === 'en' ? $subtitle : $sub->getEn());
        if ($locale !== 'en') {
            $sub[$locale] = $subtitle;
        }
        $this->notification->setSubtitle($sub);
        return $this;
    }

    public function image(string $url): static
    {
        $this->notification->setBigPicture($url);           // Android
        $this->notification->setIosAttachments(['image' => $url]); // iOS
        return $this;
    }

    public function url(string $url): static
    {
        $this->notification->setUrl($url);
        return $this;
    }

    // ── Custom Data ──

    public function data(array $data): static
    {
        $existing = $this->notification->getData() ?? [];
        $this->notification->setData(array_merge($existing, $data));
        return $this;
    }

    // ── Scheduling ──

    public function sendAfter(\DateTimeInterface|string $datetime): static
    {
        if ($datetime instanceof \DateTimeInterface) {
            $this->notification->setSendAfter($datetime);
        } else {
            $this->notification->setSendAfter(new \DateTime($datetime));
        }
        return $this;
    }

    public function throttle(int $perMinute): static
    {
        $this->notification->setThrottleRatePerMinute($perMinute);
        return $this;
    }

    // ── Buttons ──

    public function addButton(string $id, string $text): static
    {
        $buttons = $this->notification->getButtons() ?? [];
        $buttons[] = ['id' => $id, 'text' => $text];
        $this->notification->setButtons($buttons);
        return $this;
    }

    // ── Priority & TTL ──

    public function priority(int $priority): static
    {
        $this->notification->setPriority($priority);
        return $this;
    }

    public function ttl(int $seconds): static
    {
        $this->notification->setTtl($seconds);
        return $this;
    }

    // ── Template ──

    public function template(string $templateId): static
    {
        $this->notification->setTemplateId($templateId);
        return $this;
    }

    // ── Name (internal tracking) ──

    public function name(string $name): static
    {
        $this->notification->setName($name);
        return $this;
    }

    // ── Escape hatch ──

    /**
     * Access the raw SDK Notification object for anything
     * the builder doesn't cover.
     */
    public function raw(): Notification
    {
        return $this->notification;
    }

    // ── Send ──

    public function send(): array
    {
        return $this->manager->sendNotification($this->notification);
    }
}
```

---

## Step 7: `src/Concerns/HasOneSignal.php` — Eloquent Trait

```php
<?php

namespace Multek\OneSignal\Concerns;

use Multek\OneSignal\Facades\OneSignal;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;

/**
 * Add to your User model:
 *
 *   use HasOneSignal;
 *
 *   // Optionally override:
 *   public function getOneSignalExternalId(): string
 *   public function getOneSignalTags(): array
 */
trait HasOneSignal
{
    /**
     * Get the external ID for OneSignal (defaults to primary key).
     */
    public function getOneSignalExternalId(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Get the tags to sync to OneSignal.
     * Override in your User model for custom tags.
     */
    public function getOneSignalTags(): array
    {
        $tags = [];
        foreach (config('onesignal.default_tags', []) as $tagKey => $attribute) {
            if (is_callable($attribute)) {
                $tags[$tagKey] = (string) $attribute($this);
            } elseif (is_string($attribute) && isset($this->{$attribute})) {
                $tags[$tagKey] = (string) $this->{$attribute};
            }
        }
        return $tags;
    }

    /**
     * Sync this user to OneSignal (creates if not exists, updates tags).
     */
    public function syncToOneSignal(): void
    {
        $externalId = $this->getOneSignalExternalId();
        $tags = $this->getOneSignalTags();

        try {
            OneSignal::updateUserTags($externalId, $tags);
        } catch (\Throwable) {
            // User doesn't exist in OneSignal yet — create them
            OneSignal::createUser($externalId, $tags);
        }
    }

    /**
     * Dispatch a queued sync job.
     */
    public function syncToOneSignalAsync(): void
    {
        SyncUserToOneSignal::dispatch($this);
    }

    /**
     * Send a push notification to this user.
     */
    public function sendPush(string $message, array $data = []): array
    {
        return OneSignal::sendToUser(
            $this->getOneSignalExternalId(),
            $message,
            $data,
        );
    }

    /**
     * Delete this user from OneSignal.
     */
    public function deleteFromOneSignal(): void
    {
        OneSignal::deleteUser($this->getOneSignalExternalId());
    }
}
```

---

## Step 8: `src/Jobs/SyncUserToOneSignal.php`

```php
<?php

namespace Multek\OneSignal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncUserToOneSignal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(public $user)
    {
        $this->onQueue(config('onesignal.queue', 'default'));
    }

    public function handle(): void
    {
        $this->user->syncToOneSignal();
    }
}
```

---

## Step 9: `src/Events/`

```php
<?php
// src/Events/NotificationSent.php
namespace Multek\OneSignal\Events;

class NotificationSent
{
    public function __construct(
        public string $notificationId,
        public array $response,
    ) {}
}
```

```php
<?php
// src/Events/NotificationFailed.php
namespace Multek\OneSignal\Events;

class NotificationFailed
{
    public function __construct(
        public string $message,
        public int $statusCode,
    ) {}
}
```

---

## Installation

```bash
composer require multek/laravel-onesignal
php artisan vendor:publish --tag=onesignal-config
```

**.env:**
```
ONESIGNAL_APP_ID=your-app-id
ONESIGNAL_REST_API_KEY=your-rest-api-key
```

---

## Usage Examples

### Quick sends
```php
use Multek\OneSignal\Facades\OneSignal;

// To a user
OneSignal::sendToUser('user_123', 'Your appointment is in 1 hour');

// With custom data
OneSignal::sendToUser('user_123', 'New message from Dr. Silva', [
    'action' => 'open_chat',
    'chat_id' => 456,
]);

// To multiple users
OneSignal::sendToUsers(['user_1', 'user_2'], 'System maintenance at 2am');

// To a segment
OneSignal::sendToSegment('Pro Users', 'New feature available!');
```

### Fluent builder
```php
OneSignal::notification()
    ->toUser('user_123')
    ->heading('Appointment Reminder')
    ->body('Your appointment with Dr. Silva is in 1 hour')
    ->image('https://myapp.com/images/reminder.png')
    ->data(['action' => 'open_appointment', 'id' => 789])
    ->send();

// Scheduled
OneSignal::notification()
    ->toUser('user_123')
    ->body('How was your appointment?')
    ->data(['action' => 'open_feedback', 'appointment_id' => 789])
    ->sendAfter('2026-03-10 15:00:00 UTC')
    ->send();

// With segments + filters
OneSignal::notification()
    ->toSegment('Active Users')
    ->excludeSegments(['Churned'])
    ->heading('Weekly Digest')
    ->body('Here is your weekly summary')
    ->url('https://myapp.com/digest')
    ->send();

// Filter-based
OneSignal::notification()
    ->withFilters([
        ['field' => 'tag', 'key' => 'plan', 'relation' => '=', 'value' => 'pro'],
        ['operator' => 'AND'],
        ['field' => 'tag', 'key' => 'city', 'relation' => '=', 'value' => 'São Paulo'],
    ])
    ->heading('Local Event')
    ->body('Join our meetup this Friday!')
    ->send();
```

### Using the raw SDK (escape hatch)
```php
// For anything the wrapper doesn't cover, access the SDK directly:
$api = OneSignal::api();

// Full SDK power — segments, subscriptions, templates, live activities, etc.
$segments = $api->getSegments(config('onesignal.app_id'));
```

### User management
```php
// Create user with tags
OneSignal::createUser('user_123', [
    'plan' => 'pro',
    'role' => 'admin',
]);

// Update tags
OneSignal::updateUserTags('user_123', [
    'plan' => 'enterprise',
    'last_login' => now()->toDateString(),
]);

// Remove tags
OneSignal::removeUserTags('user_123', ['old_tag']);

// Get user
$user = OneSignal::getUser('user_123');

// Delete user
OneSignal::deleteUser('user_123');
```

### Eloquent trait
```php
// app/Models/User.php
use Multek\OneSignal\Concerns\HasOneSignal;

class User extends Authenticatable
{
    use HasOneSignal;

    public function getOneSignalTags(): array
    {
        return [
            'plan' => $this->subscription?->plan ?? 'free',
            'role' => $this->role,
            'city' => $this->city,
        ];
    }
}

// Then anywhere:
$user->sendPush('Welcome to Pro!', ['action' => 'show_confetti']);
$user->syncToOneSignal();        // Sync tags now
$user->syncToOneSignalAsync();   // Queue the sync
$user->deleteFromOneSignal();
```

### Observer for auto-sync
```php
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged(['subscription_plan', 'role', 'city'])) {
            $user->syncToOneSignalAsync();
        }
    }

    public function deleted(User $user): void
    {
        $user->deleteFromOneSignal();
    }
}
```

---

## Files Summary

| File | Purpose |
|---|---|
| `config/onesignal.php` | Publishable config (app_id, api_key, default_tags, queue) |
| `src/OneSignalServiceProvider.php` | Registers DefaultApi + OneSignalManager as singletons |
| `src/Facades/OneSignal.php` | Facade with IDE-friendly docblocks |
| `src/OneSignalManager.php` | Wraps DefaultApi with convenience methods |
| `src/Builders/NotificationBuilder.php` | Fluent API that builds SDK Notification objects |
| `src/Concerns/HasOneSignal.php` | Eloquent trait for User model |
| `src/Jobs/SyncUserToOneSignal.php` | Queued user sync with retry + backoff |
| `src/Events/NotificationSent.php` | Dispatched after successful send |
| `src/Events/NotificationFailed.php` | Dispatched on failure |

---

## Key Design Decisions

1. **Official SDK is the foundation** — `onesignal/onesignal-php-api` handles all HTTP, auth, serialization. We never make raw HTTP calls.
2. **Escape hatch** — `OneSignal::api()` gives direct access to the full SDK for anything the wrapper doesn't cover (segments, subscriptions, templates, live activities, etc).
3. **Wrapper only covers the 80%** — sending notifications, user CRUD, tag management. Everything else goes through `api()`.
4. **User Model only** — `external_id` everywhere, no legacy `player_id`.
5. **Thin by design** — ~5 files of real logic. If the official SDK improves its DX, this wrapper could shrink further.
6. **NotificationBuilder → SDK Notification** — the builder constructs real SDK model objects, not raw arrays. Type-safe all the way through.
