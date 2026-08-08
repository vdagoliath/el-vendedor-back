<?php

namespace App\Modules\Marketplace\DTOs;

use App\Models\MarketplaceQuote;

class MarketplaceReservationResult
{
    /**
     * @param  array<int, int>  $reservationIds
     */
    public function __construct(
        public readonly MarketplaceQuote $quote,
        public readonly array $reservationIds,
    ) {}
}
