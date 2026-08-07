<?php

namespace App\Modules\Inventory\Services;

use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\StockProjection;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable;
use Illuminate\Database\Eloquent\Builder;

class StockProjectionInventoryAvailabilityService implements InventoryAvailabilityService
{
    public function availableFor(
        int $businessId,
        string $productExternalId,
        ?string $warehouseExternalId = null
    ): float {
        $result = $this->availableMany([
            $this->availabilityKey($businessId, $productExternalId, $warehouseExternalId) => [
                'business_id' => $businessId,
                'product_external_id' => $productExternalId,
                'warehouse_external_id' => $warehouseExternalId,
            ],
        ]);

        return array_values($result)[0] ?? 0.0;
    }

    public function availableMany(array $items): array
    {
        $normalized = $this->normalizeItems($items);

        if ($normalized === []) {
            return [];
        }

        $stockRows = StockProjection::query()
            ->select(['business_id', 'product_external_id', 'warehouse_external_id', 'qty'])
            ->where(function (Builder $query) use ($normalized): void {
                foreach ($this->productPairs($normalized) as $pair) {
                    $query->orWhere(function (Builder $query) use ($pair): void {
                        $query
                            ->where('business_id', $pair['business_id'])
                            ->where('product_external_id', $pair['product_external_id']);
                    });
                }
            })
            ->get();

        $reservationRows = InventoryReservationLine::query()
            ->select(['business_id', 'product_external_id', 'warehouse_external_id', 'quantity', 'inventory_reservation_id'])
            ->with('reservation:id,status,expires_at')
            ->where(function (Builder $query) use ($normalized): void {
                foreach ($this->productPairs($normalized) as $pair) {
                    $query->orWhere(function (Builder $query) use ($pair): void {
                        $query
                            ->where('business_id', $pair['business_id'])
                            ->where('product_external_id', $pair['product_external_id']);
                    });
                }
            })
            ->whereHas('reservation', function (Builder $query): void {
                $query
                    ->where('status', InventoryReservation::StatusActive)
                    ->where('expires_at', '>', now());
            })
            ->get();

        $availability = [];

        foreach ($normalized as $key => $item) {
            $matchingStockRows = $stockRows
                ->where('business_id', $item['business_id'])
                ->where('product_external_id', $item['product_external_id']);

            $matchingReservationRows = $reservationRows
                ->where('business_id', $item['business_id'])
                ->where('product_external_id', $item['product_external_id']);

            if ($item['warehouse_external_id'] !== null) {
                $matchingStockRows = $matchingStockRows->where('warehouse_external_id', $item['warehouse_external_id']);
                $matchingReservationRows = $matchingReservationRows->where('warehouse_external_id', $item['warehouse_external_id']);
            }

            $physical = (float) $matchingStockRows->sum(fn (StockProjection $row): float => (float) $row->qty);
            $reserved = (float) $matchingReservationRows->sum(fn (InventoryReservationLine $row): float => (float) $row->quantity);

            $availability[$key] = $physical - $reserved;
        }

        return $availability;
    }

    public function assertAvailable(
        int $businessId,
        string $productExternalId,
        float $quantity,
        ?string $warehouseExternalId = null
    ): void {
        if ($quantity <= 0.0) {
            return;
        }

        $available = $this->availableFor($businessId, $productExternalId, $warehouseExternalId);

        if ($available < $quantity) {
            throw new InsufficientInventoryAvailable(
                $businessId,
                trim($productExternalId),
                $quantity,
                $available,
                $warehouseExternalId !== null ? trim($warehouseExternalId) : null,
            );
        }
    }

    /**
     * @param  array<int|string, array{business_id:int, product_external_id:string, warehouse_external_id?:string|null}>  $items
     * @return array<string, array{business_id:int, product_external_id:string, warehouse_external_id:string|null}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $key => $item) {
            $productExternalId = trim($item['product_external_id'] ?? '');

            if ($productExternalId === '') {
                continue;
            }

            $warehouseExternalId = isset($item['warehouse_external_id']) && is_string($item['warehouse_external_id'])
                ? trim($item['warehouse_external_id'])
                : null;

            $warehouseExternalId = $warehouseExternalId === '' ? null : $warehouseExternalId;
            $businessId = (int) $item['business_id'];
            $resultKey = is_string($key)
                ? $key
                : $this->availabilityKey($businessId, $productExternalId, $warehouseExternalId);

            $normalized[$resultKey] = [
                'business_id' => $businessId,
                'product_external_id' => $productExternalId,
                'warehouse_external_id' => $warehouseExternalId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array{business_id:int, product_external_id:string, warehouse_external_id:string|null}>  $items
     * @return array<int, array{business_id:int, product_external_id:string}>
     */
    private function productPairs(array $items): array
    {
        $pairs = [];

        foreach ($items as $item) {
            $key = $item['business_id'].'|'.$item['product_external_id'];

            $pairs[$key] = [
                'business_id' => $item['business_id'],
                'product_external_id' => $item['product_external_id'],
            ];
        }

        return array_values($pairs);
    }

    private function availabilityKey(
        int $businessId,
        string $productExternalId,
        ?string $warehouseExternalId = null
    ): string {
        return $businessId.'|'.trim($productExternalId).'|'.($warehouseExternalId !== null ? trim($warehouseExternalId) : '*');
    }
}
