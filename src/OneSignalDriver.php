<?php

namespace Multek\OneSignal;

use Multek\CustomerEngagement\Contracts\EngagementDriver;
use Multek\CustomerEngagement\Contracts\SendsNotifications;
use Multek\CustomerEngagement\Contracts\SyncsUsers;
use Multek\CustomerEngagement\Contracts\TracksEvents;
use Multek\CustomerEngagement\DTOs\Customer;
use Multek\CustomerEngagement\DTOs\CustomerEvent;
use Multek\CustomerEngagement\DTOs\Notification;

class OneSignalDriver implements EngagementDriver, SyncsUsers, SendsNotifications, TracksEvents
{
    public function __construct(
        protected OneSignalManager $manager,
    ) {}

    public function getName(): string
    {
        return 'onesignal';
    }

    // ── SyncsUsers ─────────────────────────────────────────────────────

    public function getUser(string $externalId): array
    {
        $user = $this->manager->getUser($externalId);

        return json_decode(json_encode($user), true) ?? [];
    }

    public function createUser(Customer $customer): array
    {
        $tags = $customer->attributes;

        if ($customer->email) {
            $tags['email'] = $customer->email;
        }
        if ($customer->phone) {
            $tags['phone'] = $customer->phone;
        }
        if ($customer->name) {
            $tags['name'] = $customer->name;
        }

        $user = $this->manager->createUser($customer->externalId, $tags);

        return json_decode(json_encode($user), true) ?? [];
    }

    public function updateUser(Customer $customer): array
    {
        $tags = $customer->attributes;

        if ($customer->email) {
            $tags['email'] = $customer->email;
        }
        if ($customer->phone) {
            $tags['phone'] = $customer->phone;
        }
        if ($customer->name) {
            $tags['name'] = $customer->name;
        }

        $user = $this->manager->updateUserTags($customer->externalId, $tags);

        return json_decode(json_encode($user), true) ?? [];
    }

    public function deleteUser(string $externalId): void
    {
        $this->manager->deleteUser($externalId);
    }

    // ── SendsNotifications ─────────────────────────────────────────────

    public function sendToUser(string $externalId, Notification $notification): array
    {
        $builder = $this->buildNotification($notification)->toUser($externalId);

        return $builder->send();
    }

    public function sendToUsers(array $externalIds, Notification $notification): array
    {
        $builder = $this->buildNotification($notification)->toUsers($externalIds);

        return $builder->send();
    }

    public function sendToSegment(string $segment, Notification $notification): array
    {
        $builder = $this->buildNotification($notification)->toSegment($segment);

        return $builder->send();
    }

    // ── TracksEvents ───────────────────────────────────────────────────

    public function trackEvent(CustomerEvent $event): void
    {
        $this->manager->trackEvent(
            externalId: $event->externalId,
            eventName: $event->name,
            payload: $event->payload,
            timestamp: $event->timestamp,
        );
    }

    public function trackEvents(array $events): void
    {
        $mapped = array_map(fn (CustomerEvent $event) => [
            'external_id' => $event->externalId,
            'name' => $event->name,
            'payload' => $event->payload,
            'timestamp' => $event->timestamp,
        ], $events);

        $this->manager->trackEvents($mapped);
    }

    // ── Internal ───────────────────────────────────────────────────────

    protected function buildNotification(Notification $notification): Builders\NotificationBuilder
    {
        $builder = $this->manager->notification()
            ->body($notification->body);

        if ($notification->heading) {
            $builder->heading($notification->heading);
        }
        if ($notification->subtitle) {
            $builder->subtitle($notification->subtitle);
        }
        if ($notification->url) {
            $builder->url($notification->url);
        }
        if ($notification->imageUrl) {
            $builder->image($notification->imageUrl);
        }
        if (! empty($notification->data)) {
            $builder->data($notification->data);
        }
        foreach ($notification->buttons as $button) {
            $builder->addButton($button['id'], $button['text']);
        }
        if ($notification->templateId) {
            $builder->template($notification->templateId);
        }
        if ($notification->priority !== null) {
            $builder->priority($notification->priority);
        }
        if ($notification->ttl !== null) {
            $builder->ttl($notification->ttl);
        }
        if ($notification->sendAfter !== null) {
            $builder->sendAfter($notification->sendAfter);
        }
        if ($notification->name) {
            $builder->name($notification->name);
        }

        return $builder;
    }
}
