<?php

namespace Multek\OneSignal\Events;

class NotificationFailed
{
    public function __construct(
        public string $message,
        public int $statusCode,
    ) {}
}
