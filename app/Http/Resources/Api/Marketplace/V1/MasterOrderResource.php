<?php

namespace App\Http\Resources\Api\Marketplace\V1;

use App\Models\MarketplaceOrderStatusHistory;
use App\Models\MasterOrder;
use App\Models\SellerOrder;
use App\Models\SellerOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MasterOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MasterOrder $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'delivery_status' => $order->delivery_status,
            'consumer_id' => $order->consumer_id,
            'recipient' => $order->recipient_snapshot,
            'delivery_address' => $order->delivery_address_snapshot,
            'payment' => $order->payment_snapshot,
            'delivery' => $order->delivery_snapshot,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'delivery_total' => (float) $order->delivery_total,
            'fees_total' => (float) $order->fees_total,
            'grand_total' => (float) $order->grand_total,
            'status_history' => $order->statusHistory
                ->sortByDesc('id')
                ->map(fn (MarketplaceOrderStatusHistory $history): array => $this->historyToArray($history))
                ->values()
                ->all(),
            'seller_orders' => $order->sellerOrders
                ->map(fn (SellerOrder $sellerOrder): array => [
                    'id' => $sellerOrder->id,
                    'business_id' => $sellerOrder->business_id,
                    'seller_order_number' => $sellerOrder->seller_order_number,
                    'status' => $sellerOrder->status,
                    'reservation_id' => $sellerOrder->reservation_id,
                    'sale_id' => $sellerOrder->sale_id,
                    'currency' => $sellerOrder->currency,
                    'subtotal' => (float) $sellerOrder->subtotal,
                    'status_history' => $sellerOrder->statusHistory
                        ->sortByDesc('id')
                        ->map(fn (MarketplaceOrderStatusHistory $history): array => $this->historyToArray($history))
                        ->values()
                        ->all(),
                    'lines' => $sellerOrder->lines
                        ->map(fn (SellerOrderLine $line): array => [
                            'product_external_id' => $line->product_external_id,
                            'warehouse_external_id' => $line->warehouse_external_id,
                            'title' => $line->title_snapshot,
                            'unit_price' => (float) $line->unit_price,
                            'quantity' => (float) $line->quantity,
                            'subtotal' => (float) $line->subtotal,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
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
