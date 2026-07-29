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
