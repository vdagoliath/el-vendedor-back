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
        /** Moneda operativa solicitada (null = moneda de las publicaciones). */
        public readonly ?string $currency = null,
    ) {}
}
