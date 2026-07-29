<?php

use Multek\OneSignal\Channels\OneSignalChannel;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\api\DefaultApi;

it('registers OneSignalManager as singleton', function () {
    $manager = app(OneSignalManager::class);

    expect($manager)->toBeInstanceOf(OneSignalManager::class)
        ->and($manager->getAppId())->toBe('test-app-id');
});

it('registers DefaultApi as singleton', function () {
    $api = app(DefaultApi::class);

    expect($api)->toBeInstanceOf(DefaultApi::class);
});

it('registers OneSignalChannel as singleton', function () {
    $channel = app(OneSignalChannel::class);

    expect($channel)->toBeInstanceOf(OneSignalChannel::class);
});

it('resolves same instance for singletons', function () {
    $manager1 = app(OneSignalManager::class);
    $manager2 = app(OneSignalManager::class);

    expect($manager1)->toBe($manager2);
});

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
