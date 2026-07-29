<?php

namespace Multek\OneSignal\Commands;

use Illuminate\Console\Command;
use Multek\OneSignal\Jobs\SyncUserToOneSignal;
use Multek\OneSignal\OneSignalManager;

class BackfillCommand extends Command
{
    protected $signature = 'onesignal:backfill
        {--dry-run : Count what would be synced without dispatching anything}
        {--chunk=250 : Records per chunk}';

    protected $description = 'Dispatch a OneSignal sync job for every record of the configured sync model';

    public function handle(OneSignalManager $manager): int
    {
        if (! $manager->isEnabled()) {
            $this->warn('OneSignal disabled — nothing to do.');

            return self::SUCCESS;
        }

        $model = config('onesignal.sync_model');

        if (! is_string($model) || ! class_exists($model)) {
            $this->error('Invalid onesignal.sync_model: set ONESIGNAL_SYNC_MODEL to an existing model class.');

            return self::FAILURE;
        }

        if (! method_exists($model, 'syncToOneSignal')) {
            $this->error("Invalid onesignal.sync_model: {$model} must use the HasOneSignal trait.");

            return self::FAILURE;
        }

        $total = $model::query()->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would dispatch {$total} sync jobs for {$model}.");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $dispatched = 0;

        $model::query()->chunkById((int) $this->option('chunk'), function ($records) use (&$dispatched, $bar) {
            foreach ($records as $record) {
                dispatch(new SyncUserToOneSignal($record));
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$dispatched} sync jobs.");

        return self::SUCCESS;
    }
}
