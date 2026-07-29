<?php

namespace Multek\OneSignal\Concerns;

use Illuminate\Support\Facades\Log;
use Multek\OneSignal\Facades\OneSignal;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;

/**
 * Add to your User model:
 *
 *   use HasOneSignal;
 *
 *   // Optionally override:
 *   public function getOneSignalExternalId(): string
 *   public function getOneSignalEmail(): ?string
 *   public function getOneSignalPhone(): ?string
 *   public function getOneSignalLanguage(): ?string
 *   public function getOneSignalTimezone(): ?string
 *   public function getOneSignalCountry(): ?string
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
     * Get the email for OneSignal (defaults to 'email' attribute).
     */
    public function getOneSignalEmail(): ?string
    {
        return data_get($this, 'email');
    }

    /**
     * Get the phone for OneSignal (must be E.164 format: +5511999999999).
     * Non-E.164 phones are omitted with a warning during sync.
     */
    public function getOneSignalPhone(): ?string
    {
        return data_get($this, 'phone');
    }

    /**
     * Get the language for OneSignal (ISO 639-1, e.g. 'pt', 'en').
     */
    public function getOneSignalLanguage(): ?string
    {
        return null;
    }

    /**
     * Get the timezone for OneSignal (IANA format, e.g. 'America/Sao_Paulo').
     */
    public function getOneSignalTimezone(): ?string
    {
        return null;
    }

    /**
     * Get the country for OneSignal (ISO 3166-1 alpha-2, e.g. 'BR', 'US').
     */
    public function getOneSignalCountry(): ?string
    {
        return null;
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
     *
     * Note: Tags are custom segmentation only (limits: Free 2 / Growth 10 / Professional 100).
     * Identity fields (email, phone, language, timezone, country) are never auto-populated as tags.
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
     * Sync the full profile in a single upsert call:
     * tags + native properties + Email/SMS subscriptions.
     */
    public function syncToOneSignal(): void
    {
        app(OneSignalManager::class)->createUser(
            $this->getOneSignalExternalId(),
            $this->getOneSignalTags(),
            array_filter([
                'language' => $this->getOneSignalLanguage(),
                'timezone_id' => $this->getOneSignalTimezone(),
                'country' => $this->getOneSignalCountry(),
            ], fn ($value) => $value !== null),
            $this->getOneSignalEmail(),
            $this->validatedOneSignalPhone(),
        );
    }

    /**
     * Dispatch a queued sync job (gated by enablement).
     */
    public function syncToOneSignalAsync(): void
    {
        if (! app(OneSignalManager::class)->isEnabled()) {
            Log::debug('OneSignal disabled, skipping sync dispatch');

            return;
        }

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

    /**
     * Validate and return E.164 phone, or null with a warning if invalid.
     */
    protected function validatedOneSignalPhone(): ?string
    {
        $phone = $this->getOneSignalPhone();

        if ($phone === null) {
            return null;
        }

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            Log::warning("OneSignal: phone '{$phone}' is not E.164, omitting SMS subscription", [
                'external_id' => $this->getOneSignalExternalId(),
            ]);

            return null;
        }

        return $phone;
    }
}
