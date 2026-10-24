<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Services\MarketplaceRateService;
use Illuminate\Http\JsonResponse;

class MarketplaceRateController extends Controller
{
    public function __construct(private readonly MarketplaceRateService $rates) {}

    /**
     * Tasas de visualizacion USD -> CUP (QvaPay) y USD -> monedas display.
     */
    public function show(): JsonResponse
    {
        return response()->json($this->rates->rates());
    }
}
