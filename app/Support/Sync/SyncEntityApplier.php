<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\MetricsSnapshot;
use App\Models\PointOfSale;
use App\Models\ProductBatch;
use App\Models\SyncReceivedEvent;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Models\WeightJournal;
use Illuminate\Support\Carbon;
use Throwable;

class SyncEntityApplier
{
    /**
     * Apply a sync change to the corresponding materialized entity table.
     * Returns true if the entity was materialized, false if entity_type is not supported here.
     */
    public function apply(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return match ($change['entity_type']) {
            'categories' => $this->applyCategory($business, $event, $change),
            'contacts' => $this->applyContact($business, $event, $change),
            'employees' => $this->applyEmployee($business, $event, $change),
            'units' => $this->applyUnit($business, $event, $change),
            'warehouses' => $this->applyWarehouse($business, $event, $change),
            'points_of_sale' => $this->applyPointOfSale($business, $event, $change),
            'product_batches' => $this->applyProductBatch($business, $event, $change),
            'metrics_snapshots' => $this->applyMetricsSnapshot($business, $event, $change),
            'weight_journals' => $this->applyWeightJournal($business, $event, $change),
            default => false,
        };
    }

    private function applyCategory(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Category::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'description' => $this->nullableString($payload['description'] ?? null),
                'code' => $this->nullableString($payload['code'] ?? null),
                'color' => $this->nullableString($payload['color'] ?? null),
                'icon' => $this->nullableString($payload['icon'] ?? null),
                'parent_external_id' => $this->nullableString($payload['parentId'] ?? null),
            ]
        );
    }

    private function applyContact(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Contact::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'mobile' => $this->nullableString($payload['mobile'] ?? null),
                'email' => $this->nullableString($payload['email'] ?? null),
                'id_card' => $this->nullableString($payload['idCard'] ?? null),
                'type' => strtolower(trim((string) ($payload['type'] ?? 'customer'))),
            ]
        );
    }

    private function applyEmployee(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Employee::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'mobile' => $this->nullableString($payload['mobile'] ?? null),
            ]
        );
    }

    private function applyUnit(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            UnitOfMeasure::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'symbol' => trim((string) ($payload['symbol'] ?? '')),
                'category' => $this->nullableString($payload['category'] ?? null),
                'ratio' => is_numeric($payload['ratio'] ?? null) ? (float) $payload['ratio'] : 1,
                'is_reference' => (bool) ($payload['is_reference'] ?? false),
            ]
        );
    }

    private function applyWarehouse(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            Warehouse::class,
            $business,
            $event,
            $change,
            function (array $payload): array {
                $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

                return [
                    'name' => trim((string) ($payload['name'] ?? '')),
                    'country' => $this->nullableString($address['country'] ?? null),
                    'province' => $this->nullableString($address['province'] ?? null),
                    'municipality' => $this->nullableString($address['municipality'] ?? null),
                    'street' => $this->nullableString($address['street'] ?? null),
                ];
            }
        );
    }

    private function applyPointOfSale(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            PointOfSale::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'warehouse_external_id' => $this->nullableString($payload['warehouseId'] ?? null),
                'employees' => is_array($payload['employees'] ?? null) ? $payload['employees'] : null,
            ]
        );
    }

    private function applyProductBatch(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            ProductBatch::class,
            $business,
            $event,
            $change,
            fn (array $payload) => [
                'product_external_id' => trim((string) ($payload['productId'] ?? '')),
                'warehouse_external_id' => trim((string) ($payload['warehouseId'] ?? '')),
                'batch_code' => $this->nullableString($payload['batchCode'] ?? null),
                'quantity' => is_numeric($payload['quantity'] ?? null) ? (float) $payload['quantity'] : 0,
                'remaining_quantity' => is_numeric($payload['remainingQuantity'] ?? null)
                    ? (float) $payload['remainingQuantity']
                    : (is_numeric($payload['quantity'] ?? null) ? (float) $payload['quantity'] : 0),
                'expiration_date' => $this->parseDate($payload['expirationDate'] ?? null)?->toDateString(),
                'received_at' => $this->parseDate($payload['receivedAt'] ?? null),
                'source' => $this->nullableString($payload['source'] ?? null),
                'source_id' => $this->nullableString($payload['sourceId'] ?? null),
            ]
        );
    }

    private function applyMetricsSnapshot(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            MetricsSnapshot::class,
            $business,
            $event,
            $change,
            function (array $payload): array {
                return [
                    'period' => trim((string) ($payload['period'] ?? 'day')) ?: 'day',
                    'period_start' => $this->parseDate($payload['periodStart'] ?? $payload['period_start'] ?? null)?->toDateString() ?? now()->toDateString(),
                    'period_end' => $this->parseDate($payload['periodEnd'] ?? $payload['period_end'] ?? $payload['periodStart'] ?? null)?->toDateString() ?? now()->toDateString(),
                    'generated_at' => $this->parseDate($payload['generatedAt'] ?? $payload['generated_at'] ?? null),
                    'source_runs' => is_array($payload['sourceRuns'] ?? null) ? array_values($payload['sourceRuns']) : [],
                    'source_counts' => is_array($payload['sourceCounts'] ?? null) ? $payload['sourceCounts'] : ['bills' => 0, 'expenses' => 0],
                    'totals' => is_array($payload['totals'] ?? null) ? $payload['totals'] : [],
                    'products' => is_array($payload['products'] ?? null) ? array_values($payload['products']) : [],
                    'expense_categories' => is_array($payload['expenseCategories'] ?? null) ? array_values($payload['expenseCategories']) : [],
                ];
            }
        );
    }

    private function applyWeightJournal(Business $business, SyncReceivedEvent $event, array $change): bool
    {
        return $this->upsertOrDelete(
            WeightJournal::class,
            $business,
            $event,
            $change,
            function (array $payload) use ($business, $change): array {
                $status = trim((string) ($payload['status'] ?? 'open')) ?: 'open';
                $sessionExternalId = trim((string) ($payload['cashRegisterSessionId'] ?? ''));

                if ($status === 'open' && $sessionExternalId !== '') {
                    $hasAnotherOpenJournal = WeightJournal::query()
                        ->where('business_id', $business->id)
                        ->where('cash_register_session_external_id', $sessionExternalId)
                        ->where('status', 'open')
                        ->where('external_id', '!=', $change['entity_id'])
                        ->exists();

                    if ($hasAnotherOpenJournal) {
                        throw new \RuntimeException('Ya existe una jornada por peso abierta para esta sesión de caja.');
                    }
                }

                return [
                    'status' => $status,
                    'opened_at' => $this->parseDate($payload['openedAt'] ?? null),
                    'closed_at' => $this->parseDate($payload['closedAt'] ?? null),
                    'pos_external_id' => trim((string) ($payload['posId'] ?? '')),
                    'pos_name' => $this->nullableString($payload['posName'] ?? null),
                    'cash_register_session_external_id' => $sessionExternalId,
                    'warehouse_external_id' => trim((string) ($payload['warehouseId'] ?? '')),
                    'payment_method' => $this->nullableString($payload['paymentMethod'] ?? null),
                    'items' => is_array($payload['items'] ?? null) ? array_values($payload['items']) : [],
                    'total_sold_quantity' => $this->decimal($payload['totalSoldQuantity'] ?? 0),
                    'total_loss_quantity' => $this->decimal($payload['totalLossQuantity'] ?? 0),
                    'total' => $this->decimal($payload['total'] ?? 0),
                    'sale_external_id' => $this->nullableString($payload['saleId'] ?? null),
                    'sale_reference' => $this->nullableString($payload['saleReference'] ?? null),
                    'notes' => $this->nullableString($payload['notes'] ?? null),
                ];
            }
        );
    }

    /**
     * Generic upsert-or-delete for any entity with (business_id, external_id) unique key.
     *
     * @param  class-string  $modelClass
     * @param  callable(array): array  $mapPayload
     */
    private function upsertOrDelete(string $modelClass, Business $business, SyncReceivedEvent $event, array $change, callable $mapPayload): bool
    {
        $entityId = $change['entity_id'];
        $operation = $change['operation'];
        $occurredAt = $this->parseDate($change['occurred_at'] ?? null);

        /** @var \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\SoftDeletes|null $record */
        $record = $modelClass::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('external_id', $entityId)
            ->first();

        if ($operation === 'delete') {
            if (! $record) {
                return true;
            }

            $record->forceFill([
                'last_received_event_id' => $event->event_id,
                'source_updated_at' => $occurredAt ?? now(),
            ])->save();

            if (! $record->trashed()) {
                $record->delete();
            }

            return true;
        }

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $fields = $mapPayload($payload);

        if (! $record) {
            $record = new $modelClass([
                'business_id' => $business->id,
                'external_id' => $entityId,
            ]);
        }

        $record->fill(array_merge($fields, [
            'business_id' => $business->id,
            'external_id' => $entityId,
            'source_created_at' => $record->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]));

        $wasTrashed = method_exists($record, 'trashed') && $record->trashed();
        $record->save();

        if ($wasTrashed) {
            $record->restore();
        }

        return true;
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

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
