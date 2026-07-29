<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;
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
    $this->app->forgetInstance(OneSignalManager::class);
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

it('fails when the sync_model does not use HasOneSignal', function () {
    config(['onesignal.sync_model' => stdClass::class]);

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('must use the HasOneSignal trait')
        ->assertExitCode(1);
});
