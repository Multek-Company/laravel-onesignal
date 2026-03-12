<?php

namespace Multek\OneSignal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncUserToOneSignal implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public $user)
    {
        $this->onQueue(config('onesignal.queue', 'default'));
    }

    public function handle(): void
    {
        $this->user->syncToOneSignal();
    }
}
