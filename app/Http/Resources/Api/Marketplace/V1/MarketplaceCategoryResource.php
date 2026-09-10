<?php

namespace App\Http\Resources\Api\Marketplace\V1;

use App\Models\MarketplaceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceCategory */
class MarketplaceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'color' => $this->color,
            'sort' => $this->sort,
            'is_active' => $this->is_active,
        ];
    }
}
