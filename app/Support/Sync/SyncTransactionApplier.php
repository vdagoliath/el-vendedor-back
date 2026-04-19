<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SyncReceivedEvent;
use Illuminate\Support\Carbon;
use Throwable;

class SyncTransactionApplier
{
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
                $sale->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $sale->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

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

        return true;
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
                $purchase->forceFill([
                    'last_received_event_id' => $event->event_id,
                    'source_updated_at' => $occurredAt ?? now(),
                ])->save();
                $purchase->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

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

        return true;
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
