<?php

namespace Multek\OneSignal\Builders;

use Multek\OneSignal\OneSignalManager;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification;

class NotificationBuilder
{
    protected Notification $notification;

    public function __construct(protected OneSignalManager $manager)
    {
        $this->notification = new Notification();
        $this->notification->setAppId($manager->getAppId());
    }

    // ── Targeting ──

    public function toUser(string $externalId): static
    {
        return $this->toUsers([$externalId]);
    }

    public function toUsers(array $externalIds): static
    {
        $this->notification->setIncludeAliases(['external_id' => $externalIds]);
        $this->notification->setTargetChannel('push');

        return $this;
    }

    public function toSegment(string $segment): static
    {
        return $this->toSegments([$segment]);
    }

    public function toSegments(array $segments): static
    {
        $this->notification->setIncludedSegments($segments);

        return $this;
    }

    public function excludeSegments(array $segments): static
    {
        $this->notification->setExcludedSegments($segments);

        return $this;
    }

    public function withFilters(array $filters): static
    {
        $this->notification->setFilters($filters);

        return $this;
    }

    // ── Content ──

    public function body(string $message, string $locale = 'en'): static
    {
        $contents = $this->notification->getContents() ?? new LanguageStringMap();

        if ($locale === 'en') {
            $contents->setEn($message);
        } else {
            $contents[$locale] = $message;
        }

        $this->notification->setContents($contents);

        return $this;
    }

    public function heading(string $title, string $locale = 'en'): static
    {
        $headings = $this->notification->getHeadings() ?? new LanguageStringMap();

        if ($locale === 'en') {
            $headings->setEn($title);
        } else {
            $headings[$locale] = $title;
        }

        $this->notification->setHeadings($headings);

        return $this;
    }

    public function subtitle(string $subtitle, string $locale = 'en'): static
    {
        $sub = $this->notification->getSubtitle() ?? new LanguageStringMap();

        if ($locale === 'en') {
            $sub->setEn($subtitle);
        } else {
            $sub[$locale] = $subtitle;
        }

        $this->notification->setSubtitle($sub);

        return $this;
    }

    public function image(string $url): static
    {
        $this->notification->setBigPicture($url);
        $this->notification->setIosAttachments(['image' => $url]);

        return $this;
    }

    public function url(string $url): static
    {
        $this->notification->setUrl($url);

        return $this;
    }

    // ── Custom Data ──

    public function data(array $data): static
    {
        $existing = $this->notification->getData() ?? [];
        $this->notification->setData(array_merge($existing, $data));

        return $this;
    }

    // ── Scheduling ──

    public function sendAfter(\DateTimeInterface|string $datetime): static
    {
        if (is_string($datetime)) {
            $datetime = new \DateTime($datetime);
        }

        $this->notification->setSendAfter($datetime);

        return $this;
    }

    public function throttle(int $perMinute): static
    {
        $this->notification->setThrottleRatePerMinute($perMinute);

        return $this;
    }

    // ── Buttons ──

    public function addButton(string $id, string $text): static
    {
        $buttons = $this->notification->getButtons() ?? [];
        $buttons[] = ['id' => $id, 'text' => $text];
        $this->notification->setButtons($buttons);

        return $this;
    }

    // ── Priority & TTL ──

    public function priority(int $priority): static
    {
        $this->notification->setPriority($priority);

        return $this;
    }

    public function ttl(int $seconds): static
    {
        $this->notification->setTtl($seconds);

        return $this;
    }

    // ── Template ──

    public function template(string $templateId): static
    {
        $this->notification->setTemplateId($templateId);

        return $this;
    }

    // ── Name (internal tracking) ──

    public function name(string $name): static
    {
        $this->notification->setName($name);

        return $this;
    }

    // ── Escape hatch ──

    /**
     * Access the raw SDK Notification object for anything
     * the builder doesn't cover.
     */
    public function raw(): Notification
    {
        return $this->notification;
    }

    // ── Send ──

    public function send(): array
    {
        return $this->manager->sendNotification($this->notification);
    }
}
