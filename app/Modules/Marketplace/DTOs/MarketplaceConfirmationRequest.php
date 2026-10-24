<?php

namespace App\Modules\Marketplace\DTOs;

use App\Models\MarketplaceQuote;

class MarketplaceConfirmationRequest
{
    /**
     * @param  array<string, mixed>|null  $recipientSnapshot
     * @param  array<string, mixed>|null  $deliveryAddressSnapshot
     * @param  array<string, mixed>|null  $paymentSnapshot
     * @param  array<string, mixed>|null  $deliverySnapshot
     */
    public function __construct(
        public readonly MarketplaceQuote $quote,
        public readonly ?array $recipientSnapshot = null,
        public readonly ?array $deliveryAddressSnapshot = null,
        public readonly string $paymentStatus = 'pending',
        public readonly string $deliveryStatus = 'pending',
        public readonly ?array $paymentSnapshot = null,
        public readonly ?array $deliverySnapshot = null,
    ) {}
}
