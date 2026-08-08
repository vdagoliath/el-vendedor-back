<?php

namespace App\Modules\Marketplace\DTOs;

class MarketplaceQuoteRequest
{
    /**
     * @param  array<int, array{publication_id:int, quantity:float}>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?int $consumerId = null,
    ) {}
}
