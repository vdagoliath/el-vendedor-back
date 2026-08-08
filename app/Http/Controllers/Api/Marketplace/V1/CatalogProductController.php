<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Marketplace\V1\CatalogProductIndexRequest;
use App\Http\Resources\Api\Marketplace\V1\MarketplaceProductPublicationResource;
use App\Models\MarketplaceProductPublication;
use App\Modules\Marketplace\Catalog\MarketplaceCatalogService;

class CatalogProductController extends Controller
{
    public function index(
        CatalogProductIndexRequest $request,
        MarketplaceCatalogService $catalog
    ): mixed {
        return MarketplaceProductPublicationResource::collection($catalog->products([
            'q' => $request->query('q'),
            'business_id' => $request->filled('business_id') ? $request->integer('business_id') : null,
            'in_stock' => $request->boolean('in_stock'),
            'per_page' => $request->perPage(),
            'page' => $request->integer('page', 1),
            'path' => $request->url(),
            'query' => $request->query(),
        ]));
    }

    public function show(
        MarketplaceProductPublication $publication,
        MarketplaceCatalogService $catalog
    ): MarketplaceProductPublicationResource {
        $publication = $catalog->publishedProduct($publication);

        abort_unless($publication instanceof MarketplaceProductPublication, 404);

        return MarketplaceProductPublicationResource::make($publication);
    }
}
