<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Marketplace\V1\MasterOrderResource;
use App\Models\SellerOrder;
use App\Modules\Sales\Actions\CreateSaleFromMarketplaceSellerOrderAction;

class MarketplaceSellerOrderController extends Controller
{
    public function accept(
        SellerOrder $sellerOrder,
        CreateSaleFromMarketplaceSellerOrderAction $createSale
    ): MasterOrderResource {
        $createSale->handle($sellerOrder);

        $masterOrder = $sellerOrder
            ->masterOrder()
            ->with(['sellerOrders.lines'])
            ->firstOrFail();

        return MasterOrderResource::make($masterOrder);
    }
}
