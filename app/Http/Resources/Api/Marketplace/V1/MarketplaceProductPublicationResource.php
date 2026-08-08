<?php

namespace App\Http\Resources\Api\Marketplace\V1;

use App\Models\MarketplaceProductPublication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceProductPublicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MarketplaceProductPublication $publication */
        $publication = $this->resource;
        $product = $publication->relationLoaded('product') ? $publication->getRelation('product') : null;
        $warehouse = $publication->relationLoaded('warehouse') ? $publication->getRelation('warehouse') : null;
        $business = $publication->business;

        return [
            'id' => $publication->id,
            'business' => $business ? [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ] : null,
            'product' => [
                'external_id' => $publication->product_external_id,
                'code' => $product?->code,
                'title' => $product?->title,
                'type' => $product?->type,
                'category_external_id' => $product?->category_external_id,
                'unit_of_measurement' => $product?->unit_of_measurement,
            ],
            'warehouse' => [
                'external_id' => $publication->warehouse_external_id,
                'name' => $warehouse?->name,
            ],
            'title' => $publication->public_title,
            'description' => $publication->public_description,
            'price' => (float) $publication->public_price,
            'currency' => $publication->currency,
            'images' => $publication->images ?? [],
            'metadata' => $publication->metadata ?? [],
            'availability' => [
                'available' => (float) ($publication->available_quantity ?? 0.0),
                'in_stock' => (float) ($publication->available_quantity ?? 0.0) > 0.0,
            ],
            'published_at' => $publication->updated_at?->toIso8601String(),
        ];
    }
}
