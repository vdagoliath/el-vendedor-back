<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Marketplace\V1\MasterOrderResource;
use App\Models\MasterOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'consumer_id' => ['nullable', 'integer'],
            'order_numbers' => ['nullable', 'array', 'max:50'],
            'order_numbers.*' => ['string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $hasConsumerFilter = array_key_exists('consumer_id', $validated);
        $hasOrderNumberFilter = ! empty($validated['order_numbers']);

        $orders = MasterOrder::query()
            ->with(['statusHistory', 'sellerOrders.lines', 'sellerOrders.statusHistory'])
            ->when(! $hasConsumerFilter && ! $hasOrderNumberFilter, function ($query): void {
                $query->whereRaw('1 = 0');
            })
            ->when($hasConsumerFilter, function ($query) use ($validated): void {
                $query->where('consumer_id', $validated['consumer_id']);
            })
            ->when($validated['order_numbers'] ?? null, function ($query, array $orderNumbers): void {
                $query->whereIn('order_number', $orderNumbers);
            })
            ->latest('id')
            ->paginate(min((int) ($validated['per_page'] ?? 20), 50));

        return MasterOrderResource::collection($orders);
    }

    public function show(string $orderNumber): MasterOrderResource
    {
        $order = MasterOrder::query()
            ->with(['statusHistory', 'sellerOrders.lines', 'sellerOrders.statusHistory'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return MasterOrderResource::make($order);
    }
}
