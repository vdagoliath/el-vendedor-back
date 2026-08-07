<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Marketplace\V1\MasterOrderResource;
use App\Models\MasterOrder;

class MarketplaceOrderController extends Controller
{
    public function show(string $orderNumber): MasterOrderResource
    {
        $order = MasterOrder::query()
            ->with(['sellerOrders.lines'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return MasterOrderResource::make($order);
    }
}
