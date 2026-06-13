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
use App\Models\ProductBreakdown;
use App\Models\ProductLoss;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
// SyncReceivedEvent ya no se usa en pull — todas las entidades están materializadas.
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Support\Licensing\BusinessLicensePricingResolver;
use App\Support\Sync\ContactPayloadNormalizer;
use App\Support\Sync\SyncCompatibility;
use App\Support\Sync\SyncCursor;
use App\Support\Sync\SyncEntityCursorBundle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Throwable ya no se usa tras migrar a SyncCursor::parse.

class SyncPullController extends Controller
{
    public function __construct(
        private readonly ContactPayloadNormalizer $contactPayloadNormalizer,
        private readonly BusinessLicensePricingResolver $pricingResolver,
        private readonly SyncCompatibility $syncCompatibility
    ) {}

    /**
     * Fixed order used to drain entities serially. Keeping dependencies (e.g.
     * contacts before sales) earlier avoids foreign-key-style gaps on the
     * client while it materializes changes.
     *
     * @var array<int, string>
     */
    private const ENTITY_ORDER = [
        'business_profile',
        'license_catalog',
        'categories',
        'units',
        'employees',
        'warehouses',
        'points_of_sale',
        'cash_register_sessions',
        'contacts',
        'products',
        'stock_movements',
        'stock_adjustments',
        'product_losses',
        'product_breakdowns',
        'sales',
        'purchases',
        'expenses',
    ];

    /**
     * Return remote changes since the provided cursor.
     *
     * The pull drains one entity_type per request following a fixed order.
     * Each entity_type carries its own sub-cursor in the returned bundle so
     * table-local ids never bleed into the filters of other tables.
     */
    public function index(PullSyncRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $device = $this->touchDevice($request, $business);
        $rawCursor = $request->string('cursor')->toString() ?: null;
        $responseBoundary = now();
        $limit = (int) ($request->integer('limit') ?: 500);
        if ($limit < 1) {
            $limit = 500;
        }

        // Cursor v3: ordenamiento global por server_version. Si el cliente
        // envía `v3:<N>`, despachamos al pipeline nuevo. Si manda el cursor
        // bundle (formato v2-serial) o nada, conservamos el camino legacy.
        if (is_string($rawCursor) && str_starts_with($rawCursor, 'v3:')) {
            return $this->indexByServerVersion($request, $business, $device, $rawCursor, $limit, $responseBoundary);
        }

        $bundle = SyncEntityCursorBundle::parse($rawCursor);

        $visibleChanges = collect();
        $remainingBudget = $limit;
        $hasMore = false;
        $servedEntities = [];

        foreach (self::ENTITY_ORDER as $entityType) {
            if ($remainingBudget <= 0) {
                // Llenamos el cupo del lote; puede haber más en entidades no visitadas.
                $hasMore = true;
                break;
            }

            $cursor = $bundle->cursorFor($entityType);
            // Pedimos budget+1 para saber si la entidad tiene más que el cupo restante
            // sin tener que volver a consultarla en esta misma iteración.
            $entityChanges = $this->fetchEntityChanges(
                $entityType,
                $business,
                $cursor,
                $responseBoundary,
                $remainingBudget + 1
            );

            if ($entityChanges->isEmpty()) {
                $bundle = $bundle->withAdvance($entityType, $responseBoundary, 0);

                continue;
            }

            $entityHasMore = $entityChanges->count() > $remainingBudget;
            $slice = $entityHasMore
                ? $entityChanges->take($remainingBudget)->values()
                : $entityChanges->values();

            $visibleChanges = $visibleChanges->concat($slice)->values();
            $servedEntities[] = $entityType;

            $last = $slice->last();
            $lastCursorAt = isset($last['cursor_at']) ? Carbon::parse((string) $last['cursor_at']) : $responseBoundary;
            $lastCursorId = (int) ($last['cursor_id'] ?? 0);
            $bundle = $bundle->withAdvance($entityType, $lastCursorAt, $lastCursorId);

            $remainingBudget -= $slice->count();

            if ($entityHasMore) {
                $hasMore = true;
                break;
            }
        }

        // license_quote stays out of the serial drain: it is emitted inline with
        // every response so the client always sees a fresh quote without blocking
        // other entities on a singleton that has no real cursor.
        $licenseQuoteChanges = $this->getLicenseQuoteChanges($business, $responseBoundary);
        if ($licenseQuoteChanges->isNotEmpty()) {
            $visibleChanges = $visibleChanges->concat($licenseQuoteChanges)->values();
        }

        $cursor = $bundle->toString();
        $conflicts = $this->getOpenConflicts($business, $bundle);

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
                'requested_cursor' => $rawCursor,
                'device_id' => $device->id,
                'applied_count' => $visibleChanges->count(),
                'has_more' => $hasMore,
                // Mantenemos la clave singular por backcompat con clientes/tests
                // previos; apunta a la última entidad servida en este lote.
                'served_entity' => empty($servedEntities) ? null : end($servedEntities),
                'served_entities' => $servedEntities,
                'cursor_format' => 'v2-serial',
            ],
        ]);
    }

    /**
     * Pull con cursor numérico global (`v3:<N>`).
     *
     * Recolecta candidatos de TODAS las tablas materializadas filtrando
     * por `server_version > N`, los ordena globalmente y los materializa
     * en payloads de cambio. El cursor de respuesta es la versión del
     * último cambio entregado.
     */
    private function indexByServerVersion(
        PullSyncRequest $request,
        Business $business,
        Device $device,
        string $rawCursor,
        int $limit,
        CarbonInterface $responseBoundary
    ): JsonResponse {
        $since = (int) substr($rawCursor, 3);
        if ($since < 0) {
            $since = 0;
        }

        $candidates = $this->collectVersionCandidates($business, $since, $limit + 1);
        $hasMore = count($candidates) > $limit;
        $slice = array_slice($candidates, 0, $limit);

        $changes = $this->materializeVersionedChanges($business, $slice, $responseBoundary);

        $licenseQuoteChanges = $this->getLicenseQuoteChanges($business, $responseBoundary);
        $changes = collect($changes)->concat($licenseQuoteChanges)->values()->all();

        $maxVersion = collect($slice)->pluck('server_version')->max();
        $newCursor = 'v3:'.($maxVersion !== null ? (int) $maxVersion : $since);

        $conflicts = $this->getOpenConflictsForCursor($business);

        SyncCheckpoint::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'device_id' => $device->id,
            ],
            [
                'user_id' => $request->user()?->id,
                'last_pulled_cursor' => $newCursor,
                'last_pulled_at' => now(),
            ]
        );

        return response()->json([
            'cursor' => $newCursor,
            'server_time' => $responseBoundary->toIso8601String(),
            'changes' => $changes,
            'conflicts' => $conflicts,
            'meta' => [
                'requested_cursor' => $rawCursor,
                'device_id' => $device->id,
                'applied_count' => count($changes),
                'has_more' => $hasMore,
                'cursor_format' => 'v3-server-version',
            ],
        ]);
    }

    /**
     * @return array<int, array{entity_type: string, id: int, server_version: int}>
     */
    private function collectVersionCandidates(Business $business, int $since, int $limit): array
    {
        $tables = [
            'business_profile' => ['table' => 'businesses', 'where' => 'id', 'value' => $business->id],
            'categories' => ['table' => 'categories', 'where' => 'business_id', 'value' => $business->id],
            'units' => ['table' => 'units_of_measure', 'where' => 'business_id', 'value' => $business->id],
            'employees' => ['table' => 'employees', 'where' => 'business_id', 'value' => $business->id],
            'warehouses' => ['table' => 'warehouses', 'where' => 'business_id', 'value' => $business->id],
            'points_of_sale' => ['table' => 'points_of_sale', 'where' => 'business_id', 'value' => $business->id],
            'cash_register_sessions' => ['table' => 'cash_register_sessions', 'where' => 'business_id', 'value' => $business->id],
            'contacts' => ['table' => 'contacts', 'where' => 'business_id', 'value' => $business->id],
            'products' => ['table' => 'products', 'where' => 'business_id', 'value' => $business->id],
            'stock_movements' => ['table' => 'stock_movements', 'where' => 'business_id', 'value' => $business->id],
            'stock_adjustments' => ['table' => 'stock_adjustments', 'where' => 'business_id', 'value' => $business->id],
            'product_losses' => ['table' => 'product_losses', 'where' => 'business_id', 'value' => $business->id],
            'product_breakdowns' => ['table' => 'product_breakdowns', 'where' => 'business_id', 'value' => $business->id],
            'sales' => ['table' => 'sales', 'where' => 'business_id', 'value' => $business->id],
            'purchases' => ['table' => 'purchases', 'where' => 'business_id', 'value' => $business->id],
            'expenses' => ['table' => 'expenses', 'where' => 'business_id', 'value' => $business->id],
        ];

        $candidates = [];

        foreach ($tables as $entityType => $info) {
            $rows = DB::table($info['table'])
                ->where($info['where'], $info['value'])
                ->whereNotNull('server_version')
                ->where('server_version', '>', $since)
                ->orderBy('server_version')
                ->limit($limit)
                ->get(['id', 'server_version']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'entity_type' => $entityType,
                    'id' => (int) $row->id,
                    'server_version' => (int) $row->server_version,
                ];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $a['server_version'] <=> $b['server_version']
        );

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Materializa los candidatos en payloads de cambio reusando los métodos
     * existentes `to*Payload`. El orden del array de salida respeta el de
     * `$candidates` (que ya viene ordenado por server_version).
     *
     * @param  array<int, array{entity_type: string, id: int, server_version: int}>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function materializeVersionedChanges(
        Business $business,
        array $candidates,
        CarbonInterface $responseBoundary
    ): array {
        if ($candidates === []) {
            return [];
        }

        $idsByType = [];
        foreach ($candidates as $candidate) {
            $idsByType[$candidate['entity_type']][] = $candidate['id'];
        }

        $rowsByType = [];
        foreach ($idsByType as $entityType => $ids) {
            $rowsByType[$entityType] = $this->loadEntitiesById($entityType, $business, $ids);
        }

        $changes = [];
        foreach ($candidates as $candidate) {
            $type = $candidate['entity_type'];
            $row = $rowsByType[$type][$candidate['id']] ?? null;
            if (! $row) {
                continue;
            }
            $change = $this->buildChangeForEntity($type, $row);
            if ($change === null) {
                continue;
            }
            $change['server_version'] = $candidate['server_version'];
            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, mixed>
     */
    private function loadEntitiesById(string $entityType, Business $business, array $ids): array
    {
        $modelClass = match ($entityType) {
            'business_profile' => Business::class,
            'categories' => Category::class,
            'units' => UnitOfMeasure::class,
            'employees' => Employee::class,
            'warehouses' => Warehouse::class,
            'points_of_sale' => PointOfSale::class,
            'cash_register_sessions' => CashRegisterSession::class,
            'contacts' => Contact::class,
            'products' => Product::class,
            'stock_movements' => StockMovement::class,
            'stock_adjustments' => StockAdjustment::class,
            'product_losses' => ProductLoss::class,
            'product_breakdowns' => ProductBreakdown::class,
            'sales' => Sale::class,
            'purchases' => Purchase::class,
            'expenses' => Expense::class,
            default => null,
        };

        if ($modelClass === null) {
            return [];
        }

        $query = $modelClass::query();
        if (method_exists($modelClass, 'bootSoftDeletes') || in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        if ($entityType === 'business_profile') {
            $query->where('id', $business->id);
        } else {
            $query->where('business_id', $business->id);
        }

        $rows = $query->whereIn('id', $ids)->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildChangeForEntity(string $entityType, $row): ?array
    {
        $occurredAtSource = $row->source_updated_at ?? $row->updated_at ?? $row->created_at;
        $occurredAt = $occurredAtSource?->toIso8601String();

        if ($entityType === 'business_profile') {
            /** @var Business $row */
            return [
                'event_id' => 'server:business_profile:'.$row->id.':'.$row->updated_at?->timestamp,
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => $occurredAt,
                'payload' => $this->toBusinessProfilePayload($row),
            ];
        }

        $isDeleted = isset($row->deleted_at) && $row->deleted_at !== null;
        $operation = $isDeleted ? 'delete' : 'upsert';

        $payload = match ($entityType) {
            'categories' => $this->toCategoryPayload($row),
            'units' => $this->toUnitPayload($row),
            'employees' => $this->toEmployeePayload($row),
            'warehouses' => $this->toWarehousePayload($row),
            'points_of_sale' => $this->toPointOfSalePayload($row),
            'cash_register_sessions' => $this->toCashRegisterSessionPayload($row),
            'contacts' => $this->toContactPayload($row),
            'products' => $this->toProductPayload($row),
            'stock_movements' => $this->toStockMovementPayload($row),
            'stock_adjustments' => $this->toStockAdjustmentPayload($row),
            'product_losses' => $this->toProductLossPayload($row),
            'product_breakdowns' => $this->toProductBreakdownPayload($row),
            'sales' => $this->toSalePayload($row),
            'purchases' => $this->toPurchasePayload($row),
            'expenses' => $this->toExpensePayload($row),
            default => null,
        };

        if ($payload === null) {
            return null;
        }

        return [
            'event_id' => $row->last_received_event_id ?? "server:{$entityType}:{$row->external_id}:{$row->updated_at?->timestamp}",
            'entity_type' => $entityType,
            'entity_id' => $entityType === 'business_profile' ? 'current_business' : $row->external_id,
            'operation' => $operation,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
        ];
    }

    /**
     * Versión simplificada de getOpenConflicts para el cursor v3 — devuelve
     * todos los abiertos sin filtrar por sub-cursores.
     */
    private function getOpenConflictsForCursor(Business $business): array
    {
        return SyncConflict::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
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
     * Pull the next page for a single entity_type using its own sub-cursor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchEntityChanges(
        string $entityType,
        Business $business,
        ?SyncCursor $cursor,
        CarbonInterface $responseBoundary,
        int $limit
    ): Collection {
        return match ($entityType) {
            'business_profile' => $this->getBusinessProfileChanges($business, $cursor, $responseBoundary),
            'license_catalog' => $this->getLicenseCatalogChanges($business, $cursor, $responseBoundary),
            'categories' => $this->getMaterializedEntityChanges(Category::class, 'categories', $business, $cursor, $responseBoundary, $limit, fn (Category $m) => $this->toCategoryPayload($m)),
            'units' => $this->getMaterializedEntityChanges(UnitOfMeasure::class, 'units', $business, $cursor, $responseBoundary, $limit, fn (UnitOfMeasure $m) => $this->toUnitPayload($m)),
            'employees' => $this->getMaterializedEntityChanges(Employee::class, 'employees', $business, $cursor, $responseBoundary, $limit, fn (Employee $m) => $this->toEmployeePayload($m)),
            'warehouses' => $this->getMaterializedEntityChanges(Warehouse::class, 'warehouses', $business, $cursor, $responseBoundary, $limit, fn (Warehouse $m) => $this->toWarehousePayload($m)),
            'points_of_sale' => $this->getMaterializedEntityChanges(PointOfSale::class, 'points_of_sale', $business, $cursor, $responseBoundary, $limit, fn (PointOfSale $m) => $this->toPointOfSalePayload($m)),
            'cash_register_sessions' => $this->getMaterializedEntityChanges(CashRegisterSession::class, 'cash_register_sessions', $business, $cursor, $responseBoundary, $limit, fn (CashRegisterSession $m) => $this->toCashRegisterSessionPayload($m)),
            'contacts' => $this->getMaterializedEntityChanges(Contact::class, 'contacts', $business, $cursor, $responseBoundary, $limit, fn (Contact $m) => $this->toContactPayload($m)),
            'products' => $this->getProductChanges($business, $cursor, $responseBoundary, $limit),
            'stock_movements' => $this->getMaterializedEntityChanges(StockMovement::class, 'stock_movements', $business, $cursor, $responseBoundary, $limit, fn (StockMovement $m) => $this->toStockMovementPayload($m)),
            'stock_adjustments' => $this->getMaterializedEntityChanges(StockAdjustment::class, 'stock_adjustments', $business, $cursor, $responseBoundary, $limit, fn (StockAdjustment $m) => $this->toStockAdjustmentPayload($m)),
            'product_losses' => $this->getMaterializedEntityChanges(ProductLoss::class, 'product_losses', $business, $cursor, $responseBoundary, $limit, fn (ProductLoss $m) => $this->toProductLossPayload($m)),
            'product_breakdowns' => $this->getMaterializedEntityChanges(ProductBreakdown::class, 'product_breakdowns', $business, $cursor, $responseBoundary, $limit, fn (ProductBreakdown $m) => $this->toProductBreakdownPayload($m)),
            'sales' => $this->getSaleChanges($business, $cursor, $responseBoundary, $limit),
            'purchases' => $this->getMaterializedEntityChanges(Purchase::class, 'purchases', $business, $cursor, $responseBoundary, $limit, fn (Purchase $m) => $this->toPurchasePayload($m)),
            'expenses' => $this->getMaterializedEntityChanges(Expense::class, 'expenses', $business, $cursor, $responseBoundary, $limit, fn (Expense $m) => $this->toExpensePayload($m)),
            default => collect(),
        };
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
     * Conflicts are lightweight and independent of the per-entity sub-cursors,
     * so we only use the minimum timestamp across the bundle as a floor to
     * avoid re-emitting already-seen conflicts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getOpenConflicts(Business $business, SyncEntityCursorBundle $bundle): array
    {
        $floor = null;
        foreach (self::ENTITY_ORDER as $entityType) {
            $cursor = $bundle->cursorFor($entityType);
            if ($cursor && $cursor->updatedAt) {
                if ($floor === null || $cursor->updatedAt->lessThan($floor)) {
                    $floor = $cursor->updatedAt;
                }
            }
        }

        return SyncConflict::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->when($floor, function ($query) use ($floor) {
                $query->where('updated_at', '>', $floor);
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
        // Marcamos el snapshot como seed para que un dispositivo recién
        // bootstrappeado pueda usar `stockByWarehouse` como punto de
        // partida. Los clientes existentes con stock local lo preservan;
        // sólo aplican el seed cuando todavía no tienen valores propios.
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
            'hasRecipe' => (bool) $product->has_recipe,
            'recipeItems' => $product->recipe_items ?? [],
            'canBreakdown' => (bool) $product->can_breakdown,
            'breakdownTargetProductId' => $product->breakdown_target_product_external_id,
            'breakdownTargetQuantity' => $product->breakdown_target_quantity !== null ? (float) $product->breakdown_target_quantity : null,
            'breakdownTargetTitleSnapshot' => $product->breakdown_target_title_snapshot,
            'breakdownTargetUnitSymbolSnapshot' => $product->breakdown_target_unit_symbol_snapshot,
            '_stockSeed' => true,
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
            'address' => [
                'country' => $business->country ?? 'CU',
                'province' => $business->province ?? '',
                'municipality' => $business->municipality ?? '',
                'street' => $business->street ?? (string) ($business->address ?? ''),
            ],
            'phone' => $business->phone,
            'defaultCurrency' => $business->default_currency ?? 'CUP',
            'licenseExpiresAt' => $business->license_expires_at?->toIso8601String(),
            'policies' => $business->policies ?? [],
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
            'address' => [
                'country' => $m->country ?? 'CU',
                'province' => $m->province ?? '',
                'municipality' => $m->municipality ?? '',
                'street' => $m->street ?? '',
            ],
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
            'master_device_id' => $m->master_device_id,
            'master_lease_expires_at' => $m->master_lease_expires_at?->toIso8601String(),
            'master_lease_ttl_seconds' => CashRegisterSession::MASTER_LEASE_TTL_SECONDS,
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

        $contactsByExternalId = $this->loadContactsForSales($business, $sales);

        return $sales->map(function (Sale $sale) use ($contactsByExternalId): array {
            $occurredAt = $sale->source_updated_at ?? $sale->updated_at ?? $sale->created_at;
            $linkedContact = $sale->contact_external_id
                ? ($contactsByExternalId[$sale->contact_external_id] ?? null)
                : null;

            return [
                'event_id' => $sale->last_received_event_id
                    ?? "server:sales:{$sale->external_id}:{$sale->updated_at?->timestamp}",
                'entity_type' => 'sales',
                'entity_id' => $sale->external_id,
                'operation' => $sale->deleted_at ? 'delete' : 'upsert',
                'occurred_at' => $occurredAt?->toIso8601String(),
                'cursor_at' => ($sale->updated_at ?? $occurredAt ?? $sale->created_at)?->toIso8601String(),
                'cursor_id' => $sale->id,
                'payload' => $this->toSalePayload($sale, $linkedContact),
            ];
        });
    }

    /**
     * Resolve customer snapshots for a batch of sales using the live contacts table
     * as the source of truth. The persisted snapshot on the sale is the fallback.
     *
     * @param  Collection<int, Sale>  $sales
     * @return array<string, Contact>
     */
    private function loadContactsForSales(Business $business, Collection $sales): array
    {
        $externalIds = $sales
            ->pluck('contact_external_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($externalIds)) {
            return [];
        }

        return Contact::query()
            ->where('business_id', $business->id)
            ->whereIn('external_id', $externalIds)
            ->get()
            ->keyBy('external_id')
            ->all();
    }

    /**
     * Build the snapshot the client uses to identify the debtor even when the
     * contact record itself has not been ingested locally yet. We prefer the
     * live contact data, then the snapshot persisted at push time.
     *
     * @return array<string, string|null>|null
     */
    private function buildContactSnapshotForSale(Sale $sale, ?Contact $linkedContact): ?array
    {
        if ($linkedContact instanceof Contact) {
            $name = trim((string) ($linkedContact->name ?? ''));
            $mobile = $this->trimOrNull($linkedContact->mobile);
            $idCard = $this->trimOrNull($linkedContact->id_card);

            if ($name !== '' || $mobile !== null || $idCard !== null) {
                return [
                    'name' => $name !== '' ? $name : null,
                    'mobile' => $mobile,
                    'idCard' => $idCard,
                ];
            }
        }

        $persisted = $sale->contact_snapshot;
        if (is_array($persisted)) {
            $hasAny = array_filter($persisted, static fn (mixed $value): bool => $value !== null && $value !== '');

            return $hasAny ? [
                'name' => $persisted['name'] ?? null,
                'mobile' => $persisted['mobile'] ?? null,
                'idCard' => $persisted['idCard'] ?? $persisted['id_card'] ?? null,
            ] : null;
        }

        return null;
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array<string, mixed> */
    private function toSalePayload(Sale $s, ?Contact $linkedContact = null): array
    {
        return [
            'type' => 'sale',
            'reference' => $s->reference,
            'contact' => $s->contact_external_id,
            'contactSnapshot' => $this->buildContactSnapshotForSale($s, $linkedContact),
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
            'creditBalance' => $s->credit_balance !== null ? (float) $s->credit_balance : null,
            'paymentBreakdown' => $s->payment_breakdown ?? [],
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

    /** @return array<string, mixed> */
    private function toStockMovementPayload(StockMovement $m): array
    {
        return [
            'productId' => $m->product_external_id,
            'fromWarehouseId' => $m->from_warehouse_external_id,
            'toWarehouseId' => $m->to_warehouse_external_id,
            'quantity' => (float) $m->quantity,
            'timestamp' => $m->movement_at?->toIso8601String(),
            'deleted_at' => $m->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toStockAdjustmentPayload(StockAdjustment $a): array
    {
        return [
            'productId' => $a->product_external_id,
            'warehouseId' => $a->warehouse_external_id,
            'quantity' => (float) $a->target_quantity,
            'changeQuantity' => (float) $a->change_quantity,
            'previousQuantity' => $a->previous_quantity !== null ? (float) $a->previous_quantity : null,
            'reason' => $a->reason,
            'timestamp' => $a->adjustment_at?->toIso8601String(),
            'deleted_at' => $a->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toProductLossPayload(ProductLoss $l): array
    {
        return [
            'productId' => $l->product_external_id,
            'warehouseId' => $l->warehouse_external_id,
            'quantity' => (float) $l->quantity,
            'lossType' => $l->loss_type,
            'notes' => $l->notes,
            'photo' => $l->photo,
            'unitCost' => $l->unit_cost !== null ? (float) $l->unit_cost : null,
            'previousQuantity' => $l->previous_quantity !== null ? (float) $l->previous_quantity : null,
            'timestamp' => $l->loss_at?->toIso8601String(),
            'deleted_at' => $l->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toProductBreakdownPayload(ProductBreakdown $b): array
    {
        return [
            'sourceProductId' => $b->source_product_external_id,
            'targetProductId' => $b->target_product_external_id,
            'warehouseId' => $b->warehouse_external_id,
            'sourceQuantity' => (float) $b->source_quantity,
            'targetQuantity' => (float) $b->target_quantity,
            'conversionRatio' => (float) $b->conversion_ratio,
            'sourceTitleSnapshot' => $b->source_title_snapshot,
            'targetTitleSnapshot' => $b->target_title_snapshot,
            'sourceUnitSymbolSnapshot' => $b->source_unit_symbol_snapshot,
            'targetUnitSymbolSnapshot' => $b->target_unit_symbol_snapshot,
            'sourceUnitCost' => $b->source_unit_cost !== null ? (float) $b->source_unit_cost : null,
            'targetUnitCost' => $b->target_unit_cost !== null ? (float) $b->target_unit_cost : null,
            'previousSourceQuantity' => $b->previous_source_quantity !== null ? (float) $b->previous_source_quantity : null,
            'previousTargetQuantity' => $b->previous_target_quantity !== null ? (float) $b->previous_target_quantity : null,
            'timestamp' => $b->breakdown_at?->toIso8601String(),
            'deleted_at' => $b->deleted_at?->toIso8601String(),
        ];
    }
}
