<?php

use Multek\CustomerEngagement\DTOs\Customer;
use Multek\CustomerEngagement\DTOs\CustomerEvent;
use Multek\CustomerEngagement\DTOs\Notification;
use Multek\OneSignal\OneSignalDriver;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use onesignal\client\model\PropertiesBody;
use onesignal\client\model\User as OneSignalUser;

beforeEach(function () {
    $this->api = Mockery::mock(DefaultApi::class);
    $this->manager = new OneSignalManager($this->api, 'test-app-id');
    $this->driver = new OneSignalDriver($this->manager);
});

it('returns driver name', function () {
    expect($this->driver->getName())->toBe('onesignal');
});

// ── SyncsUsers contract ──

it('gets a user', function () {
    $user = new OneSignalUser;
    $user->setIdentity(['external_id' => 'user_123']);

    $this->api->shouldReceive('getUser')
        ->with('test-app-id', 'external_id', 'user_123')
        ->once()
        ->andReturn($user);

    $result = $this->driver->getUser('user_123');

    expect($result)->toBeArray();
});

it('creates a user from Customer DTO', function () {
    $customer = new Customer(
        externalId: 'user_123',
        email: 'test@example.com',
        phone: '+5511999999999',
        name: 'John Doe',
        attributes: ['plan' => 'pro'],
    );

    $this->api->shouldReceive('createUser')
        ->once()
        ->withArgs(function ($appId, $userObj) {
            $tags = $userObj->getProperties()->getTags();

            return $appId === 'test-app-id'
                && $tags['plan'] === 'pro'
                && $tags['email'] === 'test@example.com'
                && $tags['phone'] === '+5511999999999'
                && $tags['name'] === 'John Doe';
        })
        ->andReturn(new OneSignalUser);

    $result = $this->driver->createUser($customer);

    expect($result)->toBeArray();
});

it('updates a user from Customer DTO', function () {
    $customer = new Customer(
        externalId: 'user_123',
        attributes: ['plan' => 'enterprise'],
    );

    $this->api->shouldReceive('updateUser')
        ->once()
        ->andReturn(new PropertiesBody);

    $result = $this->driver->updateUser($customer);

    expect($result)->toBeArray();
});

it('deletes a user', function () {
    $this->api->shouldReceive('deleteUser')
        ->with('test-app-id', 'external_id', 'user_123')
        ->once();

    $this->driver->deleteUser('user_123');
});

// ── SendsNotifications contract ──

it('sends notification to a single user', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-1');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $notification = new Notification(
        body: 'Your order shipped',
        heading: 'Order Update',
    );

    $result = $this->driver->sendToUser('user_123', $notification);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe('notif-1');
});

it('sends notification to multiple users', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-2');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $notification = new Notification(body: 'Hello everyone');

    $result = $this->driver->sendToUsers(['user_1', 'user_2'], $notification);

    expect($result['id'])->toBe('notif-2');
});

it('sends notification to a segment', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-3');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->andReturn($response);

    $notification = new Notification(body: 'New feature!');

    $result = $this->driver->sendToSegment('Active Users', $notification);

    expect($result['id'])->toBe('notif-3');
});

it('passes all notification fields to builder', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-full');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->withArgs(function ($sdkNotif) {
            return $sdkNotif->getContents()->getEn() === 'Body text'
                && $sdkNotif->getHeadings()->getEn() === 'Heading'
                && $sdkNotif->getSubtitle()->getEn() === 'Subtitle'
                && $sdkNotif->getUrl() === 'https://example.com'
                && $sdkNotif->getBigPicture() === 'https://img.jpg'
                && $sdkNotif->getData() === ['order_id' => 1]
                && $sdkNotif->getTemplateId() === 'tpl-1'
                && $sdkNotif->getPriority() === 10
                && $sdkNotif->getTtl() === 3600
                && $sdkNotif->getName() === 'test-campaign';
        })
        ->andReturn($response);

    $notification = new Notification(
        body: 'Body text',
        heading: 'Heading',
        subtitle: 'Subtitle',
        url: 'https://example.com',
        imageUrl: 'https://img.jpg',
        data: ['order_id' => 1],
        templateId: 'tpl-1',
        priority: 10,
        ttl: 3600,
        name: 'test-campaign',
        buttons: [['id' => 'ok', 'text' => 'OK']],
    );

    $this->driver->sendToUser('user_1', $notification);
});

// ── TracksEvents contract ──

it('tracks a single event via driver', function () {
    $this->api->shouldReceive('createCustomEvents')
        ->once()
        ->withArgs(function ($appId, $request) {
            $events = $request->getEvents();

            return count($events) === 1
                && $events[0]->getName() === 'purchase'
                && $events[0]->getExternalId() === 'user_123';
        });

    $event = new CustomerEvent(
        externalId: 'user_123',
        name: 'purchase',
        payload: ['amount' => 99.90],
    );

    $this->driver->trackEvent($event);
});

it('tracks multiple events via driver', function () {
    $this->api->shouldReceive('createCustomEvents')
        ->once()
        ->withArgs(function ($appId, $request) {
            return count($request->getEvents()) === 2;
        });

    $events = [
        new CustomerEvent(externalId: 'user_1', name: 'purchase'),
        new CustomerEvent(externalId: 'user_2', name: 'signup'),
    ];

    $this->driver->trackEvents($events);
});
