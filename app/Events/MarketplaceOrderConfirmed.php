<?php

namespace App\Events;

use App\Models\MasterOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketplaceOrderConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MasterOrder $masterOrder,
    ) {}
}
