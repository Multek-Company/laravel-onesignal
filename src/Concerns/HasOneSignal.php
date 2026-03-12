<?php

namespace Multek\OneSignal\Concerns;

use Multek\OneSignal\Facades\OneSignal;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;

/**
 * Add to your User model:
 *
 *   use HasOneSignal;
 *
 *   // Optionally override:
 *   public function getOneSignalExternalId(): string
 *   public function getOneSignalTags(): array
 */
trait HasOneSignal
{
    /**
     * Get the external ID for OneSignal (defaults to primary key).
     */
    public function getOneSignalExternalId(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Route notification for the OneSignal channel.
     */
    public function routeNotificationForOnesignal(): string
    {
        return $this->getOneSignalExternalId();
    }

    /**
     * Get the tags to sync to OneSignal.
     * Override in your User model for custom tags.
     */
    public function getOneSignalTags(): array
    {
        $tags = [];

        foreach (config('onesignal.default_tags', []) as $tagKey => $attribute) {
            if (is_callable($attribute)) {
                $tags[$tagKey] = (string) $attribute($this);
            } elseif (is_string($attribute) && isset($this->{$attribute})) {
                $tags[$tagKey] = (string) $this->{$attribute};
            }
        }

        return $tags;
    }

    /**
     * Sync this user to OneSignal (creates if not exists, updates tags).
     */
    public function syncToOneSignal(): void
    {
        $externalId = $this->getOneSignalExternalId();
        $tags = $this->getOneSignalTags();

        try {
            OneSignal::updateUserTags($externalId, $tags);
        } catch (\Throwable) {
            OneSignal::createUser($externalId, $tags);
        }
    }

    /**
     * Dispatch a queued sync job.
     */
    public function syncToOneSignalAsync(): void
    {
        dispatch(new SyncUserToOneSignal($this));
    }

    /**
     * Send a push notification to this user.
     */
    public function sendPush(string $message, array $data = []): array
    {
        return OneSignal::sendToUser(
            $this->getOneSignalExternalId(),
            $message,
            $data,
        );
    }

    /**
     * Track a custom event for this user.
     *
     * $user->trackOneSignalEvent('purchase', ['amount' => 99.90, 'product' => 'Pro Plan']);
     */
    public function trackOneSignalEvent(string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null): void
    {
        OneSignal::trackEvent(
            $this->getOneSignalExternalId(),
            $eventName,
            $payload,
            $timestamp,
        );
    }

    /**
     * Delete this user from OneSignal.
     */
    public function deleteFromOneSignal(): void
    {
        OneSignal::deleteUser($this->getOneSignalExternalId());
    }
}
