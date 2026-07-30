<?php

namespace Multek\OneSignal\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Multek\OneSignal\Concerns\HasOneSignal;

/**
 * Keeps OneSignal in step with a model's own saves.
 *
 * Attach with #[ObservedBy(OneSignalObserver::class)] on a model using
 * HasOneSignal. Covers the model's own attribute and foreign-key changes;
 * tags derived from a related row's content need an observer on that model,
 * and `onesignal:backfill` remains the reconciliation net. See the README.
 *
 * No enablement check here — syncToOneSignalAsync() and
 * deleteFromOneSignalAsync() already gate on OneSignalManager::isEnabled().
 */
class OneSignalObserver
{
    /**
     * @param  Model&HasOneSignal  $user
     */
    public function saved(Model $user): void
    {
        if ($user->oneSignalPayloadChanged()) {
            $user->syncToOneSignalAsync();
        }
    }

    /**
     * @param  Model&HasOneSignal  $user
     */
    public function deleted(Model $user): void
    {
        // A soft delete is reversible, so the profile stays until it is forced.
        if ($this->softDeletes($user) && ! $user->isForceDeleting()) {
            return;
        }

        $user->deleteFromOneSignalAsync();
    }

    /**
     * @param  Model&HasOneSignal  $user
     */
    public function restored(Model $user): void
    {
        $user->syncToOneSignalAsync();
    }

    protected function softDeletes(Model $user): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($user), true);
    }
}
