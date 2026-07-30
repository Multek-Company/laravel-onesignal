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
    Bus::fake();

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('OneSignal disabled')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('falls back to the auth provider model when sync_model is empty', function () {
    config([
        'onesignal.sync_model' => null,
        'auth.providers.users.model' => User::class,
    ]);
    Bus::fake();

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('Dispatched 3 sync jobs')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(SyncUserToOneSignal::class, 3);
});

it('falls back to App\\Models\\User when nothing is configured', function () {
    config([
        'onesignal.sync_model' => null,
        'auth.providers.users.model' => null,
    ]);

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('App\\Models\\User does not exist')
        ->assertExitCode(1);
});

it('fails on an invalid sync_model', function () {
    config(['onesignal.sync_model' => 'App\\Does\\Not\\Exist']);
    $this->artisan('onesignal:backfill')->assertExitCode(1);
});

it('fails when the sync_model does not use HasOneSignal', function () {
    config(['onesignal.sync_model' => stdClass::class]);

    $this->artisan('onesignal:backfill')
        ->expectsOutputToContain('must use the HasOneSignal trait')
        ->assertExitCode(1);
});
