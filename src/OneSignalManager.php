<?php

namespace Multek\OneSignal;

use Multek\OneSignal\Builders\NotificationBuilder;
use Multek\OneSignal\Events\NotificationFailed;
use Multek\OneSignal\Events\NotificationSent;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CustomEvent;
use onesignal\client\model\CustomEventsRequest;
use onesignal\client\model\Notification;
use onesignal\client\model\PropertiesBody;
use onesignal\client\model\PropertiesObject;
use onesignal\client\model\UpdateUserRequest;
use onesignal\client\model\User as OneSignalUser;

class OneSignalManager
{
    public function __construct(
        protected DefaultApi $api,
        protected string $appId,
    ) {}

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
     * Used internally by NotificationBuilder::send() and OneSignalChannel.
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
    // Custom Events
    // ──────────────────────────────────

    /**
     * Track a single custom event for a user.
     *
     * OneSignal::trackEvent('user_123', 'purchase', ['amount' => 99.90, 'product' => 'Pro Plan']);
     */
    public function trackEvent(string $externalId, string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null): void
    {
        $this->trackEvents([$this->buildEvent($externalId, $eventName, $payload, $timestamp)]);
    }

    /**
     * Track multiple custom events in a single API call.
     *
     * OneSignal::trackEvents([
     *     ['external_id' => 'user_1', 'name' => 'purchase', 'payload' => ['amount' => 50]],
     *     ['external_id' => 'user_2', 'name' => 'signup'],
     * ]);
     *
     * Also accepts pre-built CustomEvent objects.
     */
    public function trackEvents(array $events): void
    {
        $sdkEvents = [];

        foreach ($events as $event) {
            if ($event instanceof CustomEvent) {
                $sdkEvents[] = $event;
            } else {
                $sdkEvents[] = $this->buildEvent(
                    externalId: $event['external_id'],
                    eventName: $event['name'],
                    payload: $event['payload'] ?? [],
                    timestamp: $event['timestamp'] ?? null,
                );
            }
        }

        $request = new CustomEventsRequest;
        $request->setEvents($sdkEvents);

        $this->api->createCustomEvents($this->appId, $request);
    }

    /**
     * Track a custom event for multiple users at once (same event).
     *
     * OneSignal::trackEventForUsers(['user_1', 'user_2'], 'promo_viewed', ['campaign' => 'summer']);
     */
    public function trackEventForUsers(array $externalIds, string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null): void
    {
        $events = array_map(
            fn (string $id) => $this->buildEvent($id, $eventName, $payload, $timestamp),
            $externalIds,
        );

        $this->trackEvents($events);
    }

    protected function buildEvent(string $externalId, string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null): CustomEvent
    {
        $event = new CustomEvent;
        $event->setName($eventName);
        $event->setExternalId($externalId);

        if (! empty($payload)) {
            $event->setPayload($payload);
        }

        if ($timestamp) {
            $event->setTimestamp($timestamp);
        }

        return $event;
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
        $user = new OneSignalUser;
        $user->setIdentity(['external_id' => $externalId]);

        if (! empty($tags)) {
            $properties = new PropertiesObject;
            $properties->setTags($tags);
            $user->setProperties($properties);
        }

        return $this->api->createUser($this->appId, $user);
    }

    /**
     * Update tags on a user.
     */
    public function updateUserTags(string $externalId, array $tags): PropertiesBody
    {
        $request = new UpdateUserRequest;
        $properties = new PropertiesObject;
        $properties->setTags($tags);
        $request->setProperties($properties);

        return $this->api->updateUser($this->appId, 'external_id', $externalId, $request);
    }

    /**
     * Remove tags from a user (sets them to empty string).
     */
    public function removeUserTags(string $externalId, array $tagKeys): PropertiesBody
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
