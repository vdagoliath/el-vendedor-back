<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\Product;
use App\Models\SyncReceivedEvent;
use App\Support\Inventory\InventoryProjector;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Replays individual SyncReceivedEvent rows that are stuck in `failed` status.
 *
 * The original push path intentionally marks an event as `failed` when the
 * materialization throws (e.g. a product arrives before its category, or a
 * transient DB deadlock interrupts a batch). Those rows become invisible to
 * pulls even though the payload is still on disk. This service re-tries them
 * using the same appliers the push controller uses, without re-running any
 * role/permission assertion (those were already evaluated when the event was
 * first accepted).
 */
final class SyncEventReprocessor
{
    public function __construct(
        private readonly SyncEntityApplier $entityApplier,
        private readonly SyncTransactionApplier $transactionApplier,
        private readonly InventoryProjector $inventoryProjector,
    ) {}

    /**
     * Replay a single event. Returns the new status ('applied' or 'failed') and
     * persists it on the event row along with processed_at / error_message.
     */
    public function reprocess(Business $business, SyncReceivedEvent $event): string
    {
        $change = $this->buildChangeFromEvent($event);

        try {
            $applied = $this->applyChange($business, $event, $change);

            $event->forceFill([
                'status' => $applied ? 'applied' : 'pending_dispatch',
                'processed_at' => now(),
                'error_message' => $applied ? null : 'El tipo de entidad no tiene un applier registrado.',
            ])->save();

            return $event->status;
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            return 'failed';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangeFromEvent(SyncReceivedEvent $event): array
    {
        return [
            'event_id' => $event->event_id,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id,
            'operation' => $event->operation,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'payload' => is_array($event->payload) ? $event->payload : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function applyChange(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        // Contacts se materializan via SyncEntityApplier — en el push usamos
        // `assertContactPermission` pero aquí ya fue validado cuando se recibió
        // originalmente. Si el tipo es supplier y el evento fue aceptado en su
        // momento, reprocesar está bien.
        if ($this->entityApplier->apply($business, $event, $change)) {
            return true;
        }

        if ($this->transactionApplier->apply($business, $event, $change)) {
            return true;
        }

        if ($change['entity_type'] === 'products') {
            $this->applyProductChange($business, $event, $change);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function applyProductChange(Business $business, SyncReceivedEvent $event, array $change): void
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var Product|null $product */
        $product = Product::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $change['entity_id'])
            ->first();

        if ($operation === 'delete') {
            if (! $product) {
                return;
            }

            $product->forceFill([
                'last_received_event_id' => $event->event_id,
                'source_updated_at' => $occurredAt ?? now(),
            ])->save();

            if (! $product->trashed()) {
                $product->delete();
            }

            return;
        }

        $code = trim((string) ($payload['code'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));

        if ($code === '') {
            throw new \RuntimeException('El producto sincronizado no tiene codigo.');
        }

        if ($title === '') {
            throw new \RuntimeException('El producto sincronizado no tiene nombre.');
        }

        if (! $product) {
            $product = new Product([
                'business_id' => $business->id,
                'external_id' => $change['entity_id'],
            ]);
        }

        // Mismo criterio que en SyncPushController::applyProductChange:
        // sólo aceptamos `stockByWarehouse` cuando el push lo marca como seed
        // inicial. En reprocesos, eso permite no tocar el stock acumulado por
        // eventos discretos (sales, purchases, stock_movements, stock_adjustments, product_breakdowns).
        $stockByWarehouse = $product->stock_by_warehouse ?? [];
        $isStockSeed = ($payload['_stockSeed'] ?? false) === true;
        if ($isStockSeed && is_array($payload['stockByWarehouse'] ?? null)) {
            $stockByWarehouse = $payload['stockByWarehouse'];
        }

        $product->fill([
            'business_id' => $business->id,
            'external_id' => $change['entity_id'],
            'code' => $code,
            'title' => $title,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'type' => trim((string) ($payload['type'] ?? 'product')) ?: 'product',
            'regular_price' => $this->decimal($payload['regular_price'] ?? 0),
            'purchase_price' => $this->decimal($payload['purchase_price'] ?? 0),
            'barcode_type' => $this->nullStr($payload['barcodeType'] ?? null),
            'min_stock' => is_numeric($payload['min_stock'] ?? null) ? (float) $payload['min_stock'] : null,
            'category_external_id' => $this->nullStr($payload['categoryId'] ?? null),
            'unit_of_measurement' => is_array($payload['unitOfMeasurement'] ?? null) ? $payload['unitOfMeasurement'] : null,
            'unit_of_measurement_purchase' => is_array($payload['unitOfMeasurementPurchase'] ?? null) ? $payload['unitOfMeasurementPurchase'] : null,
            'stock_by_warehouse' => $stockByWarehouse,
            'has_recipe' => (bool) ($payload['hasRecipe'] ?? false),
            'recipe_items' => is_array($payload['recipeItems'] ?? null) ? $payload['recipeItems'] : [],
            'can_breakdown' => (bool) ($payload['canBreakdown'] ?? false),
            'breakdown_target_product_external_id' => $this->nullStr($payload['breakdownTargetProductId'] ?? null),
            'breakdown_target_quantity' => is_numeric($payload['breakdownTargetQuantity'] ?? null) ? (float) $payload['breakdownTargetQuantity'] : null,
            'breakdown_target_title_snapshot' => $this->nullStr($payload['breakdownTargetTitleSnapshot'] ?? null),
            'breakdown_target_unit_symbol_snapshot' => $this->nullStr($payload['breakdownTargetUnitSymbolSnapshot'] ?? null),
            'source_created_at' => $product->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $product->trashed();
        $product->save();

        if ($wasTrashed) {
            $product->restore();
        }

        if ($isStockSeed && is_array($payload['stockByWarehouse'] ?? null)) {
            $this->inventoryProjector->setSeedMany(
                $business,
                $product->external_id,
                $payload['stockByWarehouse'],
                $event->event_id
            );
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullStr(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
