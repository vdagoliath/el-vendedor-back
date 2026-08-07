<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Contracts\InventoryReservationService;
use Illuminate\Console\Command;

class ExpireInventoryReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations {--limit= : Max reservations to expire in this run}';

    protected $description = 'Expire active inventory reservations whose expiration time has passed';

    public function handle(InventoryReservationService $reservations): int
    {
        $limit = $this->option('limit');
        $expired = $reservations->expirePastDue(is_numeric($limit) ? (int) $limit : null);

        $this->info("Expired {$expired} inventory reservations.");

        return self::SUCCESS;
    }
}
