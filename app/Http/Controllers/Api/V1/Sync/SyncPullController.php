<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\PullSyncRequest;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\PointOfSale;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
// SyncReceivedEvent ya no se usa en pull — todas las entidades están materializadas.
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Support\Licensing\BusinessLicensePricingResolver;
use App\Support\Sync\ContactPayloadNormalizer;
use App\Support\Sync\SyncCompatibility;
use App\Support\Sync\SyncCursor;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Throwable ya no se usa tras migrar a SyncCursor::parse.

class SyncPullController extends Controller
{
    public function __construct(
        private readonly ContactPayloadNormalizer $contactPayloadNormalizer,
        private readonly BusinessLicensePricingResolver $pricingResolver,
        private readonly SyncCompatibility $syncCompatibility
    ) {}

    /**
     * Return remote changes since the provided cursor.
     */
    public function index(PullSyncRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $device = $this->touchDevice($request, $business);
        $requestedCursor = SyncCursor::parse($request->string('cursor')->toString() ?: null);
        $responseBoundary = now();
        $limit = (int) ($request->integer('limit') ?: 500);
        $changes = $this->getBusinessProfileChanges($business, $requestedCursor, $responseBoundary)
            ->concat($this->getLicenseCatalogChanges($business, $requestedCursor, $responseBoundary))
            ->concat($this->getLicenseQuoteChanges($business, $responseBoundary))
            ->concat($this->getProductChanges($business, $requestedCursor, $responseBoundary, $limit))
            ->concat($this->getMaterializedEntityChanges(Category::class, 'categories', $business, $requestedCursor, $responseBoundary, $limit, fn (Category $m) => $this->toCategoryPayload($m)))
            ->concat($this->getMaterializedEntityChanges(Contact::class, 'contacts', $business, $requestedCursor, $responseBoundary, $limit, fn (Contact $m) => $this->toContactPayload($m)))
            ->concat($this->getMaterializedEntityChanges(Employee::class, 'employees', $business, $requestedCursor, $responseBoundary, $limit, fn (Employee $m) => $this->toEmployeePayload($m)))
            ->concat($this->getMaterializedEntityChanges(UnitOfMeasure::class, 'units', $business, $requestedCursor, $responseBoundary, $limit, fn (UnitOfMeasure $m) => $this->toUnitPayload($m)))
            ->concat($this->getMaterializedEntityChanges(Warehouse::class, 'warehouses', $business, $requestedCursor, $responseBoundary, $limit, fn (Warehouse $m) => $this->toWarehousePayload($m)))
            ->concat($this->getMaterializedEntityChanges(PointOfSale::class, 'points_of_sale', $business, $requestedCursor, $responseBoundary, $limit, fn (PointOfSale $m) => $this->toPointOfSalePayload($m)))
            ->concat($this->getMaterializedEntityChanges(CashRegisterSession::class, 'cash_register_sessions', $business, $requestedCursor, $responseBoundary, $limit, fn (CashRegisterSession $m) => $this->toCashRegisterSessionPayload($m)))
            ->concat($this->getSaleChanges($business, $requestedCursor, $responseBoundary, $limit))
            ->concat($this->getMaterializedEntityChanges(Purchase::class, 'purchases', $business, $requestedCursor, $responseBoundary, $limit, fn (Purchase $m) => $this->toPurchasePayload($m)))
            ->concat($this->getMaterializedEntityChanges(Expense::class, 'expenses', $business, $requestedCursor, $responseBoundary, $limit, fn (Expense $m) => $this->toExpensePayload($m)))
            ->sortBy([
                ['cursor_at', 'asc'],
                ['cursor_id', 'asc'],
                ['entity_type', 'asc'],
                ['entity_id', 'asc'],
                ['event_id', 'asc'],
            ])
            ->take($limit + 1)
            ->values();
        $hasMore = $changes->count() > $limit;
        $visibleChanges = $hasMore ? $changes->take($limit)->values() : $changes->values();
        if ($hasMore && $visibleChanges->isNotEmpty()) {
            $last = $visibleChanges->last();
            $cursor = ($last['cursor_at'] ?? $responseBoundary->toIso8601String()).'|'.((int) ($last['cursor_id'] ?? 0));
        } else {
            $cursor = $responseBoundary->toIso8601String().'|0';
        }
        $conflicts = $this->getOpenConflicts($business, $requestedCursor);

        SyncCheckpoint::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'device_id' => $device->id,
            ],
            [
                'user_id' => $request->user()?->id,
                'last_pulled_cursor' => $cursor,
                'last_pulled_at' => now(),
            ]
        );

        return response()->json([
            'cursor' => $cursor,
            'server_time' => $responseBoundary->toIso8601String(),
            'changes' => $visibleChanges
                ->map(fn (array $change): array => $this->stripCursorMetadata($change))
                ->all(),
            'conflicts' => $conflicts,
            'meta' => [
                'requested_cursor' => $requestedCursor?->toString(),
                'device_id' => $device->id,
                'applied_count' => $visibleChanges->count(),
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Return the current business profile as a sync change when needed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getBusinessProfileChanges(
        Business $business,
        ?SyncCursor $requestedCursor,
        CarbonInterface $responseBoundary
    ): Collection {
        if ($business->updated_at && $business->updated_at->greaterThan($responseBoundary)) {
            return collect();
        }

        if ($requestedCursor?->updatedAt && $business->updated_at && $business->updated_at->lessThanOrEqualTo($requestedCursor->updatedAt)) {
            return collect();
        }

        $updatedAt = $business->updated_at ?? $business->created_at ?? now();

        return collect([
            [
                'event_id' => 'server:business_profile:'.$business->id.':'.$business->updated_at?->timestamp,
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => $updatedAt->toIso8601String(),
                'cursor_at' => $updatedAt->toIso8601String(),
                'cursor_id' => $business->id,
                'payload' => $this->toBusinessProfilePayload($business),
            ],
        ]);
    }

    /**
     * Return the pricing catalog when it changes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getLicenseCatalogChanges(
        Business $business,
        ?SyncCursor $requestedCursor,
        CarbonInterface $responseBoundary
    ): Collection {
        $catalog = $this->pricingResolver->catalog();
        $updatedAt = isset($catalog['updatedAt']) ? Carbon::parse((string) $catalog['updatedAt']) : null;

        if (! $updatedAt) {
            return collect();
        }

        if ($updatedAt->greaterThan($responseBoundary)) {
            return collect();
        }

        if ($requestedCursor?->updatedAt && $updatedAt->lessThanOrEqualTo($requestedCursor->updatedAt)) {
            return collect();
        }

        return collect([
            [
                'event_id' => 'server:license_catalog:'.$business->id.':'.$updatedAt->timestamp,
                'entity_type' => 'license_catalog',
                'entity_id' => 'license_catalog',
                'operation' => 'upsert',
                'occurred_at' => $updatedAt->toIso8601String(),
                'cursor_at' => $updatedAt->toIso8601String(),
                'cursor_id' => 0,
                'payload' => $catalog,
            ],
        ]);
    }

    /**
     * Return the business pricing quote on every pull so it stays fresh with active POS changes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getLicenseQuoteChanges(Business $business, CarbonInterface $responseBoundary): Collection
    {
        return collect([
            [
                'event_id' => 'server:license_quote:'.$business->id.':'.$responseBoundary->timestamp,
                'entity_type' => 'license_quote',
                'entity_id' => 'current_business_license_quote',
                'operation' => 'upsert',
                'occurred_at' => $responseBoundary->toIso8601String(),
                'cursor_at' => $responseBoundary->toIso8601String(),
                'cursor_id' => 0,
                'payload' => $this->pricingResolver->quote($business),
            ],
        ]);
    }

    /**
     * Register or refresh the device metadata.
     */
    private function touchDevice(PullSyncRequest $request, Business $business): Device
    {
        $deviceId = $request->string('device_id')->toString();
        $device = Device::query()->firstOrNew(['id' => $deviceId]);
        $existingMeta = is_array($device->meta) ? $device->meta : [];

        $device->fill([
            'business_id' => $business->id,
            'user_id' => $request->user()?->id,
            'app_version' => $this->syncCompatibility->clientAppVersion($request),
            'is_active' => true,
            'last_seen_at' => now(),
            'last_synced_at' => now(),
            'meta' => array_filter(array_merge($existingMeta, [
                'sync_version' => $this->syncCompatibility->clientSyncVersion($request),
                'last_sync_stage' => 'pull',
            ]), static fn (mixed $value): bool => $value !== null),
        ]);

        $device->save();

        return $device;
    }

    /**
     * Return incremental product deltas for the current business.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getProductChanges(
        Business $business,
        ?SyncCursor $requestedCursor,
        CarbonInterface $responseBoundary,
        int $limit
    ): Collection {
        $query = Product::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('updated_at', '<=', $responseBoundary);

        if ($requestedCursor) {
            $requestedCursor->applyFilter($query);
        }

        $products = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return $products->map(function (Product $product): array {
            $occurredAt = $product->source_updated_at ?? $product->updated_at ?? $product->created_at;

            return [
                'event_id' => $product->last_received_event_id
                    ?? 'server:products:'.$product->external_id.':'.$product->updated_at?->timestamp,
                'entity_type' => 'products',
                'entity_id' => $product->external_id,
                'operation' => $product->deleted_at ? 'delete' : 'upsert',
                'occurred_at' => $occurredAt?->toIso8601String(),
                'cursor_at' => ($product->updated_at ?? $occurredAt ?? $product->created_at)?->toIso8601String(),
                'cursor_id' => $product->id,
                'payload' => $this->toProductPayload($product),
            ];
        });
    }

    /**
     * Remove internal pagination metadata before returning the payload to the client.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function stripCursorMetadata(array $change): array
    {
        unset($change['cursor_at'], $change['cursor_id']);

        return $change;
    }

    /**
     * Return unresolved conflicts visible to the client.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getOpenConflicts(Business $business, ?SyncCursor $requestedCursor): array
    {
        return SyncConflict::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->when($requestedCursor?->updatedAt, function ($query) use ($requestedCursor) {
                $query->where('updated_at', '>', $requestedCursor->updatedAt);
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(function (SyncConflict $conflict): array {
                return [
                    'id' => $conflict->id,
                    'event_id' => $conflict->event_id,
                    'entity_type' => $conflict->entity_type,
                    'entity_id' => $conflict->entity_id,
                    'conflict_type' => $conflict->conflict_type,
                    'local_payload' => $conflict->local_payload,
                    'remote_payload' => $conflict->remote_payload,
                    'status' => $conflict->status,
                    'updated_at' => $conflict->updated_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Convert a backend product to sync payload format.
     *
     * @return array<string, mixed>
     */
    private function toProductPayload(Product $product): array
    {
        return [
            'code' => $product->code,
            'title' => $product->title,
            'description' => $product->description,
            'type' => $product->type,
            'regular_price' => (float) $product->regular_price,
            'purchase_price' => (float) $product->purchase_price,
            'barcodeType' => $product->barcode_type,
            'min_stock' => $product->min_stock !== null ? (float) $product->min_stock : null,
            'categoryId' => $product->category_external_id,
            'unitOfMeasurement' => $product->unit_of_measurement,
            'unitOfMeasurementPurchase' => $product->unit_of_measurement_purchase,
            'stockByWarehouse' => $product->stock_by_warehouse ?? [],
            'deleted_at' => $product->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Convert the current backend business profile to sync payload format.
     *
     * @return array<string, mixed>
     */
    private function toBusinessProfilePayload(Business $business): array
    {
        return [
            'businessName' => $business->name,
            'address' => $business->address,
            'phone' => $business->phone,
            'defaultCurrency' => $business->default_currency ?? 'CUP',
            'licenseExpiresAt' => $business->license_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Generic pull for any materialized entity with (business_id, external_id, updated_at) pattern.
     *
     * @param  class-string  $modelClass
     * @return Collection<int, array<string, mixed>>
     */
    private function getMaterializedEntityChanges(
        string $modelClass,
        string $entityType,
        Business $business,
        ?SyncCursor $requestedCursor,
        CarbonInterface $responseBoundary,
        int $limit,
        callable $toPayload
    ): Collection {
        $query = $modelClass::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('updated_at', '<=', $responseBoundary);

        if ($requestedCursor) {
            $requestedCursor->applyFilter($query);
        }

        $records = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return $records->map(function ($record) use ($entityType, $toPayload): array {
            $occurredAt = $record->source_updated_at ?? $record->updated_at ?? $record->created_at;

            return [
                'event_id' => $record->last_received_event_id
                    ?? "server:{$entityType}:{$record->external_id}:{$record->updated_at?->timestamp}",
                'entity_type' => $entityType,
                'entity_id' => $record->external_id,
                'operation' => $record->deleted_at ? 'delete' : 'upsert',
                'occurred_at' => $occurredAt?->toIso8601String(),
                'cursor_at' => ($record->updated_at ?? $occurredAt ?? $record->created_at)?->toIso8601String(),
                'cursor_id' => $record->id,
                'payload' => $toPayload($record),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function toCategoryPayload(Category $m): array
    {
        return [
            '_id' => $m->external_id,
            'name' => $m->name,
            'description' => $m->description,
            'code' => $m->code,
            'color' => $m->color,
            'icon' => $m->icon,
            'parentId' => $m->parent_external_id,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toContactPayload(Contact $m): array
    {
        return [
            'name' => $m->name,
            'mobile' => $m->mobile,
            'email' => $m->email,
            'idCard' => $m->id_card,
            'type' => $m->type,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toEmployeePayload(Employee $m): array
    {
        return [
            'name' => $m->name,
            'mobile' => $m->mobile,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toUnitPayload(UnitOfMeasure $m): array
    {
        return [
            '_id' => $m->external_id,
            'name' => $m->name,
            'symbol' => $m->symbol,
            'category' => $m->category,
            'ratio' => (float) $m->ratio,
            'is_reference' => $m->is_reference,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toWarehousePayload(Warehouse $m): array
    {
        return [
            '_id' => $m->external_id,
            'name' => $m->name,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toPointOfSalePayload(PointOfSale $m): array
    {
        return [
            '_id' => $m->external_id,
            'name' => $m->name,
            'warehouseId' => $m->warehouse_external_id,
            'employees' => $m->employees ?? [],
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toCashRegisterSessionPayload(CashRegisterSession $m): array
    {
        return [
            '_id' => $m->external_id,
            'posId' => $m->pos_external_id,
            'warehouseId' => $m->warehouse_external_id,
            'status' => $m->status,
            'opened_at' => $m->opened_at?->toIso8601String(),
            'closed_at' => $m->closed_at?->toIso8601String(),
            'opening_balance' => $m->opening_balance !== null ? (float) $m->opening_balance : 0.0,
            'closing_balance' => $m->closing_balance !== null ? (float) $m->closing_balance : null,
            'opened_by' => $m->opened_by,
            'closed_by' => $m->closed_by,
            'initial_inventory_snapshot' => $m->initial_inventory_snapshot,
            'final_inventory_snapshot' => $m->final_inventory_snapshot,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Sales need eager-loaded lines, so they use a dedicated pull method instead of the generic one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getSaleChanges(
        Business $business,
        ?SyncCursor $requestedCursor,
        CarbonInterface $responseBoundary,
        int $limit
    ): Collection {
        $query = Sale::query()
            ->withTrashed()
            ->with('lines')
            ->where('business_id', $business->id)
            ->where('updated_at', '<=', $responseBoundary);

        if ($requestedCursor) {
            $requestedCursor->applyFilter($query);
        }

        $sales = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return $sales->map(function (Sale $sale): array {
            $occurredAt = $sale->source_updated_at ?? $sale->updated_at ?? $sale->created_at;

            return [
                'event_id' => $sale->last_received_event_id
                    ?? "server:sales:{$sale->external_id}:{$sale->updated_at?->timestamp}",
                'entity_type' => 'sales',
                'entity_id' => $sale->external_id,
                'operation' => $sale->deleted_at ? 'delete' : 'upsert',
                'occurred_at' => $occurredAt?->toIso8601String(),
                'cursor_at' => ($sale->updated_at ?? $occurredAt ?? $sale->created_at)?->toIso8601String(),
                'cursor_id' => $sale->id,
                'payload' => $this->toSalePayload($sale),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function toSalePayload(Sale $s): array
    {
        return [
            'type' => 'sale',
            'reference' => $s->reference,
            'contact' => $s->contact_external_id,
            'pos' => $s->pos_external_id,
            'posId' => $s->pos_external_id,
            'warehouseId' => $s->warehouse_external_id,
            'cashRegisterSessionId' => $s->cash_register_session_id,
            'lines' => $s->lines->map(fn ($l) => [
                'productId' => $l->product_external_id,
                'productTitle' => $l->product_title,
                'price' => (float) $l->price,
                'amount' => (float) $l->amount,
                'subTotal' => (float) $l->sub_total,
            ])->all(),
            'inventoryConsumption' => $s->inventory_consumption ?? [],
            'dateTime' => $s->transaction_at?->toIso8601String(),
            'total' => (float) $s->total,
            'status' => $s->status,
            'currency' => $s->currency,
            'paymentMethod' => $s->payment_method,
            'amountReceived' => $s->amount_received !== null ? (float) $s->amount_received : null,
            'change' => $s->change_amount !== null ? (float) $s->change_amount : null,
            'cashBreakdown' => $s->cash_breakdown ?? [],
            'cardPaymentDetails' => $s->card_payment_details,
            'createdBy' => $s->created_by,
            'saleIdImport' => $s->sale_id_import,
            'itemsImported' => $s->items_imported ?? [],
            'deleted_at' => $s->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toPurchasePayload(Purchase $p): array
    {
        $lines = $p->relationLoaded('lines') ? $p->lines : $p->lines()->orderBy('sort_order')->get();

        return [
            'type' => 'purchase',
            'reference' => $p->reference,
            'contact' => $p->contact_external_id,
            'warehouseId' => $p->warehouse_external_id,
            'lines' => $lines->map(fn ($l) => [
                'productId' => $l->product_external_id,
                'productTitle' => $l->product_title,
                'price' => (float) $l->price,
                'amount' => (float) $l->amount,
                'subTotal' => (float) $l->sub_total,
            ])->all(),
            'inventoryConsumption' => $p->inventory_consumption ?? [],
            'dateTime' => $p->transaction_at?->toIso8601String(),
            'total' => (float) $p->total,
            'status' => $p->status,
            'currency' => $p->currency,
            'createdBy' => $p->created_by,
            'deleted_at' => $p->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toExpensePayload(Expense $e): array
    {
        return [
            'date' => $e->expense_date?->toIso8601String(),
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'category' => $e->category,
            'deleted_at' => $e->deleted_at?->toIso8601String(),
        ];
    }
}
