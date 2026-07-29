<?php

use Illuminate\Notifications\Notification;
use Multek\OneSignal\Channels\OneSignalChannel;
use Multek\OneSignal\Messages\OneSignalMessage;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;

beforeEach(function () {
    $this->api = Mockery::mock(DefaultApi::class);
    $this->manager = new OneSignalManager($this->api, 'test-app-id');
    $this->channel = new OneSignalChannel($this->manager);
});

it('sends notification via channel with OneSignalMessage', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-ch-1');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->withArgs(function ($sdkNotif) {
            return $sdkNotif->getContents()->getEn() === 'Order shipped'
                && $sdkNotif->getHeadings()->getEn() === 'Order Update'
                && $sdkNotif->getIncludeAliases() === ['external_id' => ['42']];
        })
        ->andReturn($response);

    $notifiable = new class
    {
        public function getKey()
        {
            return 42;
        }

        public function routeNotificationFor($channel, $notification = null)
        {
            return null;
        }
    };

    $notification = new class extends Notification
    {
        public function toOneSignal($notifiable): OneSignalMessage
        {
            return OneSignalMessage::create('Order shipped')
                ->heading('Order Update');
        }
    };

    $this->channel->send($notifiable, $notification);
});

it('sends notification via channel with string', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-ch-2');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->withArgs(function ($sdkNotif) {
            return $sdkNotif->getContents()->getEn() === 'Simple message';
        })
        ->andReturn($response);

    $notifiable = new class
    {
        public function getKey()
        {
            return 1;
        }

        public function routeNotificationFor($channel, $notification = null)
        {
            return null;
        }
    };

    $notification = new class extends Notification
    {
        public function toOneSignal($notifiable): string
        {
            return 'Simple message';
        }
    };

    $this->channel->send($notifiable, $notification);
});

it('uses custom routing for external id', function () {
    Event::fake();

    $response = new CreateNotificationSuccessResponse;
    $response->setId('notif-ch-3');

    $this->api->shouldReceive('createNotification')
        ->once()
        ->withArgs(function ($sdkNotif) {
            return $sdkNotif->getIncludeAliases() === ['external_id' => ['custom-ext-id']];
        })
        ->andReturn($response);

    $notifiable = new class
    {
        public function getKey()
        {
            return 99;
        }

        public function routeNotificationFor($channel, $notification = null)
        {
            return 'custom-ext-id';
        }
    };

    $notification = new class extends Notification
    {
        public function toOneSignal($notifiable): OneSignalMessage
        {
            return OneSignalMessage::create('Test');
        }
    };

    $this->channel->send($notifiable, $notification);
});
