<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\CatalogProduct;
use App\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogProduct */
class CatalogProductAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_external_id' => $this->product_external_id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'badge' => $this->badge,
            'category_name' => $this->category_name,
            'category_emoji' => $this->category_emoji,
            'images' => CatalogMedia::absoluteMany($this->images),
            'is_visible' => (bool) $this->is_visible,
            'is_featured' => (bool) $this->is_featured,
            'is_available' => (bool) $this->is_available,
            'sort' => (int) $this->sort,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
