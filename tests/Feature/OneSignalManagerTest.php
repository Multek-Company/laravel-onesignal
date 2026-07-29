<?php

use Illuminate\Support\Facades\Log;
use Multek\OneSignal\Builders\NotificationBuilder;
use Multek\OneSignal\Events\NotificationFailed;
use Multek\OneSignal\Events\NotificationSent;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\PropertiesBody;
use onesignal\client\model\UpdateUserRequest;
use onesignal\client\model\User as OneSignalUser;

beforeEach(function () {
    $this->api = Mockery::mock(DefaultApi::class);
    $this->manager = new OneSignalManager($this->api, 'test-app-id');
});

it('returns the app id', function () {
    expect($this->manager->getAppId())->toBe('test-app-id');
});

it('returns the raw api instance', function () {
    expect($this->manager->api())->toBe($this->api);
});

it('creates a notification builder', function () {
    expect($this->manager->notification())->toBeInstanceOf(NotificationBuilder::class);
});

it('sends notification and dispatches sent event', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-123');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $result = $this->manager->sendToUser('user_1', 'Hello');

    expect($result)->toBeArray()
        ->and($result['id'])->toBe('notif-123');

    Event::assertDispatched(NotificationSent::class, function ($event) {
        return $event->notificationId === 'notif-123';
    });
});

it('dispatches failed event on notification error', function () {
    Event::fake();

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andThrow(new RuntimeException('API Error', 500));

    expect(fn () => $this->manager->sendToUser('user_1', 'Hello'))
        ->toThrow(RuntimeException::class, 'API Error');

    Event::assertDispatched(NotificationFailed::class, function ($event) {
        return $event->message === 'API Error' && $event->statusCode === 500;
    });
});

it('sends to multiple users', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-456');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $result = $this->manager->sendToUsers(['user_1', 'user_2'], 'Hello all');

    expect($result['id'])->toBe('notif-456');
});

it('sends to segment', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-789');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $result = $this->manager->sendToSegment('Active Users', 'New feature!');

    expect($result['id'])->toBe('notif-789');
});

// ── User Management ──

it('gets a user', function () {
    $user = new OneSignalUser;

    $this->api->shouldReceive('getUser')
        ->with('test-app-id', 'external_id', 'user_123')
        ->once()
        ->andReturn($user);

    $result = $this->manager->getUser('user_123');

    expect($result)->toBeInstanceOf(OneSignalUser::class);
});

it('creates a user with tags', function () {
    $user = new OneSignalUser;

    $this->api->shouldReceive('createUser')
        ->once()
        ->andReturn($user);

    $result = $this->manager->createUser('user_123', ['plan' => 'pro']);

    expect($result)->toBeInstanceOf(OneSignalUser::class);
});

it('creates a user without tags', function () {
    $user = new OneSignalUser;

    $this->api->shouldReceive('createUser')
        ->once()
        ->andReturn($user);

    $result = $this->manager->createUser('user_123');

    expect($result)->toBeInstanceOf(OneSignalUser::class);
});

it('creates a user with native properties', function () {
    $this->api->shouldReceive('createUser')
        ->once()
        ->withArgs(function ($appId, $userObj) {
            $properties = $userObj->getProperties();

            return $properties->getLanguage() === 'pt'
                && $properties->getTimezoneId() === 'America/Sao_Paulo'
                && $properties->getCountry() === 'BR'
                && $properties->getTags() === ['plan' => 'pro'];
        })
        ->andReturn(new OneSignalUser);

    $this->manager->createUser('user_123', ['plan' => 'pro'], [
        'language' => 'pt',
        'timezone_id' => 'America/Sao_Paulo',
        'country' => 'BR',
    ]);
});

it('updates a user with tags and native properties', function () {
    $response = new PropertiesBody;

    $this->api->shouldReceive('updateUser')
        ->once()
        ->withArgs(function ($appId, $type, $id, $request) {
            $properties = $request->getProperties();

            return $request instanceof UpdateUserRequest
                && $properties->getLanguage() === 'en'
                && $properties->getTimezoneId() === 'America/New_York'
                && $properties->getCountry() === 'US'
                && $properties->getTags() === ['plan' => 'enterprise'];
        })
        ->andReturn($response);

    $result = $this->manager->updateUser('user_123', ['plan' => 'enterprise'], [
        'language' => 'en',
        'timezone_id' => 'America/New_York',
        'country' => 'US',
    ]);

    expect($result)->toBeInstanceOf(PropertiesBody::class);
});

it('updates user tags', function () {
    $response = new PropertiesBody;

    $this->api->shouldReceive('updateUser')
        ->with('test-app-id', 'external_id', 'user_123', Mockery::on(function ($request) {
            return $request instanceof UpdateUserRequest
                && $request->getProperties()->getTags() === ['plan' => 'enterprise'];
        }))
        ->once()
        ->andReturn($response);

    $result = $this->manager->updateUserTags('user_123', ['plan' => 'enterprise']);

    expect($result)->toBeInstanceOf(PropertiesBody::class);
});

it('removes user tags by setting empty strings', function () {
    $response = new PropertiesBody;

    $this->api->shouldReceive('updateUser')
        ->once()
        ->withArgs(function ($appId, $type, $id, $request) {
            $tags = $request->getProperties()->getTags();

            return $request instanceof UpdateUserRequest
                && $tags === ['role' => '', 'plan' => ''];
        })
        ->andReturn($response);

    $result = $this->manager->removeUserTags('user_123', ['role', 'plan']);

    expect($result)->toBeInstanceOf(PropertiesBody::class);
});

it('deletes a user', function () {
    $this->api->shouldReceive('deleteUser')
        ->with('test-app-id', 'external_id', 'user_123')
        ->once();

    $this->manager->deleteUser('user_123');
});

// ── Event Tracking ──

it('tracks a single event', function () {
    $this->api->shouldReceive('createCustomEvents')
        ->once()
        ->withArgs(function ($appId, $request) {
            $events = $request->getEvents();

            return $appId === 'test-app-id'
                && count($events) === 1
                && $events[0]->getName() === 'purchase'
                && $events[0]->getExternalId() === 'user_123';
        });

    $this->manager->trackEvent('user_123', 'purchase', ['amount' => 99.90]);
});

it('tracks multiple events', function () {
    $this->api->shouldReceive('createCustomEvents')
        ->once()
        ->withArgs(function ($appId, $request) {
            return count($request->getEvents()) === 2;
        });

    $this->manager->trackEvents([
        ['external_id' => 'user_1', 'name' => 'purchase', 'payload' => ['amount' => 50]],
        ['external_id' => 'user_2', 'name' => 'signup'],
    ]);
});

it('tracks event for multiple users', function () {
    $this->api->shouldReceive('createCustomEvents')
        ->once()
        ->withArgs(function ($appId, $request) {
            $events = $request->getEvents();

            return count($events) === 3
                && $events[0]->getExternalId() === 'user_1'
                && $events[1]->getExternalId() === 'user_2'
                && $events[2]->getExternalId() === 'user_3'
                && $events[0]->getName() === 'promo_viewed';
        });

    $this->manager->trackEventForUsers(
        ['user_1', 'user_2', 'user_3'],
        'promo_viewed',
        ['campaign' => 'summer'],
    );
});

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
