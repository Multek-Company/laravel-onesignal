<?php

namespace Multek\OneSignal\Events;

class NotificationSent
{
    public function __construct(
        public string $notificationId,
        public array $response,
    ) {}
}
