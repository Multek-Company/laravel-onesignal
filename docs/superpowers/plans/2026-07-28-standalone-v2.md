# laravel-onesignal v2.0.0 Standalone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `multek/laravel-onesignal` a fully standalone package covering the complete OneSignal lifecycle (identity, properties, subscriptions, tags, events, notifications), dropping the `laravel-customer-engagement` dependency.

**Architecture:** The enabled/disabled gate lives inside `OneSignalManager` (single choke point, nullable returns). Sync is a single `createUser` upsert call carrying tags + native properties + Email/SMS subscriptions. The `HasOneSignal` trait is the consumer-facing contract; a console backfill command chunks the configured model and dispatches the existing sync job per record.

**Tech Stack:** PHP 8.2+, Laravel 11/12/13 (illuminate components), `onesignal/onesignal-php-api ^5.0@beta`, Pest 3 + Orchestra Testbench, Mockery.

**Spec:** `docs/superpowers/specs/2026-07-28-standalone-v2-design.md` — read it before starting.

## Global Constraints

- Namespace `Multek\OneSignal`; PSR-4 from `src/`
- PHP `^8.2`; illuminate `^11.0|^12.0|^13.0`
- Tags are NEVER auto-populated with email/phone/name — no compat flag exists
- Package is disabled when `config('onesignal.enabled')` is falsy OR `config('onesignal.app_id')` is empty; disabled = silent no-op + `Log::debug`, never an exception
- `track_events` default `false` (OneSignal Free rejects custom events with 403)
- Phone must match E.164 (`/^\+[1-9]\d{6,14}$/`) or it is omitted with `Log::warning`
- Backfill chunk default: **250**
- No control-flow try/catch (the v1 `try update → catch create` pattern must not survive)
- Test with `./vendor/bin/pest` from the package root; style with `./vendor/bin/pint`
- Commit after every task (steps say when)

---

### Task 1: Decouple from laravel-customer-engagement

Remove the dependency, the driver, and its registration. The suite must be green at the end with a fresh `composer install` (no path repository).

**Files:**
- Modify: `composer.json` (remove `repositories` block + `multek/laravel-customer-engagement` require)
- Delete: `src/OneSignalDriver.php`
- Delete: `tests/Feature/OneSignalDriverTest.php`
- Modify: `src/OneSignalServiceProvider.php` (remove driver auto-registration)
- Modify: `tests/TestCase.php` (remove `CustomerEngagementServiceProvider`)

**Interfaces:**
- Consumes: nothing
- Produces: a package whose only runtime deps are illuminate + the OneSignal SDK; `OneSignalServiceProvider::boot()` contains only the config publish (later tasks add commands)

- [ ] **Step 1: Edit `composer.json`**

Remove the entire `"repositories"` array and the line `"multek/laravel-customer-engagement": "^1.1|@dev"` from `require`. Everything else stays.

- [ ] **Step 2: Delete the driver and its test**

```bash
git rm src/OneSignalDriver.php tests/Feature/OneSignalDriverTest.php
```

- [ ] **Step 3: Clean the service provider**

In `src/OneSignalServiceProvider.php`:
- Remove `use Multek\CustomerEngagement\EngagementManager;`
- In `boot()`, delete the whole block starting with the comment `// Register as a customer engagement driver` (the `if ($this->app->bound(EngagementManager::class))` block). `boot()` keeps only the `publishes` call.

- [ ] **Step 4: Clean the TestCase**

In `tests/TestCase.php`, remove the `CustomerEngagementServiceProvider::class` entry (and its import) from `getPackageProviders()`, leaving only `OneSignalServiceProvider::class`.

- [ ] **Step 5: Fresh install and full suite**

```bash
rm -rf vendor composer.lock
composer install
./vendor/bin/pest
```

Expected: install succeeds with no path repository; all remaining tests PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat!: drop laravel-customer-engagement dependency and driver"
```

---

### Task 2: Config keys + enabled gate in OneSignalManager

**Files:**
- Modify: `config/onesignal.php`
- Modify: `src/OneSignalManager.php`
- Modify: `src/OneSignalServiceProvider.php`
- Test: `tests/Feature/OneSignalManagerTest.php`, `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Consumes: Task 1's cleaned provider
- Produces:
  - `OneSignalManager::__construct(DefaultApi $api, string $appId, bool $enabled = true, bool $trackEvents = true)`
  - `OneSignalManager::isEnabled(): bool`
  - Nullable returns when disabled: `getUser(): ?User`, `createUser(): ?User`, `updateUser(): ?PropertiesBody`, `updateUserTags(): ?PropertiesBody`, `removeUserTags(): ?PropertiesBody`; `sendNotification()/sendToUser()/sendToUsers()/sendToSegment()` return `[]`; `deleteUser()`/`trackEvent*()` return void
  - Config keys `onesignal.enabled`, `onesignal.track_events`, `onesignal.sync_model`

- [ ] **Step 1: Add config keys**

In `config/onesignal.php` add (keeping the existing keys and comment style):

```php
'enabled' => env('ONESIGNAL_ENABLED', true),          // package also disables itself when app_id is empty
'track_events' => env('ONESIGNAL_TRACK_EVENTS', false), // Free plan rejects custom events with 403
'sync_model' => env('ONESIGNAL_SYNC_MODEL'),           // e.g. App\Models\User::class — used by onesignal:backfill
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/OneSignalManagerTest.php`:

```php
describe('disabled mode', function () {
    beforeEach(function () {
        $this->disabledManager = new OneSignalManager($this->api, '', enabled: false);
    });

    it('reports disabled state', function () {
        expect($this->disabledManager->isEnabled())->toBeFalse()
            ->and($this->manager->isEnabled())->toBeTrue();
    });

    it('never touches the api when disabled', function () {
        $this->api->shouldNotReceive('createNotification', 'createUser', 'getUser', 'updateUser', 'deleteUser', 'createCustomEvents');

        expect($this->disabledManager->sendToUser('u1', 'Hi'))->toBe([])
            ->and($this->disabledManager->getUser('u1'))->toBeNull()
            ->and($this->disabledManager->createUser('u1', ['plan' => 'pro']))->toBeNull()
            ->and($this->disabledManager->updateUser('u1', ['plan' => 'pro']))->toBeNull()
            ->and($this->disabledManager->updateUserTags('u1', ['plan' => 'pro']))->toBeNull()
            ->and($this->disabledManager->removeUserTags('u1', ['plan']))->toBeNull();

        $this->disabledManager->deleteUser('u1');
        $this->disabledManager->trackEvent('u1', 'purchase');
    });

    it('logs a debug line when skipping', function () {
        Log::shouldReceive('debug')->atLeast()->once()
            ->with(Mockery::pattern('/OneSignal disabled, skipping/'));

        $this->disabledManager->sendToUser('u1', 'Hi');
    });
});
```

Append to `tests/Feature/ServiceProviderTest.php`:

```php
it('disables the manager when app_id is empty', function () {
    config(['onesignal.app_id' => null]);
    $this->app->forgetInstance(OneSignalManager::class);

    expect(app(OneSignalManager::class)->isEnabled())->toBeFalse();
});

it('disables the manager when ONESIGNAL_ENABLED is false', function () {
    config(['onesignal.enabled' => false]);
    $this->app->forgetInstance(OneSignalManager::class);

    expect(app(OneSignalManager::class)->isEnabled())->toBeFalse();
});
```

(Add `use Illuminate\Support\Facades\Log;` at the top of the manager test file.)

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter "disabled"`
Expected: FAIL — unknown constructor argument `enabled` / method `isEnabled` does not exist.

- [ ] **Step 4: Implement the gate**

In `src/OneSignalManager.php`:

```php
use Illuminate\Support\Facades\Log;

public function __construct(
    protected DefaultApi $api,
    protected string $appId,
    protected bool $enabled = true,
    protected bool $trackEvents = true,
) {}

public function isEnabled(): bool
{
    return $this->enabled;
}

/**
 * True (and logs) when the package is disabled — callers no-op.
 */
protected function skips(string $operation): bool
{
    if ($this->enabled) {
        return false;
    }

    Log::debug("OneSignal disabled, skipping {$operation}");

    return true;
}
```

Then gate every API-touching method at the top:

- `sendNotification(Notification $notification): array` → `if ($this->skips(__FUNCTION__)) { return []; }` (the `sendToUser/sendToUsers/sendToSegment` helpers all funnel through it via the builder, no extra gate needed)
- `getUser(string $externalId): ?OneSignalUser` → return `null`
- `createUser(...): ?OneSignalUser` → return `null`
- `updateUser(...): ?PropertiesBody` → return `null`
- `updateUserTags(...): ?PropertiesBody` and `removeUserTags(...): ?PropertiesBody` → they delegate to `updateUser`, only the return types change to nullable
- `deleteUser(string $externalId): void` → `if ($this->skips(__FUNCTION__)) { return; }`
- `trackEvents(array $events): void` → `if ($this->skips(__FUNCTION__)) { return; }` (`trackEvent`/`trackEventForUsers` funnel through it)

- [ ] **Step 5: Wire the provider**

In `src/OneSignalServiceProvider.php`, replace the `OneSignalManager` singleton closure body with:

```php
$appId = (string) (config('onesignal.app_id') ?? '');

return new OneSignalManager(
    api: $app->make(DefaultApi::class),
    appId: $appId,
    enabled: (bool) config('onesignal.enabled', true) && $appId !== '',
    trackEvents: (bool) config('onesignal.track_events', false),
);
```

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS (pre-existing tests use `new OneSignalManager($api, 'test-app-id')` — defaults keep them enabled).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: enabled/disabled gate inside OneSignalManager"
```

---

### Task 3: track_events guard

**Files:**
- Modify: `src/OneSignalManager.php`
- Test: `tests/Feature/OneSignalManagerTest.php`

**Interfaces:**
- Consumes: Task 2's constructor (`bool $trackEvents`)
- Produces: `trackEvent`/`trackEvents`/`trackEventForUsers` no-op with `Log::debug` when `$trackEvents` is false, even if the package is enabled

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/OneSignalManagerTest.php`:

```php
describe('event tracking guard', function () {
    it('skips tracking when track_events is off', function () {
        Log::shouldReceive('debug')->once()
            ->with(Mockery::pattern('/event tracking disabled/'));

        $manager = new OneSignalManager($this->api, 'test-app-id', trackEvents: false);
        $this->api->shouldNotReceive('createCustomEvents');

        $manager->trackEvent('u1', 'purchase', ['amount' => 50]);
    });

    it('tracks when track_events is on', function () {
        $this->api->shouldReceive('createCustomEvents')->once();

        $this->manager->trackEvent('u1', 'purchase', ['amount' => 50]);
    });
});
```

- [ ] **Step 2: Run tests to verify the first fails**

Run: `./vendor/bin/pest --filter "event tracking"`
Expected: first test FAILS (api mock receives `createCustomEvents` unexpectedly), second PASSES.

- [ ] **Step 3: Implement the guard**

At the top of `trackEvents(array $events): void`, after the enabled gate:

```php
if (! $this->trackEvents) {
    Log::debug('OneSignal event tracking disabled, skipping trackEvents');

    return;
}
```

- [ ] **Step 4: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: ONESIGNAL_TRACK_EVENTS guard for custom events"
```

---

### Task 4: createUser carries Email/SMS subscriptions

**Files:**
- Modify: `src/OneSignalManager.php`
- Test: `tests/Feature/OneSignalManagerTest.php`

**Interfaces:**
- Consumes: SDK models `onesignal\client\model\Subscription` (constants `TYPE_EMAIL`, `TYPE_SMS`; setters `setType`, `setToken`) and `User::setSubscriptions(array)`
- Produces: `createUser(string $externalId, array $tags = [], array $properties = [], ?string $email = null, ?string $phone = null): ?OneSignalUser` — identity + properties + subscriptions in one request

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/OneSignalManagerTest.php` (add `use onesignal\client\model\Subscription;` at the top):

```php
it('creates a user with email and sms subscriptions', function () {
    $this->api->shouldReceive('createUser')
        ->once()
        ->withArgs(function (string $appId, OneSignalUser $user) {
            $subs = $user->getSubscriptions();

            return $appId === 'test-app-id'
                && $user->getIdentity() === ['external_id' => 'u1']
                && count($subs) === 2
                && $subs[0]->getType() === Subscription::TYPE_EMAIL
                && $subs[0]->getToken() === 'ana@example.com'
                && $subs[1]->getType() === Subscription::TYPE_SMS
                && $subs[1]->getToken() === '+5511999999999';
        })
        ->andReturn(new OneSignalUser);

    $this->manager->createUser('u1', ['plan' => 'pro'], ['language' => 'pt'], 'ana@example.com', '+5511999999999');
});

it('creates a user without subscriptions when email and phone are null', function () {
    $this->api->shouldReceive('createUser')
        ->once()
        ->withArgs(fn (string $appId, OneSignalUser $user) => $user->getSubscriptions() === null)
        ->andReturn(new OneSignalUser);

    $this->manager->createUser('u1', ['plan' => 'pro']);
});
```

- [ ] **Step 2: Run tests to verify the first fails**

Run: `./vendor/bin/pest --filter "subscriptions when"`
Expected: FAIL — `createUser` takes no `$email`/`$phone` yet.

- [ ] **Step 3: Implement**

In `src/OneSignalManager.php` (add `use onesignal\client\model\Subscription;`):

```php
public function createUser(
    string $externalId,
    array $tags = [],
    array $properties = [],
    ?string $email = null,
    ?string $phone = null,
): ?OneSignalUser {
    if ($this->skips(__FUNCTION__)) {
        return null;
    }

    $user = new OneSignalUser;
    $user->setIdentity(['external_id' => $externalId]);

    if (! empty($tags) || ! empty($properties)) {
        $user->setProperties($this->buildProperties($tags, $properties));
    }

    if ($subscriptions = $this->buildSubscriptions($email, $phone)) {
        $user->setSubscriptions($subscriptions);
    }

    return $this->api->createUser($this->appId, $user);
}

/**
 * @return Subscription[]
 */
protected function buildSubscriptions(?string $email, ?string $phone): array
{
    $subscriptions = [];

    if ($email !== null) {
        $subscription = new Subscription;
        $subscription->setType(Subscription::TYPE_EMAIL);
        $subscription->setToken($email);
        $subscriptions[] = $subscription;
    }

    if ($phone !== null) {
        $subscription = new Subscription;
        $subscription->setType(Subscription::TYPE_SMS);
        $subscription->setToken($phone);
        $subscriptions[] = $subscription;
    }

    return $subscriptions;
}
```

- [ ] **Step 4: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: createUser upsert carries Email/SMS subscriptions"
```

---

### Task 5: HasOneSignal trait v2

**Files:**
- Modify: `src/Concerns/HasOneSignal.php`
- Create: `tests/Fixtures/User.php`
- Test: `tests/Feature/HasOneSignalTest.php` (new)

**Interfaces:**
- Consumes: Task 4's `createUser(externalId, tags, properties, email, phone)`, Task 2's `OneSignal::isEnabled()`
- Produces: the full trait contract —
  - `getOneSignalExternalId(): string` (default `(string) $this->getKey()`)
  - `getOneSignalEmail(): ?string` (default `data_get($this, 'email')`)
  - `getOneSignalPhone(): ?string` (default `data_get($this, 'phone')`)
  - `getOneSignalLanguage(): ?string` / `getOneSignalTimezone(): ?string` / `getOneSignalCountry(): ?string` (default `null`)
  - `getOneSignalTags(): array` (unchanged from v1)
  - `syncToOneSignal(): void` — single `createUser` call, no try/catch
  - `syncToOneSignalAsync(): void` — returns without dispatching when disabled
  - `sendPush`, `trackOneSignalEvent`, `deleteFromOneSignal`, `routeNotificationForOnesignal` unchanged

- [ ] **Step 1: Create the fixture model**

`tests/Fixtures/User.php`:

```php
<?php

namespace Multek\OneSignal\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Multek\OneSignal\Concerns\HasOneSignal;

class User extends Model
{
    use HasOneSignal;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/HasOneSignalTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;
use Multek\OneSignal\Tests\Fixtures\User;

function fixtureUser(array $attributes = []): User
{
    return (new User)->forceFill(array_merge([
        'id' => 1,
        'email' => 'ana@example.com',
        'phone' => '+5511999999999',
    ], $attributes));
}

it('syncs with a single createUser call carrying the full profile', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    $manager->shouldReceive('createUser')
        ->once()
        ->with('1', [], [], 'ana@example.com', '+5511999999999');

    fixtureUser()->syncToOneSignal();
});

it('maps language, timezone and country to native properties', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    $user = new class extends User
    {
        public function getOneSignalLanguage(): ?string { return 'pt'; }
        public function getOneSignalTimezone(): ?string { return 'America/Sao_Paulo'; }
        public function getOneSignalCountry(): ?string { return 'BR'; }
    };

    $manager->shouldReceive('createUser')
        ->once()
        ->with('1', [], [
            'language' => 'pt',
            'timezone_id' => 'America/Sao_Paulo',
            'country' => 'BR',
        ], 'ana@example.com', '+5511999999999');

    $user->forceFill(['id' => 1, 'email' => 'ana@example.com', 'phone' => '+5511999999999'])->syncToOneSignal();
});

it('omits a non-E164 phone with a warning and keeps syncing', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/E\.164/'), Mockery::type('array'));

    $manager->shouldReceive('createUser')
        ->once()
        ->with('1', [], [], 'ana@example.com', null);

    fixtureUser(['phone' => '11 99999-9999'])->syncToOneSignal();
});

it('never copies email or phone into tags', function () {
    expect(fixtureUser()->getOneSignalTags())->toBe([]);
});

it('dispatches the sync job when enabled', function () {
    Bus::fake();

    fixtureUser()->syncToOneSignalAsync();

    Bus::assertDispatched(SyncUserToOneSignal::class);
});

it('dispatches nothing when disabled', function () {
    config(['onesignal.enabled' => false]);
    $this->app->forgetInstance(OneSignalManager::class);
    Bus::fake();

    fixtureUser()->syncToOneSignalAsync();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/HasOneSignalTest.php`
Expected: FAIL — trait still uses `updateUserTags`/try-catch, has no email/phone/property getters, async doesn't gate.

- [ ] **Step 4: Rewrite the trait**

Replace `syncToOneSignal`/`syncToOneSignalAsync` in `src/Concerns/HasOneSignal.php` and add the getters (add `use Illuminate\Support\Facades\Log;` and `use Multek\OneSignal\OneSignalManager;`):

```php
public function getOneSignalEmail(): ?string
{
    return data_get($this, 'email');
}

/**
 * Must be E.164 (+5511999999999). Anything else is omitted with a warning.
 */
public function getOneSignalPhone(): ?string
{
    return data_get($this, 'phone');
}

public function getOneSignalLanguage(): ?string // ISO 639-1, e.g. 'pt'
{
    return null;
}

public function getOneSignalTimezone(): ?string // IANA, e.g. 'America/Sao_Paulo'
{
    return null;
}

public function getOneSignalCountry(): ?string // ISO 3166-1 alpha-2, e.g. 'BR'
{
    return null;
}

/**
 * Sync the full profile in a single upsert call:
 * tags + native properties + Email/SMS subscriptions.
 */
public function syncToOneSignal(): void
{
    app(OneSignalManager::class)->createUser(
        $this->getOneSignalExternalId(),
        $this->getOneSignalTags(),
        array_filter([
            'language' => $this->getOneSignalLanguage(),
            'timezone_id' => $this->getOneSignalTimezone(),
            'country' => $this->getOneSignalCountry(),
        ], fn ($value) => $value !== null),
        $this->getOneSignalEmail(),
        $this->validatedOneSignalPhone(),
    );
}

public function syncToOneSignalAsync(): void
{
    if (! app(OneSignalManager::class)->isEnabled()) {
        Log::debug('OneSignal disabled, skipping sync dispatch');

        return;
    }

    dispatch(new SyncUserToOneSignal($this));
}

protected function validatedOneSignalPhone(): ?string
{
    $phone = $this->getOneSignalPhone();

    if ($phone === null) {
        return null;
    }

    if (! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
        Log::warning("OneSignal: phone '{$phone}' is not E.164, omitting SMS subscription", [
            'external_id' => $this->getOneSignalExternalId(),
        ]);

        return null;
    }

    return $phone;
}
```

Also update the trait's docblock: `getOneSignalTags()` gets a note that tags are custom segmentation only (plan limits: Free 2 / Growth 10 / Professional 100) and are never auto-populated with identity fields.

- [ ] **Step 5: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat!: HasOneSignal v2 — native properties, subscriptions, single-call sync"
```

---

### Task 6: onesignal:backfill command

**Files:**
- Create: `src/Commands/BackfillCommand.php`
- Modify: `src/OneSignalServiceProvider.php` (register command)
- Test: `tests/Feature/BackfillCommandTest.php` (new)

**Interfaces:**
- Consumes: `config('onesignal.sync_model')`, `OneSignalManager::isEnabled()`, `SyncUserToOneSignal` job, Task 5's fixture `Multek\OneSignal\Tests\Fixtures\User`
- Produces: `php artisan onesignal:backfill {--dry-run} {--chunk=250}` — exit 0 on success/disabled/dry-run, exit 1 on invalid `sync_model`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/BackfillCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\Tests\Fixtures\User;

beforeEach(function () {
    config(['onesignal.sync_model' => User::class]);

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
    });

    User::query()->insert([
        ['email' => 'a@example.com', 'phone' => null],
        ['email' => 'b@example.com', 'phone' => null],
        ['email' => 'c@example.com', 'phone' => null],
    ]);
});

it('dispatches one sync job per record', function () {
    Bus::fake();

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('Dispatched 3 sync jobs')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 3);
});

it('dispatches nothing on dry-run', function () {
    Bus::fake();

    $this->artisan('onesignal:backfill --dry-run')
        ->expectsOutputToContain('Would dispatch 3 sync jobs')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('aborts when the package is disabled', function () {
    config(['onesignal.enabled' => false]);
    $this->app->forgetInstance(\Multek\OneSignal\OneSignalManager::class);
    Bus::fake();

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('OneSignal disabled')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('fails on a missing or invalid sync_model', function () {
    config(['onesignal.sync_model' => null]);
    $this->artisan('onesignal:backfill')->assertExitCode(1);

    config(['onesignal.sync_model' => 'App\\Does\\Not\\Exist']);
    $this->artisan('onesignal:backfill')->assertExitCode(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/BackfillCommandTest.php`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement the command**

`src/Commands/BackfillCommand.php`:

```php
<?php

namespace Multek\OneSignal\Commands;

use Illuminate\Console\Command;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;

class BackfillCommand extends Command
{
    protected $signature = 'onesignal:backfill
        {--dry-run : Count what would be synced without dispatching anything}
        {--chunk=250 : Records per chunk}';

    protected $description = 'Dispatch a OneSignal sync job for every record of the configured sync model';

    public function handle(OneSignalManager $manager): int
    {
        if (! $manager->isEnabled()) {
            $this->warn('OneSignal disabled — nothing to do.');

            return self::SUCCESS;
        }

        $model = config('onesignal.sync_model');

        if (! is_string($model) || ! class_exists($model)) {
            $this->error('Invalid onesignal.sync_model: set ONESIGNAL_SYNC_MODEL to an existing model class.');

            return self::FAILURE;
        }

        $total = $model::query()->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would dispatch {$total} sync jobs for {$model}.");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $dispatched = 0;

        $model::query()->chunkById((int) $this->option('chunk'), function ($records) use (&$dispatched, $bar) {
            foreach ($records as $record) {
                dispatch(new SyncUserToOneSignal($record));
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$dispatched} sync jobs.");

        return self::SUCCESS;
    }
}
```

Register in `OneSignalServiceProvider::boot()`:

```php
if ($this->app->runningInConsole()) {
    $this->commands([Commands\BackfillCommand::class]);
}
```

- [ ] **Step 4: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: onesignal:backfill command (--dry-run, --chunk=250)"
```

---

### Task 7: Live smoke suite (env-gated)

**Files:**
- Create: `tests/Live/LiveTestCase.php`
- Create: `tests/Live/UserLifecycleTest.php`
- Modify: `tests/Pest.php`, `phpunit.xml`

**Interfaces:**
- Consumes: the full manager API from Tasks 2–4
- Produces: proof (or refutation) of the single-call upsert decision. If re-sync duplicates subscriptions, STOP and flag it — the spec's contingency (updateUser + createSubscription inside the manager) becomes a new task.

- [ ] **Step 1: Create the live TestCase**

`tests/Live/LiveTestCase.php`:

```php
<?php

namespace Multek\OneSignal\Tests\Live;

use Multek\OneSignal\Tests\TestCase;

abstract class LiveTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('onesignal.app_id', env('ONESIGNAL_TEST_APP_ID'));
        $app['config']->set('onesignal.rest_api_key', env('ONESIGNAL_TEST_REST_API_KEY'));
        $app['config']->set('onesignal.enabled', true);
        $app['config']->set('onesignal.track_events', (bool) env('ONESIGNAL_TEST_TRACK_EVENTS', false));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('ONESIGNAL_TEST_APP_ID') || ! env('ONESIGNAL_TEST_REST_API_KEY')) {
            $this->markTestSkipped('Set ONESIGNAL_TEST_APP_ID and ONESIGNAL_TEST_REST_API_KEY to run live tests.');
        }
    }
}
```

Add to `tests/Pest.php`:

```php
use Multek\OneSignal\Tests\Live\LiveTestCase;

uses(LiveTestCase::class)->in('Live');
```

Add to `phpunit.xml` inside `<testsuites>`:

```xml
<testsuite name="Live">
    <directory>tests/Live</directory>
</testsuite>
```

- [ ] **Step 2: Write the lifecycle test**

`tests/Live/UserLifecycleTest.php`:

```php
<?php

use Multek\OneSignal\OneSignalManager;
use onesignal\client\model\Subscription;

it('runs the full user lifecycle against the real API', function () {
    $manager = app(OneSignalManager::class);
    $externalId = 'pest-live-'.bin2hex(random_bytes(6));
    $email = "pest-live-{$externalId}@example.com";
    $phone = '+15005550006';

    try {
        // create — tags + native properties + subscriptions in one call
        $manager->createUser($externalId, ['role' => 'tester'], [
            'language' => 'pt',
            'timezone_id' => 'America/Sao_Paulo',
        ], $email, $phone);

        // get — everything landed
        $user = $manager->getUser($externalId);
        $properties = $user->getProperties();
        expect($properties->getTags())->toMatchArray(['role' => 'tester'])
            ->and($properties->getLanguage())->toBe('pt')
            ->and($properties->getTimezoneId())->toBe('America/Sao_Paulo');

        $tokens = array_map(fn (Subscription $s) => $s->getToken(), $user->getSubscriptions() ?? []);
        expect($tokens)->toContain($email)->toContain($phone);

        // sync again — proves upsert: nothing duplicates
        $manager->createUser($externalId, ['role' => 'tester'], ['language' => 'pt'], $email, $phone);
        $again = $manager->getUser($externalId);
        $emailSubs = array_filter($again->getSubscriptions() ?? [], fn (Subscription $s) => $s->getToken() === $email);
        expect(count($emailSubs))->toBe(1, 'Re-sync duplicated the email subscription — upsert assumption broken, apply the spec contingency.');

        // update tags
        $manager->updateUserTags($externalId, ['role' => 'admin']);
        expect($manager->getUser($externalId)->getProperties()->getTags()['role'])->toBe('admin');

        // track event (Free plan: guard keeps this a no-op)
        $manager->trackEvent($externalId, 'live_test_event', ['source' => 'pest']);
    } finally {
        $manager->deleteUser($externalId);
    }
});
```

- [ ] **Step 3: Verify the gate works without credentials**

Run: `./vendor/bin/pest tests/Live`
Expected: 1 test SKIPPED (no credentials locally). Full suite still green: `./vendor/bin/pest` → PASS + 1 skip.

- [ ] **Step 4: Run live if credentials are available**

If the user provides `ONESIGNAL_TEST_APP_ID`/`ONESIGNAL_TEST_REST_API_KEY`:

```bash
ONESIGNAL_TEST_APP_ID=... ONESIGNAL_TEST_REST_API_KEY=... ./vendor/bin/pest tests/Live
```

Expected: PASS. **If the duplicate-subscription assertion fails, stop and report — the manager needs the contingency path from the spec before release.**

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: env-gated live smoke suite for the full user lifecycle"
```

---

### Task 8: UPGRADE.md, README rewrite, CHANGELOG

**Files:**
- Create: `UPGRADE.md`
- Modify: `README.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: everything above
- Produces: release-ready docs for the v2.0.0 tag

- [ ] **Step 1: Write UPGRADE.md**

`UPGRADE.md` must contain these sections with this content (expand prose freely, keep facts exact):

```markdown
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

## 3. Config moves
Delete `config/customer-engagement.php`. In `config/onesignal.php` / .env:
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
```

- [ ] **Step 2: Rewrite README.md standalone-first**

Structure (no mention of drivers/engagement abstraction anywhere):

1. Intro: one paragraph — Laravel wrapper for the official OneSignal PHP SDK covering users (identity/properties/subscriptions), tags, events and push/email/SMS notifications.
2. Install: `composer require multek/laravel-onesignal`, publish config, env vars table (`ONESIGNAL_APP_ID`, `ONESIGNAL_REST_API_KEY`, `ONESIGNAL_ENABLED`, `ONESIGNAL_TRACK_EVENTS`, `ONESIGNAL_QUEUE`, `ONESIGNAL_SYNC_MODEL`).
3. "Zero-config local dev": explain disabled mode (empty app_id or `ONESIGNAL_ENABLED=false` → every call is a logged no-op).
4. The `HasOneSignal` trait: full contract from Task 5 with a worked `User` example overriding `getOneSignalTags()` (with the plan-limits warning: Free 2 / Growth 10 / Professional 100), `getOneSignalLanguage()`, and E.164 note for phone.
5. Sending notifications: facade one-liners + fluent builder + Laravel notification channel with `OneSignalMessage` (adapt existing v1 sections — content is still accurate).
6. Custom events: `trackOneSignalEvent` + `ONESIGNAL_TRACK_EVENTS` explanation (Free plan 403).
7. Backfill: `php artisan onesignal:backfill --dry-run`, then real run; `ONESIGNAL_SYNC_MODEL` requirement.
8. Testing section: mock suite + how to run the live suite with `ONESIGNAL_TEST_*` vars.

- [ ] **Step 3: Add CHANGELOG entry**

Prepend to `CHANGELOG.md` under a `## v2.0.0` heading: summary of breaking changes (dependency drop, identity-tags removal, nullable manager returns, async no-dispatch when disabled) and additions (enabled gate, track_events guard, subscriptions, backfill, live suite). Link `UPGRADE.md`.

- [ ] **Step 4: Style pass and full suite**

```bash
./vendor/bin/pint
./vendor/bin/pest
```

Expected: Pint clean, suite PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: UPGRADE.md, standalone README and v2.0.0 changelog"
```

---

## Out of scope (explicitly)

- Tagging/publishing v2.0.0 on Packagist (user action after merge)
- Freeze note in the `laravel-customer-engagement` repo (separate repo)
- Corbi's own migration (separate codebase; UPGRADE.md is the guide)
