<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Marketplace\V1\MarketplaceCurrencyResource;
use App\Models\MarketplaceCurrency;

class MarketplaceCurrencyController extends Controller
{
    /**
     * Listado de monedas de visualizacion gestionadas por el backend.
     */
    public function index()
    {
        return MarketplaceCurrencyResource::collection(
            MarketplaceCurrency::query()->ordered()->get(),
        )->additional([
            'meta' => [
                'default' => MarketplaceCurrency::query()->defaults()->value('code') ?? 'USD',
            ],
        ]);
    }
}
