<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockProjection;
use App\Support\Inventory\InventoryProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye `stock_projections` para uno o varios negocios desde el log de
 * operaciones inmutables (sales, purchases, stock_movements,
 * stock_adjustments) más el seed de Product.stock_by_warehouse.
 *
 * El procedimiento:
 *   1. Borra todas las filas de proyección del business.
 *   2. Reseembra desde Product.stock_by_warehouse (estado inicial).
 *   3. Reaplica todas las operaciones en orden cronológico.
 *
 * Útil cuando se sospecha drift, tras cambios de schema, o como parte de un
 * runbook de incidentes. Es seguro correrlo en producción siempre que no
 * haya pushes en vuelo (ideal: ventana de mantenimiento corta o lock por
 * negocio).
 */
class InventoryRebuild extends Command
{
    protected $signature = 'inventory:rebuild
                            {--business= : ID interno del negocio (omitir = todos)}
                            {--dry-run : Calcula sin escribir, sólo informa}';

    protected $description = 'Recomputa stock_projections desde el log de operaciones';

    public function handle(InventoryProjector $projector): int
    {
        $businessId = $this->option('business');
        $dryRun = (bool) $this->option('dry-run');

        $query = Business::query();
        if ($businessId !== null) {
            $query->where('id', $businessId);
        }

        $businesses = $query->get();
        if ($businesses->isEmpty()) {
            $this->warn('No hay negocios que procesar.');

            return self::SUCCESS;
        }

        foreach ($businesses as $business) {
            $this->rebuildForBusiness($business, $projector, $dryRun);
        }

        return self::SUCCESS;
    }

    private function rebuildForBusiness(Business $business, InventoryProjector $projector, bool $dryRun): void
    {
        $this->info("Rebuilding business #{$business->id} ({$business->name})".($dryRun ? ' [dry-run]' : ''));

        DB::transaction(function () use ($business, $projector, $dryRun): void {
            if (! $dryRun) {
                StockProjection::query()->where('business_id', $business->id)->delete();
            }

            $seedRows = 0;
            Product::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->whereNotNull('stock_by_warehouse')
                ->chunk(200, function ($products) use ($business, $projector, &$seedRows, $dryRun): void {
                    foreach ($products as $product) {
                        $entries = is_array($product->stock_by_warehouse) ? $product->stock_by_warehouse : [];
                        if ($entries === []) {
                            continue;
                        }
                        if (! $dryRun) {
                            $projector->setSeedMany($business, $product->external_id, $entries, 'rebuild:seed:'.$product->id);
                        }
                        $seedRows += count($entries);
                    }
                });

            $appliedSales = 0;
            Sale::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->orderBy('transaction_at')
                ->orderBy('id')
                ->chunk(200, function ($sales) use ($business, $projector, &$appliedSales, $dryRun): void {
                    foreach ($sales as $sale) {
                        if ($sale->trashed() || ! $sale->warehouse_external_id) {
                            continue;
                        }
                        $isReducing = in_array($sale->status, ['completed', 'credit', 'pending'], true);
                        if (! $isReducing) {
                            continue;
                        }
                        $consumption = is_array($sale->inventory_consumption) ? $sale->inventory_consumption : null;
                        $lines = $consumption !== null && $consumption !== []
                            ? $consumption
                            : $sale->lines()->get(['product_external_id', 'amount']);

                        if (! $dryRun) {
                            $projector->applyLines($business, $sale->warehouse_external_id, $lines, -1, 'rebuild:sale:'.$sale->external_id);
                        }
                        $appliedSales++;
                    }
                });

            $appliedPurchases = 0;
            Purchase::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->orderBy('transaction_at')
                ->orderBy('id')
                ->chunk(200, function ($purchases) use ($business, $projector, &$appliedPurchases, $dryRun): void {
                    foreach ($purchases as $purchase) {
                        if ($purchase->trashed() || $purchase->status !== 'completed' || ! $purchase->warehouse_external_id) {
                            continue;
                        }
                        $lines = $purchase->lines()->get(['product_external_id', 'amount']);
                        if (! $dryRun) {
                            $projector->applyLines($business, $purchase->warehouse_external_id, $lines, +1, 'rebuild:purchase:'.$purchase->external_id);
                        }
                        $appliedPurchases++;
                    }
                });

            $appliedMovements = 0;
            StockMovement::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->orderBy('movement_at')
                ->orderBy('id')
                ->chunk(200, function ($movements) use ($business, $projector, &$appliedMovements, $dryRun): void {
                    foreach ($movements as $movement) {
                        if ($movement->trashed()) {
                            continue;
                        }
                        if (! $dryRun) {
                            $projector->applyDelta($business, $movement->product_external_id, $movement->from_warehouse_external_id, -(float) $movement->quantity, 'rebuild:movement:'.$movement->external_id);
                            $projector->applyDelta($business, $movement->product_external_id, $movement->to_warehouse_external_id, +(float) $movement->quantity, 'rebuild:movement:'.$movement->external_id);
                        }
                        $appliedMovements++;
                    }
                });

            $appliedAdjustments = 0;
            StockAdjustment::query()
                ->withTrashed()
                ->where('business_id', $business->id)
                ->orderBy('adjustment_at')
                ->orderBy('id')
                ->chunk(200, function ($adjustments) use ($business, $projector, &$appliedAdjustments, $dryRun): void {
                    foreach ($adjustments as $adjustment) {
                        if ($adjustment->trashed()) {
                            continue;
                        }
                        if (! $dryRun) {
                            $projector->applyDelta($business, $adjustment->product_external_id, $adjustment->warehouse_external_id, (float) $adjustment->change_quantity, 'rebuild:adjustment:'.$adjustment->external_id);
                        }
                        $appliedAdjustments++;
                    }
                });

            $this->line(sprintf(
                '  seeded=%d sales=%d purchases=%d movements=%d adjustments=%d',
                $seedRows,
                $appliedSales,
                $appliedPurchases,
                $appliedMovements,
                $appliedAdjustments
            ));
        });
    }
}
