<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Marketplace\SellerOrderResource;
use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\MarketplaceOrderStatusHistory;
use App\Models\MasterOrder;
use App\Models\SellerOrder;
use App\Modules\Sales\Actions\CreateSaleFromMarketplaceSellerOrderAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SellerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                SellerOrder::StatusReserved,
                SellerOrder::StatusAccepted,
                SellerOrder::StatusPreparing,
                SellerOrder::StatusReady,
                SellerOrder::StatusDispatched,
                SellerOrder::StatusDelivered,
                SellerOrder::StatusCancelled,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = SellerOrder::query()
            ->with(['lines', 'statusHistory', 'masterOrder.statusHistory'])
            ->where('business_id', $business->id)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return SellerOrderResource::collection($orders);
    }

    public function accept(
        Request $request,
        SellerOrder $sellerOrder,
        CreateSaleFromMarketplaceSellerOrderAction $createSale
    ): SellerOrderResource {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        abort_unless((int) $sellerOrder->business_id === (int) $business->id, 404);

        $createSale->handle($sellerOrder);

        return SellerOrderResource::make(
            $sellerOrder->refresh()->load(['lines', 'statusHistory', 'masterOrder.statusHistory'])
        );
    }

    public function reject(Request $request, SellerOrder $sellerOrder): SellerOrderResource
    {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        abort_unless((int) $sellerOrder->business_id === (int) $business->id, 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $sellerOrder, $validated): void {
            $sellerOrder->refresh()->load(['masterOrder.sellerOrders', 'reservation']);

            if ($sellerOrder->status === SellerOrder::StatusCancelled && $sellerOrder->sale_id === null) {
                return;
            }

            if ($sellerOrder->status !== SellerOrder::StatusReserved || $sellerOrder->sale_id !== null) {
                abort(422, 'Solo puedes rechazar una venta Global antes de aceptarla.');
            }

            $previousStatus = $sellerOrder->status;
            $sellerOrder->forceFill(['status' => SellerOrder::StatusCancelled])->save();

            $this->cancelReservationForRejectedSellerOrder($sellerOrder->reservation);

            MarketplaceOrderStatusHistory::query()->create([
                'master_order_id' => $sellerOrder->master_order_id,
                'seller_order_id' => $sellerOrder->id,
                'from_status' => $previousStatus,
                'to_status' => SellerOrder::StatusCancelled,
                'actor_type' => 'sync',
                'actor_id' => $request->user()?->getKey(),
                'notes' => $validated['reason'] ?? 'Seller order rejected from ElVendedor before acceptance.',
            ]);

            $this->updateMasterOrderAfterRejection($sellerOrder->masterOrder);
        });

        return SellerOrderResource::make(
            $sellerOrder->refresh()->load(['lines', 'statusHistory', 'masterOrder.statusHistory'])
        );
    }

    public function updateStatus(Request $request, SellerOrder $sellerOrder): SellerOrderResource
    {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        abort_unless((int) $sellerOrder->business_id === (int) $business->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                SellerOrder::StatusPreparing,
                SellerOrder::StatusReady,
                SellerOrder::StatusDispatched,
                SellerOrder::StatusDelivered,
            ])],
        ]);

        $nextStatus = $validated['status'];
        $currentStatus = $sellerOrder->status;

        if ($currentStatus === $nextStatus) {
            return SellerOrderResource::make($sellerOrder->load(['lines', 'statusHistory', 'masterOrder.statusHistory']));
        }

        if (! $this->canTransition($currentStatus, $nextStatus)) {
            abort(422, 'Esta venta Global no puede pasar a ese estado.');
        }

        if ($sellerOrder->sale_id === null) {
            abort(422, 'Debes aceptar la venta antes de actualizar su preparación.');
        }

        $sellerOrder->forceFill(['status' => $nextStatus])->save();

        MarketplaceOrderStatusHistory::query()->create([
            'master_order_id' => $sellerOrder->master_order_id,
            'seller_order_id' => $sellerOrder->id,
            'from_status' => $currentStatus,
            'to_status' => $nextStatus,
            'actor_type' => 'sync',
            'actor_id' => $request->user()?->getKey(),
            'notes' => 'Seller order status updated from ElVendedor.',
        ]);

        $this->updateMasterOrderFulfillment($sellerOrder->masterOrder, $nextStatus);

        return SellerOrderResource::make(
            $sellerOrder->refresh()->load(['lines', 'statusHistory', 'masterOrder.statusHistory'])
        );
    }


    private function cancelReservationForRejectedSellerOrder(?InventoryReservation $reservation): void
    {
        if (! $reservation instanceof InventoryReservation || $reservation->status !== InventoryReservation::StatusActive) {
            return;
        }

        $reservation->forceFill([
            'status' => InventoryReservation::StatusCancelled,
            'cancelled_at' => now(),
        ])->save();
    }

    private function updateMasterOrderAfterRejection(?MasterOrder $masterOrder): void
    {
        if (! $masterOrder instanceof MasterOrder) {
            return;
        }

        $masterOrder->unsetRelation('sellerOrders');
        $masterOrder->load('sellerOrders');

        $allCancelled = $masterOrder->sellerOrders
            ->every(fn (SellerOrder $order): bool => $order->status === SellerOrder::StatusCancelled);
        $hasCancelled = $masterOrder->sellerOrders
            ->contains(fn (SellerOrder $order): bool => $order->status === SellerOrder::StatusCancelled);

        $nextStatus = $allCancelled
            ? MasterOrder::StatusCancelled
            : ($hasCancelled ? MasterOrder::StatusPartiallyConfirmed : $masterOrder->status);

        if ($nextStatus === $masterOrder->status) {
            return;
        }

        $previousStatus = $masterOrder->status;
        $updates = ['status' => $nextStatus];
        if ($allCancelled) {
            $updates['delivery_status'] = 'failed';
        }

        $masterOrder->forceFill($updates)->save();

        MarketplaceOrderStatusHistory::query()->create([
            'master_order_id' => $masterOrder->id,
            'seller_order_id' => null,
            'from_status' => $previousStatus,
            'to_status' => $nextStatus,
            'actor_type' => 'sync',
            'actor_id' => null,
            'notes' => 'Master order status updated after seller rejection.',
        ]);
    }

    private function canTransition(string $currentStatus, string $nextStatus): bool
    {
        $transitions = [
            SellerOrder::StatusAccepted => [SellerOrder::StatusPreparing, SellerOrder::StatusReady],
            SellerOrder::StatusPreparing => [SellerOrder::StatusReady],
            SellerOrder::StatusReady => [SellerOrder::StatusDispatched, SellerOrder::StatusDelivered],
            SellerOrder::StatusDispatched => [SellerOrder::StatusDelivered],
        ];

        return in_array($nextStatus, $transitions[$currentStatus] ?? [], true);
    }

    private function updateMasterOrderFulfillment(?MasterOrder $masterOrder, string $sellerStatus): void
    {
        if (! $masterOrder instanceof MasterOrder) {
            return;
        }

        $masterOrder->loadMissing('sellerOrders');

        $updates = [];
        if (in_array($sellerStatus, [SellerOrder::StatusPreparing, SellerOrder::StatusReady, SellerOrder::StatusDispatched], true)
            && ! in_array($masterOrder->status, [MasterOrder::StatusCompleted, MasterOrder::StatusCancelled, MasterOrder::StatusRefunded], true)) {
            $updates['status'] = MasterOrder::StatusInFulfillment;
        }

        if ($sellerStatus === SellerOrder::StatusDispatched) {
            $updates['delivery_status'] = 'in_transit';
        }

        if ($sellerStatus === SellerOrder::StatusDelivered) {
            $updates['delivery_status'] = 'delivered';

            $allDelivered = $masterOrder->sellerOrders
                ->every(fn (SellerOrder $order): bool => $order->status === SellerOrder::StatusDelivered);

            if ($allDelivered) {
                $updates['status'] = MasterOrder::StatusCompleted;
            }
        }

        if ($updates !== []) {
            $previousStatus = $masterOrder->status;
            $masterOrder->forceFill($updates)->save();

            if (($updates['status'] ?? $previousStatus) !== $previousStatus) {
                MarketplaceOrderStatusHistory::query()->create([
                    'master_order_id' => $masterOrder->id,
                    'seller_order_id' => null,
                    'from_status' => $previousStatus,
                    'to_status' => $updates['status'],
                    'actor_type' => 'sync',
                    'actor_id' => null,
                    'notes' => 'Master order fulfillment status updated from seller order progress.',
                ]);
            }
        }
    }
}
