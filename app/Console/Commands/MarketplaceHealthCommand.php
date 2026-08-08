<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Operations\MarketplaceOperationalMetricsService;
use Illuminate\Console\Command;

class MarketplaceHealthCommand extends Command
{
    protected $signature = 'marketplace:health {--json : Output metrics as JSON}';

    protected $description = 'Report marketplace operational metrics';

    public function handle(MarketplaceOperationalMetricsService $metrics): int
    {
        $snapshot = $metrics->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($snapshot)
                ->map(fn (int|float $value, string $metric): array => [$metric, $value])
                ->values()
                ->all(),
        );

        if (($snapshot['past_due_active_reservations'] ?? 0) > 0) {
            $this->warn('Past-due active reservations need expiration processing.');
        }

        return self::SUCCESS;
    }
}
