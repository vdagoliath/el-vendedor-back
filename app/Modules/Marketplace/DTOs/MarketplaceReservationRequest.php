<?php

namespace App\Modules\Marketplace\DTOs;

use App\Models\MarketplaceQuote;

class MarketplaceReservationRequest
{
    public function __construct(
        public readonly MarketplaceQuote $quote,
    ) {}
}
