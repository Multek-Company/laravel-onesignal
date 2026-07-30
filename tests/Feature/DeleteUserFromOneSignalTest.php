<?php

use Multek\OneSignal\Jobs\DeleteUserFromOneSignal;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\ApiException;

it('deletes the user through the manager', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    $manager->shouldReceive('deleteUser')
        ->once()
        ->with('user_123');

    (new DeleteUserFromOneSignal('user_123'))->handle();
});

it('carries the same retry policy as the sync job', function () {
    $delete = new DeleteUserFromOneSignal('user_123');
    $sync = new SyncUserToOneSignal(null);

    expect($delete->tries)->toBe($sync->tries)
        ->and($delete->backoff)->toBe($sync->backoff);
});

it('runs on the configured queue', function () {
    config(['onesignal.queue' => 'onesignal-writes']);

    expect((new DeleteUserFromOneSignal('user_123'))->queue)->toBe('onesignal-writes');
});

it('treats a 404 as a completed deletion', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    $manager->shouldReceive('deleteUser')
        ->once()
        ->andThrow(new ApiException('Not Found', 404));

    (new DeleteUserFromOneSignal('user_123'))->handle();
});

it('rethrows a server error so the queue retries', function () {
    $manager = Mockery::mock(OneSignalManager::class);
    $this->app->instance(OneSignalManager::class, $manager);

    $manager->shouldReceive('deleteUser')
        ->once()
        ->andThrow(new ApiException('Service Unavailable', 503));

    (new DeleteUserFromOneSignal('user_123'))->handle();
})->throws(ApiException::class);
