<?php

namespace Multek\OneSignal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\ApiException;

/**
 * Delete a user profile from OneSignal.
 *
 * Takes the external id rather than the model: by the time the job runs the
 * model row is usually already gone.
 */
class DeleteUserFromOneSignal implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public string $externalId)
    {
        $this->onQueue(config('onesignal.queue', 'default'));

        // `deleted` (and therefore this dispatch) can fire inside an open
        // DB::transaction(). Without this, a worker can erase the OneSignal
        // profile before a rolled-back delete is undone, leaving no profile
        // for a row that still exists. It is a no-op when there is no open
        // transaction. Do not remove this: it is easy to mistake for noise.
        // (Set in the constructor, not as a property default: Queueable
        // already declares $afterCommit untyped/null, and PHP treats a
        // differing class-level default as an incompatible redeclaration.)
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            app(OneSignalManager::class)->deleteUser($this->externalId);
        } catch (ApiException $exception) {
            // The profile is already absent — the erasure is done, retrying
            // would only burn attempts and land a no-op in failed_jobs.
            if ($exception->getCode() === 404) {
                Log::debug('OneSignal user already absent, nothing to delete', [
                    'external_id' => $this->externalId,
                ]);

                return;
            }

            throw $exception;
        }
    }
}
