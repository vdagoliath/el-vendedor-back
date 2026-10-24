<?php

namespace App\Modules\Marketplace\Operations;

use App\Models\InventoryReservation;
use App\Models\MarketplaceQuote;
use App\Models\MasterOrder;
use App\Models\SellerOrder;

class MarketplaceOperationalMetricsService
{
    /**
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $totalQuotes = MarketplaceQuote::query()->count();
        $convertedQuotes = MarketplaceQuote::query()
            ->where('status', MarketplaceQuote::StatusConverted)
            ->count();

        return [
            'active_reservations' => InventoryReservation::query()
                ->where('status', InventoryReservation::StatusActive)
                ->where('expires_at', '>', now())
                ->count(),
            'past_due_active_reservations' => InventoryReservation::query()
                ->where('status', InventoryReservation::StatusActive)
                ->where('expires_at', '<=', now())
                ->count(),
            'quoted_quotes' => MarketplaceQuote::query()
                ->where('status', MarketplaceQuote::StatusQuoted)
                ->count(),
            'reserved_quotes' => MarketplaceQuote::query()
                ->where('status', MarketplaceQuote::StatusReserved)
                ->count(),
            'converted_quotes' => $convertedQuotes,
            'quote_conversion_rate' => $totalQuotes > 0
                ? round($convertedQuotes / $totalQuotes, 4)
                : 0.0,
            'confirmed_orders' => MasterOrder::query()
                ->where('status', MasterOrder::StatusConfirmed)
                ->count(),
            'in_fulfillment_orders' => MasterOrder::query()
                ->where('status', MasterOrder::StatusInFulfillment)
                ->count(),
            'pending_payment_orders' => MasterOrder::query()
                ->where('payment_status', 'pending')
                ->count(),
            'pending_delivery_orders' => MasterOrder::query()
                ->where('delivery_status', 'pending')
                ->count(),
            'reserved_seller_orders' => SellerOrder::query()
                ->where('status', SellerOrder::StatusReserved)
                ->count(),
            'accepted_seller_orders' => SellerOrder::query()
                ->where('status', SellerOrder::StatusAccepted)
                ->count(),
            'accepted_seller_orders_without_sale' => SellerOrder::query()
                ->where('status', SellerOrder::StatusAccepted)
                ->whereNull('sale_id')
                ->count(),
        ];
    }
}
