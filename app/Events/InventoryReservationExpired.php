<?php

namespace App\Events;

use App\Models\InventoryReservation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryReservationExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly InventoryReservation $reservation,
    ) {}
}
