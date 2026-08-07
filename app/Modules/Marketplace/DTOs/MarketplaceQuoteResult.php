<?php

namespace App\Modules\Marketplace\DTOs;

use App\Models\MarketplaceQuote;

class MarketplaceQuoteResult
{
    public function __construct(
        public readonly MarketplaceQuote $quote,
    ) {}
}
