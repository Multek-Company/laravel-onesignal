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

        // `saved`/`deleted` (and therefore this dispatch) can fire inside an
        // open DB::transaction(). Without this, a worker can pick the job up
        // and re-query the model before the transaction commits, syncing
        // stale — or, on rollback, nonexistent — data. It is a no-op when
        // there is no open transaction. Do not remove this: it is easy to
        // mistake for noise. (Set in the constructor, not as a property
        // default: Queueable already declares $afterCommit untyped/null, and
        // PHP treats a differing class-level default as an incompatible
        // redeclaration.)
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $this->user->syncToOneSignal();
    }
}
