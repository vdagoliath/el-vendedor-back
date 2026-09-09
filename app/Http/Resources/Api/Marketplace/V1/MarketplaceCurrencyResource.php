<?php

namespace App\Http\Resources\Api\Marketplace\V1;

use App\Models\MarketplaceCurrency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceCurrency */
class MarketplaceCurrencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'is_default' => $this->is_default,
        ];
    }
}
