<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\SyncReceivedEvent;
use App\Support\Inventory\InventoryProjector;
use Illuminate\Support\Carbon;
use Throwable;

class SyncTransactionApplier
{
    public function __construct(private readonly InventoryProjector $projector)
    {
    }

    /**
     * Apply a transaction sync change to the corresponding materialized table.
     * Returns true if handled, false if entity_type is not a transaction.
     */
    public function apply(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return match ($change['entity_type']) {
            'sales' => $this->applySale($business, $event, $change),
            'purchases' => $this->applyPurchase($business, $event, $change),
            'expenses' => $this->applyExpense($business, $event, $change),
            'stock_movements' => $this->applyStockMovement($business, $event, $change),
            'stock_adjustments' => $this->applyStockAdjustment($business, $event, $change),
            default => false,
        };
    }

    private function applySale(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var Sale|null $sale */
        $sale = Sale::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if ($sale && ! $sale->trashed()) {
                // Si estaba descontando stock al borrarse, restauramos.
                if ($this->isSaleStockReducing($sale->status) && $sale->warehouse_external_id) {
                    $this->projectSaleStock($business, $sale, +1, $event->event_id);
                }

                $sale->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $sale->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $previousStatus = $sale?->status;

        if (! $sale) {
            $sale = new Sale([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $sale->fill([
            'business_id' => $business->id,
            'external_id' => $entityId,
            'reference' => $this->nullStr($payload['reference'] ?? null),
            'contact_external_id' => $this->nullStr($payload['contact'] ?? null),
            'contact_snapshot' => $this->normalizeContactSnapshot(
                $payload['contactSnapshot'] ?? null,
                $sale->contact_snapshot
            ),
            'pos_external_id' => $this->nullStr($payload['posId'] ?? $payload['pos'] ?? null),
            'warehouse_external_id' => $this->nullStr($payload['warehouseId'] ?? null),
            'cash_register_session_id' => $this->nullStr($payload['cashRegisterSessionId'] ?? null),
            'total' => $this->decimal($payload['total'] ?? 0),
            'status' => $this->nullStr($payload['status'] ?? 'completed') ?? 'completed',
            'currency' => $this->nullStr($payload['currency'] ?? null),
            'payment_method' => $this->nullStr($payload['paymentMethod'] ?? null),
            'amount_received' => isset($payload['amountReceived']) ? $this->decimal($payload['amountReceived']) : null,
            'change_amount' => isset($payload['change']) ? $this->decimal($payload['change']) : null,
            'cash_breakdown' => is_array($payload['cashBreakdown'] ?? null) ? $payload['cashBreakdown'] : null,
            'card_payment_details' => is_array($payload['cardPaymentDetails'] ?? null) ? $payload['cardPaymentDetails'] : null,
            'created_by' => is_array($payload['createdBy'] ?? null) ? $payload['createdBy'] : null,
            'inventory_consumption' => is_array($payload['inventoryConsumption'] ?? null) ? $payload['inventoryConsumption'] : null,
            'sale_id_import' => $this->nullStr($payload['saleIdImport'] ?? null),
            'items_imported' => is_array($payload['itemsImported'] ?? null) ? $payload['itemsImported'] : null,
            'transaction_at' => $this->parseDate($payload['dateTime'] ?? null) ?? $occurredAt ?? now(),
            'source_created_at' => $sale->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $sale->trashed();
        $sale->save();

        if ($wasTrashed) {
            $sale->restore();
        }

        $this->syncLines(SaleLine::class, 'sale_id', $sale->id, $payload['lines'] ?? []);

        // Refrescamos para que la proyección lea las líneas recién materializadas.
        $sale->refresh();
        $this->applySaleStockTransition($business, $previousStatus, $sale, $event->event_id);

        return true;
    }

    private function applySaleStockTransition(
        Business $business,
        ?string $previousStatus,
        Sale $next,
        string $eventId
    ): void {
        if (! $next->warehouse_external_id) {
            return;
        }

        $wasReducing = $previousStatus !== null && $this->isSaleStockReducing($previousStatus);
        $isReducing = $this->isSaleStockReducing($next->status);

        if (! $wasReducing && $isReducing) {
            $this->projectSaleStock($business, $next, -1, $eventId);

            return;
        }

        if ($wasReducing && in_array($next->status, ['returned', 'canceled'], true)) {
            $this->projectSaleStock($business, $next, +1, $eventId);
        }
    }

    private function projectSaleStock(Business $business, Sale $sale, int $sign, string $eventId): void
    {
        if (! $sale->warehouse_external_id) {
            return;
        }

        // Para el efecto sobre stock preferimos `inventory_consumption`
        // (recetas expandidas en componentes) y caemos a las líneas si no
        // está disponible.
        $consumption = is_array($sale->inventory_consumption) ? $sale->inventory_consumption : null;
        if ($consumption !== null && $consumption !== []) {
            $this->projector->applyLines(
                $business,
                $sale->warehouse_external_id,
                $consumption,
                $sign,
                $eventId
            );

            return;
        }

        $lines = SaleLine::query()
            ->where('sale_id', $sale->id)
            ->get(['product_external_id', 'amount']);

        $this->projector->applyLines(
            $business,
            $sale->warehouse_external_id,
            $lines,
            $sign,
            $eventId
        );
    }

    private function isSaleStockReducing(?string $status): bool
    {
        return in_array($status, ['completed', 'credit', 'pending'], true);
    }

    private function applyPurchase(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var Purchase|null $purchase */
        $purchase = Purchase::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if ($purchase && ! $purchase->trashed()) {
                if ($purchase->status === 'completed' && $purchase->warehouse_external_id) {
                    $this->projectPurchaseStock($business, $purchase, -1, $event->event_id);
                }

                $purchase->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $purchase->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $previousStatus = $purchase?->status;

        if (! $purchase) {
            $purchase = new Purchase([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $purchase->fill([
            'business_id' => $business->id,
            'external_id' => $entityId,
            'reference' => $this->nullStr($payload['reference'] ?? null),
            'contact_external_id' => $this->nullStr($payload['contact'] ?? null),
            'warehouse_external_id' => $this->nullStr($payload['warehouseId'] ?? null),
            'total' => $this->decimal($payload['total'] ?? 0),
            'status' => $this->nullStr($payload['status'] ?? 'completed') ?? 'completed',
            'currency' => $this->nullStr($payload['currency'] ?? null),
            'created_by' => is_array($payload['createdBy'] ?? null) ? $payload['createdBy'] : null,
            'inventory_consumption' => is_array($payload['inventoryConsumption'] ?? null) ? $payload['inventoryConsumption'] : null,
            'transaction_at' => $this->parseDate($payload['dateTime'] ?? null) ?? $occurredAt ?? now(),
            'source_created_at' => $purchase->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $purchase->trashed();
        $purchase->save();

        if ($wasTrashed) {
            $purchase->restore();
        }

        $this->syncLines(PurchaseLine::class, 'purchase_id', $purchase->id, $payload['lines'] ?? []);

        $purchase->refresh();
        $this->applyPurchaseStockTransition($business, $previousStatus, $purchase, $event->event_id);

        return true;
    }

    private function applyPurchaseStockTransition(
        Business $business,
        ?string $previousStatus,
        Purchase $next,
        string $eventId
    ): void {
        if (! $next->warehouse_external_id) {
            return;
        }

        $wasCompleted = $previousStatus === 'completed';
        $isCompleted = $next->status === 'completed';

        if (! $wasCompleted && $isCompleted) {
            $this->projectPurchaseStock($business, $next, +1, $eventId);

            return;
        }

        if ($wasCompleted && in_array($next->status, ['returned', 'canceled'], true)) {
            $this->projectPurchaseStock($business, $next, -1, $eventId);
        }
    }

    private function projectPurchaseStock(Business $business, Purchase $purchase, int $sign, string $eventId): void
    {
        if (! $purchase->warehouse_external_id) {
            return;
        }

        $lines = PurchaseLine::query()
            ->where('purchase_id', $purchase->id)
            ->get(['product_external_id', 'amount']);

        $this->projector->applyLines(
            $business,
            $purchase->warehouse_external_id,
            $lines,
            $sign,
            $eventId
        );
    }

    private function applyExpense(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var Expense|null $expense */
        $expense = Expense::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if ($expense && ! $expense->trashed()) {
                $expense->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $expense->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        if (! $expense) {
            $expense = new Expense([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $expense->fill([
            'business_id' => $business->id,
            'external_id' => $entityId,
            'expense_date' => $this->parseDate($payload['date'] ?? null) ?? $occurredAt ?? now(),
            'description' => $this->nullStr($payload['description'] ?? null),
            'amount' => $this->decimal($payload['amount'] ?? 0),
            'category' => $this->nullStr($payload['category'] ?? null),
            'source_created_at' => $expense->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $expense->trashed();
        $expense->save();

        if ($wasTrashed) {
            $expense->restore();
        }

        return true;
    }

    private function applyStockMovement(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var StockMovement|null $movement */
        $movement = StockMovement::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if ($movement && ! $movement->trashed()) {
                // Revertimos el delta original si el movimiento estaba activo.
                $this->projector->applyDelta(
                    $business,
                    $movement->product_external_id,
                    $movement->from_warehouse_external_id,
                    +(float) $movement->quantity,
                    $event->event_id
                );
                $this->projector->applyDelta(
                    $business,
                    $movement->product_external_id,
                    $movement->to_warehouse_external_id,
                    -(float) $movement->quantity,
                    $event->event_id
                );

                $movement->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $movement->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        $productExternalId = $this->nullStr($payload['productId'] ?? null);
        $fromWarehouseId = $this->nullStr($payload['fromWarehouseId'] ?? null);
        $toWarehouseId = $this->nullStr($payload['toWarehouseId'] ?? null);
        $quantity = $this->decimal($payload['quantity'] ?? 0);

        if ($productExternalId === null || $fromWarehouseId === null || $toWarehouseId === null) {
            throw new \RuntimeException('El movimiento de stock sincronizado no tiene producto u almacenes válidos.');
        }

        $isNew = $movement === null;

        if (! $movement) {
            $movement = new StockMovement([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $movement->fill([
            'business_id' => $business->id,
            'external_id' => $entityId,
            'product_external_id' => $productExternalId,
            'from_warehouse_external_id' => $fromWarehouseId,
            'to_warehouse_external_id' => $toWarehouseId,
            'quantity' => $quantity,
            'movement_at' => $this->parseDate($payload['timestamp'] ?? null) ?? $occurredAt ?? now(),
            'source_created_at' => $movement->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $movement->trashed();
        $movement->save();

        if ($wasTrashed) {
            $movement->restore();
        }

        // Sólo aplicamos a la proyección la PRIMERA vez que vemos el evento.
        // Reposts/upserts del mismo movimiento no deben acumular delta. La
        // tabla `sync_received_events` ya deduplica por event_id, así que
        // cuando llegamos hasta acá con $isNew=false estamos viendo un
        // upsert legítimo del mismo recurso (raro, pero posible).
        if ($isNew || $wasTrashed) {
            $this->projector->applyDelta(
                $business,
                $productExternalId,
                $fromWarehouseId,
                -(float) $quantity,
                $event->event_id
            );
            $this->projector->applyDelta(
                $business,
                $productExternalId,
                $toWarehouseId,
                +(float) $quantity,
                $event->event_id
            );
        }

        return true;
    }

    private function applyStockAdjustment(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var StockAdjustment|null $adjustment */
        $adjustment = StockAdjustment::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if ($adjustment && ! $adjustment->trashed()) {
                $this->projector->applyDelta(
                    $business,
                    $adjustment->product_external_id,
                    $adjustment->warehouse_external_id,
                    -(float) $adjustment->change_quantity,
                    $event->event_id
                );

                $adjustment->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $adjustment->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        $productExternalId = $this->nullStr($payload['productId'] ?? null);
        $warehouseId = $this->nullStr($payload['warehouseId'] ?? null);

        if ($productExternalId === null || $warehouseId === null) {
            throw new \RuntimeException('El ajuste de stock sincronizado no tiene producto o almacén válido.');
        }

        $isNew = $adjustment === null;
        $changeQuantity = $this->decimal($payload['changeQuantity'] ?? 0);

        if (! $adjustment) {
            $adjustment = new StockAdjustment([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $adjustment->fill([
            'business_id' => $business->id,
            'external_id' => $entityId,
            'product_external_id' => $productExternalId,
            'warehouse_external_id' => $warehouseId,
            'target_quantity' => $this->decimal($payload['quantity'] ?? 0),
            'change_quantity' => $changeQuantity,
            'previous_quantity' => isset($payload['previousQuantity']) && is_numeric($payload['previousQuantity'])
                ? $this->decimal($payload['previousQuantity'])
                : null,
            'reason' => $this->nullStr($payload['reason'] ?? null),
            'adjustment_at' => $this->parseDate($payload['timestamp'] ?? null) ?? $occurredAt ?? now(),
            'source_created_at' => $adjustment->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $adjustment->trashed();
        $adjustment->save();

        if ($wasTrashed) {
            $adjustment->restore();
        }

        if ($isNew || $wasTrashed) {
            $this->projector->applyDelta(
                $business,
                $productExternalId,
                $warehouseId,
                (float) $changeQuantity,
                $event->event_id
            );
        }

        return true;
    }

    /**
     * Replace all lines for a parent record (sale or purchase).
     *
     * @param  class-string  $lineModelClass
     */
    private function syncLines(string $lineModelClass, string $foreignKey, int $parentId, mixed $rawLines): void
    {
        $lineModelClass::query()->where($foreignKey, $parentId)->delete();

        if (! is_array($rawLines)) {
            return;
        }

        foreach ($rawLines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineModelClass::query()->create([
                $foreignKey => $parentId,
                'product_external_id' => $this->nullStr($line['productId'] ?? null),
                'product_title' => $this->nullStr($line['productTitle'] ?? null),
                'price' => $this->decimal($line['price'] ?? 0),
                'amount' => $this->decimal($line['amount'] ?? 0),
                'sub_total' => $this->decimal($line['subTotal'] ?? (($line['price'] ?? 0) * ($line['amount'] ?? 0))),
                'sort_order' => $index,
            ]);
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

        $n = trim($value);

        return $n === '' ? null : $n;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Normalize the customer snapshot pushed by clients so it always has the
     * same shape downstream. Falls back to the previously stored snapshot when
     * the incoming payload omits it (avoids losing context on partial updates).
     *
     * @return array<string, string|null>|null
     */
    private function normalizeContactSnapshot(mixed $incoming, mixed $existing): ?array
    {
        if (! is_array($incoming)) {
            return is_array($existing) ? $existing : null;
        }

        $snapshot = [
            'name' => $this->nullStr($incoming['name'] ?? null),
            'mobile' => $this->nullStr($incoming['mobile'] ?? null),
            'idCard' => $this->nullStr($incoming['idCard'] ?? $incoming['id_card'] ?? null),
        ];

        $hasAny = array_filter($snapshot, static fn (mixed $value): bool => $value !== null);

        return $hasAny ? $snapshot : (is_array($existing) ? $existing : null);
    }
}
