<?php

namespace Multek\OneSignal\Channels;

use Illuminate\Notifications\Notification;
use Multek\OneSignal\Messages\OneSignalMessage;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as SdkNotification;

class OneSignalChannel
{
    public function __construct(protected OneSignalManager $manager) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toOneSignal($notifiable);

        if (is_string($message)) {
            $message = OneSignalMessage::create($message);
        }

        $externalId = $notifiable->routeNotificationFor('onesignal', $notification);

        if (! $externalId) {
            $externalId = $notifiable->getKey();
        }

        $sdkNotification = $this->buildSdkNotification($message, (string) $externalId);

        $this->manager->sendNotification($sdkNotification);
    }

    protected function buildSdkNotification(OneSignalMessage $message, string $externalId): SdkNotification
    {
        $notification = new SdkNotification;
        $notification->setAppId($this->manager->getAppId());

        // Target by external_id
        $notification->setIncludeAliases(['external_id' => [$externalId]]);
        $notification->setTargetChannel('push');

        // Body (required)
        $contents = new LanguageStringMap;
        $contents->setEn($message->getBody());
        $notification->setContents($contents);

        // Heading
        if ($message->getHeading()) {
            $headings = new LanguageStringMap;
            $headings->setEn($message->getHeading());
            $notification->setHeadings($headings);
        }

        // Subtitle
        if ($message->getSubtitle()) {
            $subtitle = new LanguageStringMap;
            $subtitle->setEn($message->getSubtitle());
            $notification->setSubtitle($subtitle);
        }

        // URL
        if ($message->getUrl()) {
            $notification->setUrl($message->getUrl());
        }

        // Image
        if ($message->getImage()) {
            $notification->setBigPicture($message->getImage());
            $notification->setIosAttachments(['image' => $message->getImage()]);
        }

        // Custom data
        if (! empty($message->getData())) {
            $notification->setData($message->getData());
        }

        // Buttons
        if (! empty($message->getButtons())) {
            $notification->setButtons($message->getButtons());
        }

        // Template
        if ($message->getTemplateId()) {
            $notification->setTemplateId($message->getTemplateId());
        }

        // Priority
        if ($message->getPriority() !== null) {
            $notification->setPriority($message->getPriority());
        }

        // TTL
        if ($message->getTtl() !== null) {
            $notification->setTtl($message->getTtl());
        }

        // Scheduled send
        if ($message->getSendAfter()) {
            $notification->setSendAfter($message->getSendAfter());
        }

        // Name (internal tracking)
        if ($message->getName()) {
            $notification->setName($message->getName());
        }

        return $notification;
    }
}
