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

    /**
     * The row is gone, so there is nothing left to sync — a completed outcome,
     * not a failure. Without this, a create-then-delete race puts a
     * ModelNotFoundException in failed_jobs.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public $user)
    {
        $this->onQueue(config('onesignal.queue', 'default'));
    }

    public function handle(): void
    {
        $this->user->syncToOneSignal();
    }
}
