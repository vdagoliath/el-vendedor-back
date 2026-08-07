<?php

namespace App\Events;

use App\Models\MarketplaceQuote;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketplaceQuoteReserved
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, int>  $reservationIds
     */
    public function __construct(
        public readonly MarketplaceQuote $quote,
        public readonly array $reservationIds,
    ) {}
}
