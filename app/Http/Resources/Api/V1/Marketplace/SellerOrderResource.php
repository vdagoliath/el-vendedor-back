<?php

namespace App\Http\Resources\Api\V1\Marketplace;

use App\Models\MarketplaceOrderStatusHistory;
use App\Models\MasterOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SellerOrder
 */
class SellerOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $masterOrder = $this->whenLoaded('masterOrder');

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'master_order_id' => $this->master_order_id,
            'seller_order_number' => $this->seller_order_number,
            'status' => $this->status,
            'sale_id' => $this->sale_id,
            'reservation_id' => $this->reservation_id,
            'subtotal' => (float) $this->subtotal,
            'currency' => $this->currency,
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory
                ->sortByDesc('id')
                ->map(fn (MarketplaceOrderStatusHistory $history): array => $this->historyToArray($history))
                ->values()
                ->all()),
            'master_order' => $masterOrder instanceof MasterOrder ? [
                'id' => $masterOrder->id,
                'order_number' => $masterOrder->order_number,
                'status' => $masterOrder->status,
                'payment_status' => $masterOrder->payment_status,
                'delivery_status' => $masterOrder->delivery_status,
                'recipient' => $masterOrder->recipient_snapshot,
                'delivery_address' => $masterOrder->delivery_address_snapshot,
                'grand_total' => (float) $masterOrder->grand_total,
                'currency' => $masterOrder->currency,
                'created_at' => $masterOrder->created_at?->toIso8601String(),
                'status_history' => $masterOrder->relationLoaded('statusHistory') ? $masterOrder->statusHistory
                    ->sortByDesc('id')
                    ->map(fn (MarketplaceOrderStatusHistory $history): array => $this->historyToArray($history))
                    ->values()
                    ->all() : [],
            ] : null,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn ($line): array => [
                    'id' => $line->id,
                    'product_external_id' => $line->product_external_id,
                    'warehouse_external_id' => $line->warehouse_external_id,
                    'title' => $line->title_snapshot,
                    'unit_price' => (float) $line->unit_price,
                    'quantity' => (float) $line->quantity,
                    'subtotal' => (float) $line->subtotal,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyToArray(MarketplaceOrderStatusHistory $history): array
    {
        return [
            'id' => $history->id,
            'master_order_id' => $history->master_order_id,
            'seller_order_id' => $history->seller_order_id,
            'from_status' => $history->from_status,
            'to_status' => $history->to_status,
            'actor_type' => $history->actor_type,
            'notes' => $history->notes,
            'created_at' => $history->created_at?->toIso8601String(),
        ];
    }

}
