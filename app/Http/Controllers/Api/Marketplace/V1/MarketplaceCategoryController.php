<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Marketplace\V1\MarketplaceCategoryResource;
use App\Models\MarketplaceCategory;

class MarketplaceCategoryController extends Controller
{
    /**
     * Categorias de navegacion activas (imagenes gestionadas por el backend).
     */
    public function index()
    {
        return MarketplaceCategoryResource::collection(
            MarketplaceCategory::query()->active()->ordered()->get(),
        );
    }
}
