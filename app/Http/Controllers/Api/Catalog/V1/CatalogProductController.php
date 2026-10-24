<?php

namespace App\Http\Controllers\Api\Catalog\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Catalog\V1\CatalogProductIndexRequest;
use App\Http\Resources\Api\Catalog\V1\CatalogProductResource;
use App\Models\CatalogProduct;
use App\Models\CatalogStorefront;
use App\Modules\Catalog\CatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Productos del catálogo público (solo visibles, precio del dueño).
 */
class CatalogProductController extends Controller
{
    public function index(
        string $subdomain,
        CatalogProductIndexRequest $request,
        CatalogService $catalog
    ): AnonymousResourceCollection {
        $storefront = $catalog->publicStorefront($subdomain);

        abort_unless($storefront instanceof CatalogStorefront, 404);

        return CatalogProductResource::collection($catalog->products($storefront, [
            'q' => $request->query('q'),
            'category' => $request->query('category'),
            'only_available' => $request->boolean('only_available'),
            'featured' => $request->boolean('featured'),
            'sort' => $request->sort(),
            'per_page' => $request->perPage(),
        ]));
    }

    public function show(string $subdomain, string $slug, CatalogService $catalog): CatalogProductResource
    {
        $storefront = $catalog->publicStorefront($subdomain);

        abort_unless($storefront instanceof CatalogStorefront, 404);

        $product = $catalog->product($storefront, $slug);

        abort_unless($product instanceof CatalogProduct, 404);

        return CatalogProductResource::make($product);
    }
}
