<?php

namespace App\Http\Resources\Api\Marketplace\V1;

use App\Models\MarketplaceQuote;
use App\Models\MarketplaceQuoteLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceQuoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MarketplaceQuote $quote */
        $quote = $this->resource;

        return [
            'id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'status' => $quote->status,
            'currency' => $quote->currency,
            'subtotal' => (float) $quote->subtotal,
            'delivery_total' => (float) $quote->delivery_total,
            'fees_total' => (float) $quote->fees_total,
            'grand_total' => (float) $quote->grand_total,
            'expires_at' => $quote->expires_at?->toIso8601String(),
            'reservation_ids' => $quote->payload_snapshot['reservation_ids'] ?? [],
            'lines' => $quote->lines
                ->map(fn (MarketplaceQuoteLine $line): array => [
                    'publication_id' => $line->marketplace_product_publication_id,
                    'business_id' => $line->business_id,
                    'product_external_id' => $line->product_external_id,
                    'warehouse_external_id' => $line->warehouse_external_id,
                    'title' => $line->title_snapshot,
                    'unit_price' => (float) $line->unit_price,
                    'quantity' => (float) $line->quantity,
                    'subtotal' => (float) $line->subtotal,
                    'currency' => $line->currency,
                ])
                ->values()
                ->all(),
        ];
    }
}
