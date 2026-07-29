<?php

namespace Multek\OneSignal\Facades;

use Illuminate\Support\Facades\Facade;
use Multek\OneSignal\OneSignalManager;

/**
 * @method static \onesignal\client\api\DefaultApi api()
 * @method static \Multek\OneSignal\Builders\NotificationBuilder notification()
 * @method static array sendToUser(string $externalId, string $message, array $data = [])
 * @method static array sendToUsers(array $externalIds, string $message, array $data = [])
 * @method static array sendToSegment(string $segment, string $message, array $data = [])
 * @method static \onesignal\client\model\User getUser(string $externalId)
 * @method static \onesignal\client\model\User createUser(string $externalId, array $tags = [])
 * @method static \onesignal\client\model\User updateUserTags(string $externalId, array $tags)
 * @method static \onesignal\client\model\User removeUserTags(string $externalId, array $tagKeys)
 * @method static void deleteUser(string $externalId)
 * @method static void trackEvent(string $externalId, string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null)
 * @method static void trackEvents(array $events)
 * @method static void trackEventForUsers(array $externalIds, string $eventName, array $payload = [], ?\DateTimeInterface $timestamp = null)
 *
 * @see OneSignalManager
 */
class OneSignal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OneSignalManager::class;
    }
}
