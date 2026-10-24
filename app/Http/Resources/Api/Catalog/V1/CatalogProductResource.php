<?php

namespace App\Http\Resources\Api\Catalog\V1;

use App\Models\CatalogProduct;
use App\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin CatalogProduct */
class CatalogProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => (string) ($this->description ?? ''),
            // Precio exacto definido por el dueño: sin conversión de moneda.
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'category_id' => $this->category_name ? Str::slug((string) $this->category_name) : null,
            'category_name' => $this->category_name,
            'images' => CatalogMedia::absoluteMany($this->images),
            'featured' => (bool) $this->is_featured,
            'badge' => $this->badge,
            'available' => (bool) $this->is_available,
            'unit' => $this->unit,
            'sort' => (int) $this->sort,
        ];
    }
}
