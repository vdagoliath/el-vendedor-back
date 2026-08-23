<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\PushSyncRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\PointOfSale;
use App\Models\Product;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncReceivedEvent;
use App\Support\Inventory\InventoryProjector;
use App\Support\Sync\BusinessPolicies;
use App\Support\Sync\ContactPayloadNormalizer;
use App\Support\Sync\SyncCompatibility;
use App\Support\Sync\SyncEntityApplier;
use App\Support\Sync\SyncTransactionApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SyncPushController extends Controller
{
    /**
     * Entity types that sellers are NOT allowed to push.
     * Anything not in this list is permitted for sellers too.
     */
    private const SELLER_FORBIDDEN_ENTITY_TYPES = [
        'purchases',
        'products',
        'marketplace_product_publications',
        'product_batches',
        'business_profile',
        'categories',
        'units',
        'warehouses',
        'points_of_sale',
        'employees',
        'expenses',
        'license_catalog',
        'license_quote',
        'cash_register_sessions',
        'product_breakdowns',
        'metrics_snapshots',
    ];

    public function __construct(
        private readonly ContactPayloadNormalizer $contactPayloadNormalizer,
        private readonly SyncCompatibility $syncCompatibility,
        private readonly SyncEntityApplier $entityApplier,
        private readonly SyncTransactionApplier $transactionApplier,
        private readonly InventoryProjector $inventoryProjector,
    ) {}

    /**
     * Receive a batch of client-side sync events.
     */
    public function store(PushSyncRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $dataResetFailure = $this->evaluateBusinessDataResetVersion($request, $business);
        if ($dataResetFailure !== null) {
            return response()->json($dataResetFailure, 409);
        }

        /** @var PersonalAccessToken|null $accessToken */
        $accessToken = $request->user()?->currentAccessToken();
        $ability = $this->resolveTokenAbility($accessToken);
        $employeeExternalId = $accessToken?->employee_external_id;

        // Defense-in-depth: token must be scoped to the same business.
        if ($accessToken && $accessToken->business_id !== null && $accessToken->business_id !== $business->id) {
            abort(403, 'El token no está autorizado para este negocio.');
        }

        $device = $this->upsertDevice($request, $business);

        $accepted = [];
        $duplicates = [];
        $rejected = [];

        foreach ($request->validated('changes') as $change) {
            $change = $this->normalizeChange($change);

            if (! $this->isEntityTypeAllowedForAbility($change['entity_type'], $ability)) {
                $rejected[] = [
                    'event_id' => $change['event_id'],
                    'entity_type' => $change['entity_type'],
                    'entity_id' => $change['entity_id'],
                    'status' => 'rejected',
                    'reason' => 'El tipo de entidad no está permitido para este dispositivo.',
                    'code' => 'sync_entity_forbidden',
                    'retryable' => false,
                ];

                continue;
            }

            if (! $this->isChangeAllowedForSellerScope($business, $change, $ability, $employeeExternalId)) {
                $rejected[] = [
                    'event_id' => $change['event_id'],
                    'entity_type' => $change['entity_type'],
                    'entity_id' => $change['entity_id'],
                    'status' => 'rejected',
                    'reason' => 'El movimiento no pertenece al almacén asignado a este vendedor.',
                    'code' => 'sync_seller_scope_forbidden',
                    'retryable' => false,
                ];

                continue;
            }

            $existingEvent = SyncReceivedEvent::query()
                ->where('business_id', $business->id)
                ->where('event_id', $change['event_id'])
                ->first();

            if ($existingEvent) {
                $duplicates[] = [
                    'event_id' => $change['event_id'],
                    'entity_type' => $existingEvent->entity_type,
                    'entity_id' => $existingEvent->entity_id,
                    'status' => $existingEvent->status,
                ];

                continue;
            }

            $event = SyncReceivedEvent::query()->create([
                'business_id' => $business->id,
                'user_id' => $request->user()?->id,
                'device_id' => $device->id,
                'employee_external_id' => $employeeExternalId,
                'token_ability' => $ability,
                'event_id' => $change['event_id'],
                'entity_type' => $change['entity_type'],
                'entity_id' => $change['entity_id'],
                'operation' => $change['operation'],
                'occurred_at' => $change['occurred_at'] ?? null,
                'payload' => $change['payload'] ?? null,
                'status' => 'pending_dispatch',
            ]);

            try {
                $status = $this->applyChange($business, $request->user(), $event, $change, $ability);

                $event->forceFill([
                    'status' => $status,
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();

                $accepted[] = [
                    'event_id' => $event->event_id,
                    'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id,
                    'status' => $event->status,
                ];
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'error_message' => $exception->getMessage(),
                ])->save();

                $conflictType = $this->recordConflict($business, $request, $device, $change, $exception);

                $rejected[] = [
                    'event_id' => $change['event_id'],
                    'entity_type' => $change['entity_type'],
                    'entity_id' => $change['entity_id'],
                    'status' => 'failed',
                    'reason' => $exception->getMessage(),
                    'code' => $this->resolveChangeErrorCode($exception, $conflictType),
                    'retryable' => $this->isRetryableChangeError($exception, $conflictType),
                    'conflict_type' => $conflictType,
                ];
            }
        }

        $cursor = now()->toIso8601String();

        SyncCheckpoint::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'device_id' => $device->id,
            ],
            [
                'user_id' => $request->user()?->id,
                'last_pushed_cursor' => $cursor,
                'last_pushed_at' => now(),
            ]
        );

        return response()->json([
            'cursor' => $cursor,
            'server_time' => $cursor,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'meta' => [
                'received_count' => count($request->validated('changes')),
                'accepted_count' => count($accepted),
                'duplicate_count' => count($duplicates),
                'rejected_count' => count($rejected),
                'device_id' => $device->id,
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluateBusinessDataResetVersion(PushSyncRequest $request, Business $business): ?array
    {
        $serverVersion = (int) $business->data_reset_version;
        $rawClientVersion = $request->header('X-Business-Data-Reset-Version');

        if ($rawClientVersion === null) {
            return null;
        }

        if ($serverVersion === 0 && $rawClientVersion === '0') {
            return null;
        }

        if (! is_numeric($rawClientVersion) || (int) $rawClientVersion !== $serverVersion) {
            return [
                'message' => 'El negocio fue limpiado en el servidor. Debes reiniciar los datos locales antes de volver a sincronizar.',
                'error' => [
                    'code' => 'business_data_reset_required',
                    'retryable' => false,
                ],
                'current_business' => [
                    'id' => $business->id,
                    'data_reset_version' => $serverVersion,
                    'data_reset_at' => $business->data_reset_at?->toIso8601String(),
                ],
            ];
        }

        return null;
    }

    /**
     * Returns 'sync:owner', 'sync:seller' or null if token has no known sync ability.
     */
    private function resolveTokenAbility(?PersonalAccessToken $token): ?string
    {
        if (! $token) {
            return null;
        }

        foreach (['sync:owner', 'sync:seller'] as $candidate) {
            if ($token->can($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isEntityTypeAllowedForAbility(string $entityType, ?string $ability): bool
    {
        // Owner and legacy unscoped tokens can push any entity type.
        if ($ability === null || $ability === 'sync:owner') {
            return true;
        }

        if ($ability === 'sync:seller') {
            return ! in_array($entityType, self::SELLER_FORBIDDEN_ENTITY_TYPES, true);
        }

        return false;
    }


    private function isChangeAllowedForSellerScope(Business $business, array $change, ?string $ability, ?string $employeeExternalId): bool
    {
        if ($ability !== 'sync:seller') {
            return true;
        }

        $warehouseIds = $this->warehouseIdsFromSellerChange($change);
        if ($warehouseIds === []) {
            return true;
        }

        if (! is_string($employeeExternalId) || trim($employeeExternalId) === '') {
            return false;
        }

        $assignedWarehouses = $this->warehouseIdsForSeller($business, $employeeExternalId);
        if ($assignedWarehouses === []) {
            return false;
        }

        foreach ($warehouseIds as $warehouseId) {
            if (! in_array($warehouseId, $assignedWarehouses, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function warehouseIdsFromSellerChange(array $change): array
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $warehouseIds = match ($change['entity_type']) {
            'sales', 'stock_adjustments', 'product_losses', 'weight_journals' => [
                $payload['warehouseId'] ?? null,
            ],
            'stock_movements' => [
                $payload['fromWarehouseId'] ?? null,
                $payload['toWarehouseId'] ?? null,
            ],
            default => [],
        };

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            $warehouseIds
        ))));
    }

    /**
     * @return list<string>
     */
    private function warehouseIdsForSeller(Business $business, string $employeeExternalId): array
    {
        $pointsOfSale = PointOfSale::query()
            ->where('business_id', $business->id)
            ->whereNotNull('warehouse_external_id')
            ->get(['warehouse_external_id', 'employees']);

        $warehouseIds = [];
        foreach ($pointsOfSale as $pointOfSale) {
            $employees = is_array($pointOfSale->employees) ? $pointOfSale->employees : [];
            foreach ($employees as $employee) {
                $id = is_array($employee) ? ($employee['_id'] ?? $employee['id'] ?? null) : null;
                if ($id === $employeeExternalId) {
                    $warehouseIds[] = (string) $pointOfSale->warehouse_external_id;
                    break;
                }
            }
        }

        return array_values(array_unique($warehouseIds));
    }

    /**
     * Register or refresh the pushing device.
     */
    private function upsertDevice(PushSyncRequest $request, Business $business): Device
    {
        /** @var array{id:string,name:?string,platform:?string,app_version:?string} $devicePayload */
        $devicePayload = $request->validated('device');

        $device = Device::query()->firstOrNew(['id' => $devicePayload['id']]);
        $existingMeta = is_array($device->meta) ? $device->meta : [];

        $device->fill([
            'business_id' => $business->id,
            'user_id' => $request->user()?->id,
            'name' => $devicePayload['name'],
            'platform' => $devicePayload['platform'],
            'app_version' => $this->syncCompatibility->clientAppVersion($request) ?? $devicePayload['app_version'],
            'is_active' => true,
            'last_seen_at' => now(),
            'last_synced_at' => now(),
            'meta' => array_filter(array_merge($existingMeta, [
                'sync_version' => $this->syncCompatibility->clientSyncVersion($request),
                'last_sync_stage' => 'push',
            ]), static fn (mixed $value): bool => $value !== null),
        ]);

        $device->save();

        return $device;
    }

    /**
     * Normalize change aliases and payloads before they are persisted.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function normalizeChange(array $change): array
    {
        $originalEntityType = (string) ($change['entity_type'] ?? '');
        $change['entity_type'] = $this->contactPayloadNormalizer->normalizeEntityType(
            $originalEntityType
        );

        if (
            $change['entity_type'] === 'contacts'
            && is_array($change['payload'] ?? null)
        ) {
            $change['payload'] = $this->contactPayloadNormalizer->normalizePayload($change['payload']);

            if (! isset($change['payload']['type'])) {
                $inferredType = $this->contactPayloadNormalizer->inferTypeFromEntityType($originalEntityType);

                if ($inferredType !== null) {
                    $change['payload']['type'] = $inferredType;
                }
            }
        }

        if (is_array($change['payload'] ?? null)) {
            $change['payload'] = $this->sanitizeMediaPayload($change['entity_type'], $change['payload']);
        }

        return $change;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeMediaPayload(string $entityType, array $payload): array
    {
        $mediaKeys = match ($entityType) {
            'products' => [
                'photos',
                'featuredPhoto',
                'photo',
                'image',
                'images',
                'base64String',
                'dataUrl',
                'webPath',
                'filepath',
                'path',
            ],
            'business_profile' => [
                'businessPhoto',
                'business_photo',
                'businessPhotoRemoved',
                'photo',
                'image',
                'logo',
                'base64String',
                'dataUrl',
                'webPath',
                'filepath',
                'path',
            ],
            default => [],
        };

        foreach ($mediaKeys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * Apply a supported domain change.
     *
     * @param  array<string, mixed>  $change
     */
    private function applyChange(Business $business, mixed $user, SyncReceivedEvent $event, array $change, ?string $ability = null): string
    {
        if ($change['entity_type'] === 'contacts') {
            $this->assertContactPermission($business, $user, $change, $ability);

            return 'applied';
        }

        if ($change['entity_type'] === 'products') {
            $this->applyProductChange($business, $event, $change);

            return 'applied';
        }

        if ($change['entity_type'] === 'business_profile') {
            $this->applyBusinessProfileChange($business, $event, $change);

            return 'applied';
        }

        // Materializar entidades simples en tablas dedicadas
        if ($this->entityApplier->apply($business, $event, $change)) {
            return 'applied';
        }

        // Materializar transacciones (sales, purchases, expenses)
        if ($this->transactionApplier->apply($business, $event, $change)) {
            return 'applied';
        }

        return 'pending_dispatch';
    }

    /**
     * Ensure the current user can sync the requested contact type.
     *
     * @param  array<string, mixed>  $change
     */
    private function assertContactPermission(Business $business, mixed $user, array $change, ?string $ability = null): void
    {
        $contactType = null;

        if (is_array($change['payload'] ?? null)) {
            $contactType = $change['payload']['type'] ?? null;
        }

        $normalizedType = is_string($contactType) ? strtolower(trim($contactType)) : null;

        // Sellers may only sync customer contacts; the token ability enforces this
        // regardless of the owning user's permissions.
        if ($ability === 'sync:seller' && $normalizedType !== 'customer') {
            throw new \RuntimeException('Tu rol actual no puede gestionar proveedores.');
        }

        if ($ability === null && ! $user?->canManageContactTypeInBusiness($business, $normalizedType)) {
            throw new \RuntimeException('Tu rol actual no puede gestionar proveedores.');
        }
    }

    /**
     * Apply a product create, update, upsert or delete.
     *
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

        $this->assertProductCodeIsAvailable($business, $change['entity_id'], $code);

        if (! $product) {
            $product = new Product([
                'business_id' => $business->id,
                'external_id' => $change['entity_id'],
            ]);
        }

        // El stock se sincroniza por eventos discretos (sales, purchases,
        // stock_movements, stock_adjustments, product_breakdowns). Solo aceptamos el snapshot
        // `stockByWarehouse` cuando el cliente lo marca explícitamente como
        // seed inicial (`_stockSeed: true`). Para todos los demás updates
        // del producto preservamos el stock existente; así un rename o
        // cambio de precio no clobbea el stock de otros dispositivos.
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
            'regular_price' => $this->normalizeDecimal($payload['regular_price'] ?? 0),
            'purchase_price' => $this->normalizeDecimal($payload['purchase_price'] ?? 0),
            'prices_by_currency' => is_array($payload['pricesByCurrency'] ?? null) ? $payload['pricesByCurrency'] : null,
            'barcode_type' => $this->normalizeNullableString($payload['barcodeType'] ?? null),
            'min_stock' => $this->normalizeNullableDecimal($payload['min_stock'] ?? null),
            'category_external_id' => $this->normalizeNullableString($payload['categoryId'] ?? null),
            'unit_of_measurement' => is_array($payload['unitOfMeasurement'] ?? null) ? $payload['unitOfMeasurement'] : null,
            'unit_of_measurement_purchase' => is_array($payload['unitOfMeasurementPurchase'] ?? null) ? $payload['unitOfMeasurementPurchase'] : null,
            'stock_by_warehouse' => $stockByWarehouse,
            'has_recipe' => (bool) ($payload['hasRecipe'] ?? false),
            'recipe_items' => is_array($payload['recipeItems'] ?? null) ? $payload['recipeItems'] : [],
            'can_breakdown' => (bool) ($payload['canBreakdown'] ?? false),
            'breakdown_target_product_external_id' => $this->normalizeNullableString($payload['breakdownTargetProductId'] ?? null),
            'breakdown_target_quantity' => $this->normalizeNullableDecimal($payload['breakdownTargetQuantity'] ?? null),
            'breakdown_target_title_snapshot' => $this->normalizeNullableString($payload['breakdownTargetTitleSnapshot'] ?? null),
            'breakdown_target_unit_symbol_snapshot' => $this->normalizeNullableString($payload['breakdownTargetUnitSymbolSnapshot'] ?? null),
            'source_created_at' => $product->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $product->trashed();

        $product->save();

        if ($wasTrashed) {
            $product->restore();
        }

        // Si el push trajo seed, replicamos el snapshot a la proyección.
        // Reemplazamos los almacenes que aparecen en el payload; los que
        // no aparecen se dejan tal cual (un seed parcial no borra otros
        // almacenes).
        if ($isStockSeed && is_array($payload['stockByWarehouse'] ?? null)) {
            $this->inventoryProjector->setSeedMany(
                $business,
                $product->external_id,
                $payload['stockByWarehouse'],
                $event->event_id
            );
        }
    }

    /**
     * Apply business profile changes pushed from a device.
     *
     * @param  array<string, mixed>  $change
     */
    private function applyBusinessProfileChange(Business $business, SyncReceivedEvent $event, array $change): void
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        // For nullable text fields we treat empty/missing values as
        // "preserve existing" rather than "clear". This prevents a fresh
        // device (e.g. a newly added co-owner) from clobbering the
        // address/phone of the business with empty strings on its first
        // bootstrap push.
        $addressPayload = is_array($payload['address'] ?? null) ? $payload['address'] : null;
        $legacyAddressString = is_string($payload['address'] ?? null) ? $payload['address'] : null;
        $businessPhotoRemoved = ($payload['businessPhotoRemoved'] ?? false) === true;
        $businessPhoto = ! $businessPhotoRemoved && array_key_exists('businessPhoto', $payload)
            ? $this->storeBusinessPhoto($business, $payload['businessPhoto'])
            : null;

        $business->fill([
            'name' => $this->normalizeNullableString($payload['businessName'] ?? null) ?: $business->name,
            'photo' => $businessPhotoRemoved
                ? null
                : ($businessPhoto ?? $business->photo),
            'address' => $this->normalizeNullableString($legacyAddressString) ?? $business->address,
            'country' => $addressPayload !== null
                ? ($this->normalizeNullableString($addressPayload['country'] ?? null) ?? $business->country)
                : $business->country,
            'province' => $addressPayload !== null
                ? ($this->normalizeNullableString($addressPayload['province'] ?? null) ?? $business->province)
                : $business->province,
            'municipality' => $addressPayload !== null
                ? ($this->normalizeNullableString($addressPayload['municipality'] ?? null) ?? $business->municipality)
                : $business->municipality,
            'street' => $addressPayload !== null
                ? ($this->normalizeNullableString($addressPayload['street'] ?? null) ?? $business->street)
                : $business->street,
            'phone' => $this->normalizeNullableString($payload['phone'] ?? null) ?? $business->phone,
            'default_currency' => $this->normalizeNullableString($payload['defaultCurrency'] ?? null) ?: ($business->default_currency ?? 'CUP'),
            'license_expires_at' => $this->normalizeNullableString($payload['licenseExpiresAt'] ?? null) ?? $business->license_expires_at,
        ]);

        if (is_array($payload['policies'] ?? null)) {
            $business->policies = array_merge(
                BusinessPolicies::normalize((array) ($business->policies ?? [])),
                BusinessPolicies::normalize($payload['policies'])
            );
        }

        $business->touch();
        $business->save();
    }

    private function storeBusinessPhoto(Business $business, mixed $photo): ?string
    {
        $rawPhoto = $this->normalizeNullableString($photo);
        if ($rawPhoto === null) {
            return null;
        }

        if (preg_match('/^(https?:)?\/\//i', $rawPhoto) === 1 || str_starts_with($rawPhoto, '/storage/')) {
            return $rawPhoto;
        }

        $contentType = 'image/jpeg';
        $rawData = $rawPhoto;

        if (preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/i', $rawPhoto, $matches) === 1) {
            $contentType = strtolower($matches[1]);
            $rawData = $matches[2];
        } elseif (str_contains($rawPhoto, ',')) {
            $rawData = substr($rawPhoto, strpos($rawPhoto, ',') + 1);
        }

        $binary = base64_decode($rawData, true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $extension = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = sprintf(
            'marketplace/business-%s/profile/%s.%s',
            $business->id,
            Str::ulid(),
            $extension,
        );

        Storage::disk('public')->put($path, $binary);

        return Storage::disk('public')->url($path);
    }

    private function assertProductCodeIsAvailable(Business $business, string $externalId, string $code): void
    {
        $duplicated = Product::query()
            ->where('business_id', $business->id)
            ->where('code', $code)
            ->where('external_id', '!=', $externalId)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicated) {
            throw new \RuntimeException('Ya existe otro producto activo con el codigo '.$code.'.');
        }
    }

    /**
     * Register an open sync conflict when a change could not be applied.
     *
     * @param  array<string, mixed>  $change
     */
    private function recordConflict(
        Business $business,
        PushSyncRequest $request,
        Device $device,
        array $change,
        Throwable $exception
    ): ?string {
        if ($change['entity_type'] !== 'products') {
            return null;
        }

        $remotePayload = null;

        $incomingCode = trim((string) (($change['payload']['code'] ?? null) ?: ''));
        if ($incomingCode !== '') {
            $remoteProduct = Product::query()
                ->where('business_id', $business->id)
                ->where('code', $incomingCode)
                ->where('external_id', '!=', $change['entity_id'])
                ->first();

            if ($remoteProduct) {
                $remotePayload = $this->toProductPayload($remoteProduct);
            }
        }

        $conflictType = $remotePayload ? 'duplicate_code' : 'apply_error';

        SyncConflict::query()->create([
            'business_id' => $business->id,
            'user_id' => $request->user()?->id,
            'device_id' => $device->id,
            'event_id' => $change['event_id'],
            'entity_type' => $change['entity_type'],
            'entity_id' => $change['entity_id'],
            'conflict_type' => $conflictType,
            'local_payload' => is_array($change['payload'] ?? null) ? $change['payload'] : null,
            'remote_payload' => $remotePayload,
            'status' => 'open',
        ]);

        return $conflictType;
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

    private function normalizeDecimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function normalizeNullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveChangeErrorCode(Throwable $exception, ?string $conflictType): string
    {
        $message = strtolower(trim($exception->getMessage()));

        if ($conflictType === 'duplicate_code') {
            return 'sync_duplicate_product_code';
        }

        if (str_contains($message, 'no puede gestionar')) {
            return 'sync_permission_denied';
        }

        if (str_contains($message, 'no tiene codigo') || str_contains($message, 'no tiene nombre')) {
            return 'sync_invalid_payload';
        }

        return 'sync_apply_error';
    }

    private function isRetryableChangeError(Throwable $exception, ?string $conflictType): bool
    {
        if ($conflictType === 'duplicate_code') {
            return false;
        }

        $message = strtolower(trim($exception->getMessage()));

        if (str_contains($message, 'no puede gestionar') || str_contains($message, 'no tiene codigo') || str_contains($message, 'no tiene nombre')) {
            return false;
        }

        return true;
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
            'pricesByCurrency' => $product->prices_by_currency ?? null,
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
            'deleted_at' => $product->deleted_at?->toIso8601String(),
        ];
    }
}
