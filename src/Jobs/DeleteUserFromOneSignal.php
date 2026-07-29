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
