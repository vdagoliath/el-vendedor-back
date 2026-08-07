<?php

namespace App\Modules\Sales\Actions;

use App\Events\MarketplaceSellerOrderAccepted;
use App\Models\InventoryReservation;
use App\Models\MarketplaceOrderStatusHistory;
use App\Models\MasterOrder;
use App\Models\Sale;
use App\Models\SellerOrder;
use App\Models\SellerOrderLine;
use App\Models\StockProjection;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Support\Inventory\InventoryProjector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSaleFromMarketplaceSellerOrderAction
{
    public function __construct(
        private readonly InventoryProjector $inventoryProjector,
        private readonly InventoryReservationService $reservationService,
    ) {}

    public function handle(SellerOrder $sellerOrder): Sale
    {
        $wasAccepted = false;

        $sale = DB::transaction(function () use ($sellerOrder, &$wasAccepted): Sale {
            /** @var SellerOrder $lockedSellerOrder */
            $lockedSellerOrder = SellerOrder::query()
                ->with(['business', 'lines', 'masterOrder', 'reservation', 'sale'])
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSellerOrder->sale) {
                return $lockedSellerOrder->sale;
            }

            if ($lockedSellerOrder->status !== SellerOrder::StatusReserved) {
                throw ValidationException::withMessages([
                    'seller_order' => 'Only reserved seller orders can be accepted.',
                ]);
            }

            $this->assertReservationCanBeConverted($lockedSellerOrder);

            $warehouseExternalId = $this->singleWarehouseExternalId($lockedSellerOrder->lines);
            $this->assertPhysicalStockCoversLines($lockedSellerOrder, $warehouseExternalId);

            $sale = $this->createSale($lockedSellerOrder, $warehouseExternalId);
            $this->createSaleLines($sale, $lockedSellerOrder->lines);

            $sale->load('lines');
            $eventId = "marketplace:seller-orders:{$lockedSellerOrder->seller_order_number}:accepted";

            $this->inventoryProjector->applyLines(
                $lockedSellerOrder->business,
                $warehouseExternalId,
                $sale->lines,
                -1,
                $eventId,
            );

            $this->reservationService->confirm($lockedSellerOrder->reservation);

            $previousSellerStatus = $lockedSellerOrder->status;
            $lockedSellerOrder->forceFill([
                'status' => SellerOrder::StatusAccepted,
                'sale_id' => $sale->id,
            ])->save();

            $this->recordSellerStatusHistory($lockedSellerOrder, $previousSellerStatus);
            $this->moveMasterOrderIntoFulfillment($lockedSellerOrder->masterOrder);
            $wasAccepted = true;

            return $sale->refresh()->load('lines');
        });

        if ($wasAccepted) {
            MarketplaceSellerOrderAccepted::dispatch($sellerOrder->refresh(), $sale);
        }

        return $sale;
    }

    private function assertReservationCanBeConverted(SellerOrder $sellerOrder): void
    {
        $reservation = $sellerOrder->reservation;

        if (! $reservation instanceof InventoryReservation) {
            throw ValidationException::withMessages([
                'reservation' => 'The seller order does not have an inventory reservation.',
            ]);
        }

        if ($reservation->status !== InventoryReservation::StatusActive || $reservation->expires_at <= now()) {
            throw ValidationException::withMessages([
                'reservation' => 'The seller order inventory reservation is no longer active.',
            ]);
        }
    }

    /**
     * @param  Collection<int, SellerOrderLine>  $lines
     */
    private function singleWarehouseExternalId(Collection $lines): string
    {
        $warehouses = $lines
            ->pluck('warehouse_external_id')
            ->filter()
            ->unique()
            ->values();

        if ($warehouses->count() !== 1) {
            throw ValidationException::withMessages([
                'seller_order' => 'Seller orders can only be accepted when all lines belong to one warehouse.',
            ]);
        }

        return (string) $warehouses->first();
    }

    private function assertPhysicalStockCoversLines(SellerOrder $sellerOrder, string $warehouseExternalId): void
    {
        $requiredByProduct = $sellerOrder->lines
            ->groupBy('product_external_id')
            ->map(fn (Collection $lines): float => (float) $lines->sum(fn (SellerOrderLine $line): float => (float) $line->quantity));

        $availableByProduct = StockProjection::query()
            ->where('business_id', $sellerOrder->business_id)
            ->where('warehouse_external_id', $warehouseExternalId)
            ->whereIn('product_external_id', $requiredByProduct->keys()->all())
            ->lockForUpdate()
            ->get(['product_external_id', 'qty'])
            ->keyBy('product_external_id');

        foreach ($requiredByProduct as $productExternalId => $requiredQuantity) {
            $physicalQuantity = (float) ($availableByProduct->get($productExternalId)?->qty ?? 0);

            if ($physicalQuantity < $requiredQuantity) {
                throw ValidationException::withMessages([
                    'inventory' => "Insufficient physical stock for product [{$productExternalId}] in warehouse [{$warehouseExternalId}].",
                ]);
            }
        }
    }

    private function createSale(SellerOrder $sellerOrder, string $warehouseExternalId): Sale
    {
        $eventId = "marketplace:seller-orders:{$sellerOrder->seller_order_number}:accepted";

        return Sale::query()->create([
            'business_id' => $sellerOrder->business_id,
            'external_id' => "marketplace:{$sellerOrder->seller_order_number}",
            'reference' => $sellerOrder->seller_order_number,
            'warehouse_external_id' => $warehouseExternalId,
            'total' => $sellerOrder->subtotal,
            'status' => 'pending',
            'currency' => $sellerOrder->currency,
            'payment_method' => 'marketplace',
            'payment_breakdown' => [],
            'created_by' => [
                'source' => 'marketplace',
                'masterOrderNumber' => $sellerOrder->masterOrder?->order_number,
                'sellerOrderNumber' => $sellerOrder->seller_order_number,
            ],
            'inventory_consumption' => $sellerOrder->lines
                ->map(fn (SellerOrderLine $line): array => [
                    'productId' => $line->product_external_id,
                    'warehouseId' => $line->warehouse_external_id,
                    'amount' => (float) $line->quantity,
                ])
                ->values()
                ->all(),
            'transaction_at' => now(),
            'source_created_at' => now(),
            'source_updated_at' => now(),
            'last_received_event_id' => $eventId,
        ]);
    }

    /**
     * @param  Collection<int, SellerOrderLine>  $sellerOrderLines
     */
    private function createSaleLines(Sale $sale, Collection $sellerOrderLines): void
    {
        $sellerOrderLines
            ->values()
            ->each(function (SellerOrderLine $line, int $index) use ($sale): void {
                $sale->lines()->create([
                    'product_external_id' => $line->product_external_id,
                    'product_title' => $line->title_snapshot,
                    'price' => $line->unit_price,
                    'amount' => $line->quantity,
                    'sub_total' => $line->subtotal,
                    'sort_order' => $index,
                ]);
            });
    }

    private function recordSellerStatusHistory(SellerOrder $sellerOrder, string $previousStatus): void
    {
        MarketplaceOrderStatusHistory::query()->create([
            'master_order_id' => $sellerOrder->master_order_id,
            'seller_order_id' => $sellerOrder->id,
            'from_status' => $previousStatus,
            'to_status' => SellerOrder::StatusAccepted,
            'actor_type' => 'marketplace',
            'actor_id' => null,
            'notes' => 'Seller order accepted and converted into an operative sale.',
        ]);
    }

    private function moveMasterOrderIntoFulfillment(?MasterOrder $masterOrder): void
    {
        if (! $masterOrder instanceof MasterOrder || $masterOrder->status === MasterOrder::StatusInFulfillment) {
            return;
        }

        $previousStatus = $masterOrder->status;

        $masterOrder->forceFill([
            'status' => MasterOrder::StatusInFulfillment,
        ])->save();

        MarketplaceOrderStatusHistory::query()->create([
            'master_order_id' => $masterOrder->id,
            'seller_order_id' => null,
            'from_status' => $previousStatus,
            'to_status' => MasterOrder::StatusInFulfillment,
            'actor_type' => 'marketplace',
            'actor_id' => null,
            'notes' => 'At least one seller order was accepted for fulfillment.',
        ]);
    }
}
