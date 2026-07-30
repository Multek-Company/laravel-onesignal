<?php

namespace Multek\OneSignal\Concerns;

use Illuminate\Support\Facades\Log;
use Multek\OneSignal\Facades\OneSignal;
use Multek\OneSignal\Jobs\DeleteUserFromOneSignal;
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
            if ($attribute instanceof \Closure) {
                $tags[$tagKey] = (string) $attribute($this);
            } elseif (is_string($attribute) && isset($this->{$attribute})) {
                $tags[$tagKey] = (string) $this->{$attribute};
            }
        }

        return $tags;
    }

    /**
     * The exact profile OneSignal receives on sync: identity, segmentation
     * tags, native properties and delivery subscriptions.
     *
     * Tags and properties are key-sorted so two payloads can be compared
     * regardless of the order an override built them in.
     */
    public function toOneSignalPayload(): array
    {
        $tags = $this->getOneSignalTags();
        $properties = array_filter([
            'language' => $this->getOneSignalLanguage(),
            'timezone_id' => $this->getOneSignalTimezone(),
            'country' => $this->getOneSignalCountry(),
        ], fn ($value) => $value !== null);

        ksort($tags);
        ksort($properties);

        return [
            'external_id' => $this->getOneSignalExternalId(),
            'tags' => $tags,
            'properties' => $properties,
            'email' => $this->getOneSignalEmail(),
            'phone' => $this->normalizedOneSignalPhone(),
        ];
    }

    /**
     * Would a sync right now send something different from what the model
     * looked like before this save?
     *
     * Derived, never declared — no watched-attribute list to fall out of date
     * when a new tag or getter is added.
     */
    public function oneSignalPayloadChanged(): bool
    {
        if ($this->getRawOriginal() === []) {
            // Nothing to compare against (Eloquent fires `saved` before
            // syncOriginal(), so a create() sees an empty original). A fresh
            // record always needs a sync, and evaluating a user-supplied tag
            // closure — possibly walking a relation, per the two-sided-clone
            // support above — against an attribute-less model isn't safe to
            // ask of them.
            return true;
        }

        $current = $this->oneSignalPayloadFrom($this->getAttributes());
        // getRawOriginal(), not getOriginal(): getOriginal() with no key runs
        // every value through transformModelValue(), applying casts and
        // Attribute accessors a second time. For array/json/encrypted casts
        // or non-idempotent custom accessors that corrupts the "previous"
        // side (TypeError, DecryptException, or a silently wrong diff).
        // getRawOriginal() returns $this->original untouched — the same raw
        // shape getAttributes() gives the current side above.
        $previous = $this->oneSignalPayloadFrom($this->getRawOriginal());

        if ($current === $previous) {
            return false;
        }

        Log::debug('OneSignal payload changed', [
            'external_id' => $current['external_id'],
            'changed' => array_keys(array_diff_assoc(
                array_map('serialize', $current),
                array_map('serialize', $previous),
            )),
        ]);

        return true;
    }

    /**
     * Build a payload from an arbitrary attribute set.
     *
     * Both sides of the diff go through here so each one re-resolves relations
     * against its own foreign keys — a tag closure reading $user->role->name
     * would otherwise see the live model's already-loaded relation on both
     * sides and miss the change.
     */
    protected function oneSignalPayloadFrom(array $attributes): array
    {
        return (clone $this)
            ->setRawAttributes($attributes, sync: true)
            ->setRelations([])
            ->toOneSignalPayload();
    }

    /**
     * Sync the full profile in a single upsert call:
     * tags + native properties + Email/SMS subscriptions.
     */
    public function syncToOneSignal(): void
    {
        $payload = $this->toOneSignalPayload();
        $phone = $this->getOneSignalPhone();

        if ($phone !== null && $payload['phone'] === null) {
            Log::warning("OneSignal: phone '{$phone}' is not E.164, omitting SMS subscription", [
                'external_id' => $payload['external_id'],
            ]);
        }

        app(OneSignalManager::class)->createUser(
            $payload['external_id'],
            $payload['tags'],
            $payload['properties'],
            $payload['email'],
            $payload['phone'],
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
     * Dispatch a queued delete job (gated by enablement).
     *
     * Safe to call from a `deleted` model hook: the external id is captured
     * eagerly, so the job no longer needs the model row.
     */
    public function deleteFromOneSignalAsync(): void
    {
        if (! app(OneSignalManager::class)->isEnabled()) {
            Log::debug('OneSignal disabled, skipping delete dispatch');

            return;
        }

        dispatch(new DeleteUserFromOneSignal($this->getOneSignalExternalId()));
    }

    /**
     * The phone if it is E.164, otherwise null. Silent by design — the
     * warning belongs to the send path, so building a payload twice for a
     * diff does not double-log.
     */
    protected function normalizedOneSignalPhone(): ?string
    {
        $phone = $this->getOneSignalPhone();

        if ($phone === null) {
            return null;
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $phone) ? $phone : null;
    }
}
