<?php

namespace App\Support\Inventory;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene `stock_projections` aplicando deltas atómicos por (product, warehouse).
 *
 * Diseño:
 *   - El log de operaciones (sales, purchases, stock_movements, stock_adjustments)
 *     es la fuente de verdad. La proyección es una vista materializada
 *     reconstruible por completo desde ese log con `inventory:rebuild`.
 *   - Cada `applyDelta` corre en una transacción y bloquea la fila
 *     `(business_id, product_external_id, warehouse_external_id)` con
 *     `lockForUpdate()`, así varios push concurrentes contra el mismo
 *     producto se serializan correctamente.
 *   - `setSeed` se usa cuando un producto se crea/recibe con `_stockSeed: true`;
 *     reemplaza el valor existente en lugar de aplicar delta.
 */
class InventoryProjector
{
    public function applyDelta(
        Business $business,
        string $productExternalId,
        string $warehouseExternalId,
        float $delta,
        ?string $eventId = null
    ): void {
        if ($delta == 0.0) {
            return;
        }

        DB::transaction(function () use ($business, $productExternalId, $warehouseExternalId, $delta, $eventId): void {
            $row = $this->lockRow($business->id, $productExternalId, $warehouseExternalId);

            if (! $row) {
                StockProjection::query()->create([
                    'business_id' => $business->id,
                    'product_external_id' => $productExternalId,
                    'warehouse_external_id' => $warehouseExternalId,
                    'qty' => $delta,
                    'last_applied_event_id' => $eventId,
                ]);
            } else {
                $row->qty = (float) $row->qty + $delta;
                $row->last_applied_event_id = $eventId;
                $row->save();
            }

            $this->syncLegacyProductStock($business, $productExternalId);
        });
    }

    /**
     * Reemplaza el valor de stock para un (product, warehouse). Sólo se
     * usa cuando recibimos un seed explícito; el resto de los pushes
     * mueven la proyección por delta.
     */
    public function setSeed(
        Business $business,
        string $productExternalId,
        string $warehouseExternalId,
        float $qty,
        ?string $eventId = null
    ): void {
        DB::transaction(function () use ($business, $productExternalId, $warehouseExternalId, $qty, $eventId): void {
            $row = $this->lockRow($business->id, $productExternalId, $warehouseExternalId);

            if (! $row) {
                StockProjection::query()->create([
                    'business_id' => $business->id,
                    'product_external_id' => $productExternalId,
                    'warehouse_external_id' => $warehouseExternalId,
                    'qty' => $qty,
                    'last_applied_event_id' => $eventId,
                ]);
            } else {
                $row->qty = $qty;
                $row->last_applied_event_id = $eventId;
                $row->save();
            }

            $this->syncLegacyProductStock($business, $productExternalId);
        });
    }

    /**
     * Para un producto, reemplaza la proyección de TODOS los almacenes que
     * vengan en `$entries`. Almacenes existentes que no aparezcan se
     * dejan tal cual (un seed parcial nunca borra datos no mencionados).
     *
     * @param  array<int, array{warehouseId?: string, quantity?: float|int|string}>  $entries
     */
    public function setSeedMany(
        Business $business,
        string $productExternalId,
        array $entries,
        ?string $eventId = null
    ): void {
        foreach ($entries as $entry) {
            $warehouseId = $entry['warehouseId'] ?? null;
            if (! is_string($warehouseId) || trim($warehouseId) === '') {
                continue;
            }
            $qty = isset($entry['quantity']) && is_numeric($entry['quantity'])
                ? (float) $entry['quantity']
                : 0;

            $this->setSeed($business, $productExternalId, trim($warehouseId), $qty, $eventId);
        }
    }

    /**
     * Aplica un conjunto de líneas a la proyección con un signo dado:
     *   - $sign = -1 para ventas (descontar)
     *   - $sign = +1 para compras / restauraciones (sumar)
     *
     * @param  iterable<int, object|array<string, mixed>>  $lines
     */
    public function applyLines(
        Business $business,
        string $warehouseExternalId,
        iterable $lines,
        int $sign,
        ?string $eventId = null
    ): void {
        foreach ($lines as $line) {
            $productId = $this->extract($line, 'product_external_id', 'productId');
            $amount = $this->extract($line, 'amount');

            if (! is_string($productId) || trim($productId) === '') {
                continue;
            }
            if (! is_numeric($amount)) {
                continue;
            }

            $delta = $sign * (float) $amount;
            if ($delta == 0.0) {
                continue;
            }

            $this->applyDelta($business, trim($productId), $warehouseExternalId, $delta, $eventId);
        }
    }

    public function findFor(int $businessId, string $productExternalId, string $warehouseExternalId): ?StockProjection
    {
        return StockProjection::query()
            ->where('business_id', $businessId)
            ->where('product_external_id', $productExternalId)
            ->where('warehouse_external_id', $warehouseExternalId)
            ->first();
    }

    private function lockRow(int $businessId, string $productExternalId, string $warehouseExternalId): ?StockProjection
    {
        return StockProjection::query()
            ->where('business_id', $businessId)
            ->where('product_external_id', $productExternalId)
            ->where('warehouse_external_id', $warehouseExternalId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Espejea la proyección de un producto en `Product.stock_by_warehouse`
     * para mantener compatibilidad con consumidores que aún leen del
     * snapshot legacy (Backoffice dashboards, exports, etc.). Cuando los
     * readers migren a la proyección, este método puede eliminarse.
     */
    private function syncLegacyProductStock(Business $business, string $productExternalId): void
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $productExternalId)
            ->first();

        if (! $product) {
            return;
        }

        $rows = StockProjection::query()
            ->where('business_id', $business->id)
            ->where('product_external_id', $productExternalId)
            ->get(['warehouse_external_id', 'qty']);

        $product->stock_by_warehouse = $rows
            ->map(fn (StockProjection $row): array => [
                'warehouseId' => $row->warehouse_external_id,
                'quantity' => (float) $row->qty,
            ])
            ->all();

        $product->save();
    }

    /**
     * Lectura tolerante a líneas representadas como array o como modelo Eloquent.
     */
    private function extract(mixed $line, string ...$keys): mixed
    {
        if (is_array($line)) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $line)) {
                    return $line[$key];
                }
            }

            return null;
        }

        if (is_object($line)) {
            foreach ($keys as $key) {
                if (isset($line->{$key})) {
                    return $line->{$key};
                }
            }
        }

        return null;
    }
}
