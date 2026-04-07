<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\PushSyncRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncReceivedEvent;
use App\Support\Sync\ContactPayloadNormalizer;
use App\Support\Sync\SyncCompatibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Throwable;

class SyncPushController extends Controller
{
    public function __construct(
        private readonly ContactPayloadNormalizer $contactPayloadNormalizer,
        private readonly SyncCompatibility $syncCompatibility
    ) {}

    /**
     * Receive a batch of client-side sync events.
     */
    public function store(PushSyncRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $device = $this->upsertDevice($request, $business);

        $accepted = [];
        $duplicates = [];
        $rejected = [];

        foreach ($request->validated('changes') as $change) {
            $change = $this->normalizeChange($change);

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
                'event_id' => $change['event_id'],
                'entity_type' => $change['entity_type'],
                'entity_id' => $change['entity_id'],
                'operation' => $change['operation'],
                'occurred_at' => $change['occurred_at'] ?? null,
                'payload' => $change['payload'] ?? null,
                'status' => 'pending_dispatch',
            ]);

            try {
                $status = $this->applyChange($business, $request->user(), $event, $change);

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

        return $change;
    }

    /**
     * Apply a supported domain change.
     *
     * @param  array<string, mixed>  $change
     */
    private function applyChange(Business $business, mixed $user, SyncReceivedEvent $event, array $change): string
    {
        if ($change['entity_type'] === 'contacts') {
            $this->assertContactPermission($business, $user, $change);

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

        if (in_array($change['entity_type'], ['categories', 'employees', 'units', 'warehouses', 'points_of_sale', 'sales', 'purchases', 'expenses'], true)) {
            return 'applied';
        }

        return 'pending_dispatch';
    }

    /**
     * Ensure the current user can sync the requested contact type.
     *
     * @param  array<string, mixed>  $change
     */
    private function assertContactPermission(Business $business, mixed $user, array $change): void
    {
        $contactType = null;

        if (is_array($change['payload'] ?? null)) {
            $contactType = $change['payload']['type'] ?? null;
        }

        $normalizedType = is_string($contactType) ? strtolower(trim($contactType)) : null;

        if (! $user?->canManageContactTypeInBusiness($business, $normalizedType)) {
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

        $product->fill([
            'business_id' => $business->id,
            'external_id' => $change['entity_id'],
            'code' => $code,
            'title' => $title,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'type' => trim((string) ($payload['type'] ?? 'product')) ?: 'product',
            'regular_price' => $this->normalizeDecimal($payload['regular_price'] ?? 0),
            'purchase_price' => $this->normalizeDecimal($payload['purchase_price'] ?? 0),
            'barcode_type' => $this->normalizeNullableString($payload['barcodeType'] ?? null),
            'min_stock' => $this->normalizeNullableDecimal($payload['min_stock'] ?? null),
            'category_external_id' => $this->normalizeNullableString($payload['categoryId'] ?? null),
            'unit_of_measurement' => is_array($payload['unitOfMeasurement'] ?? null) ? $payload['unitOfMeasurement'] : null,
            'unit_of_measurement_purchase' => is_array($payload['unitOfMeasurementPurchase'] ?? null) ? $payload['unitOfMeasurementPurchase'] : null,
            'stock_by_warehouse' => is_array($payload['stockByWarehouse'] ?? null) ? $payload['stockByWarehouse'] : [],
            'source_created_at' => $product->source_created_at ?? $occurredAt ?? now(),
            'source_updated_at' => $occurredAt ?? now(),
            'last_received_event_id' => $event->event_id,
        ]);

        $wasTrashed = $product->trashed();

        $product->save();

        if ($wasTrashed) {
            $product->restore();
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

        $business->fill([
            'name' => $this->normalizeNullableString($payload['businessName'] ?? null) ?: $business->name,
            'address' => $this->normalizeNullableString($payload['address'] ?? null),
            'phone' => $this->normalizeNullableString($payload['phone'] ?? null),
            'default_currency' => $this->normalizeNullableString($payload['defaultCurrency'] ?? null) ?: ($business->default_currency ?? 'CUP'),
            'license_expires_at' => $this->normalizeNullableString($payload['licenseExpiresAt'] ?? null),
        ]);

        $business->touch();
        $business->save();
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
            'barcodeType' => $product->barcode_type,
            'min_stock' => $product->min_stock !== null ? (float) $product->min_stock : null,
            'categoryId' => $product->category_external_id,
            'unitOfMeasurement' => $product->unit_of_measurement,
            'unitOfMeasurementPurchase' => $product->unit_of_measurement_purchase,
            'stockByWarehouse' => $product->stock_by_warehouse ?? [],
            'deleted_at' => $product->deleted_at?->toIso8601String(),
        ];
    }
}
