<?php

namespace App\Console\Commands;

use App\Models\SyncReceivedEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneSyncReceivedEventsCommand extends Command
{
    protected $signature = 'sync:prune-events
                            {--days=180 : Delete applied events older than this many days}
                            {--chunk=1000 : Batch size per delete iteration}
                            {--dry-run : Show what would be deleted without touching the DB}';

    protected $description = 'Archive/delete old sync_received_events. Entities are fully materialized so these events are kept only for audit.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = Carbon::now()->subDays($days);

        $baseQuery = SyncReceivedEvent::query()
            ->where('status', 'applied')
            ->where('created_at', '<', $cutoff);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $this->info("No hay eventos aplicados anteriores a {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s eventos en status=applied anteriores a %s (%d días).',
            number_format($total),
            $cutoff->toDateTimeString(),
            $days
        ));

        if ($dryRun) {
            $this->warn('--dry-run activo: no se eliminará nada.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        do {
            $batch = (clone $baseQuery)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deletedNow = SyncReceivedEvent::query()
                ->whereIn('id', $batch)
                ->delete();

            $deleted += $deletedNow;
            $bar->advance($deletedNow);
        } while ($deletedNow > 0);

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Eliminados %s eventos.', number_format($deleted)));

        return self::SUCCESS;
    }
}
