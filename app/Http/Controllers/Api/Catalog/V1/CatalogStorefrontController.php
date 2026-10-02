<?php

namespace App\Http\Controllers\Api\Catalog\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Catalog\V1\CatalogStorefrontResource;
use App\Models\CatalogStorefront;
use App\Modules\Catalog\CatalogService;

/**
 * Catálogo público de un negocio, resuelto por subdominio.
 */
class CatalogStorefrontController extends Controller
{
    public function show(string $subdomain, CatalogService $catalog): CatalogStorefrontResource
    {
        $storefront = $catalog->publicStorefront($subdomain);

        abort_unless($storefront instanceof CatalogStorefront, 404);

        $storefront->categories = $catalog->categories($storefront);

        return CatalogStorefrontResource::make($storefront);
    }
}
