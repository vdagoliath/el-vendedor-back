<?php

namespace App\Events;

use App\Models\Sale;
use App\Models\SellerOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketplaceSellerOrderAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SellerOrder $sellerOrder,
        public readonly Sale $sale,
    ) {}
}
