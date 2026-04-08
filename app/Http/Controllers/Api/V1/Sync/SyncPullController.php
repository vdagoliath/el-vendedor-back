<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\PullSyncRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncReceivedEvent;
use App\Support\Licensing\BusinessLicensePricingResolver;
use App\Support\Sync\ContactPayloadNormalizer;
use App\Support\Sync\SyncCompatibility;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

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
        $requestedCursor = $this->parseCursor($request->string('cursor')->toString() ?: null);
        $responseBoundary = now();
        $limit = (int) ($request->integer('limit') ?: 500);
        $changes = $this->getBusinessProfileChanges($business, $requestedCursor, $responseBoundary)
            ->concat($this->getLicenseCatalogChanges($business, $requestedCursor, $responseBoundary))
            ->concat($this->getLicenseQuoteChanges($business, $responseBoundary))
            ->concat($this->getEventChanges($business, $requestedCursor, $responseBoundary, $limit))
            ->concat($this->getProductChanges($business, $requestedCursor, $responseBoundary, $limit))
            ->sortBy([
                ['cursor_at', 'asc'],
                ['entity_type', 'asc'],
                ['entity_id', 'asc'],
                ['event_id', 'asc'],
            ])
            ->take($limit + 1)
            ->values();
        $hasMore = $changes->count() > $limit;
        $visibleChanges = $hasMore ? $changes->take($limit)->values() : $changes->values();
        $cursor = $hasMore
            ? ($visibleChanges->last()['cursor_at'] ?? $responseBoundary->toIso8601String())
            : $responseBoundary->toIso8601String();
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
                'requested_cursor' => $requestedCursor?->toIso8601String(),
                'device_id' => $device->id,
                'applied_count' => $visibleChanges->count(),
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Return incremental transaction deltas recorded via sync events.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getEventChanges(
        Business $business,
        ?Carbon $requestedCursor,
        CarbonInterface $responseBoundary,
        int $limit
    ): Collection {
        $events = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('entity_type', ['categories', 'contacts', 'providers', 'suppliers', 'vendors', 'customers', 'clients', 'employees', 'units', 'warehouses', 'points_of_sale', 'sales', 'purchases', 'expenses'])
            ->where('status', 'applied')
            ->where('updated_at', '<=', $responseBoundary)
            ->when($requestedCursor, function ($query) use ($requestedCursor) {
                $query->where('updated_at', '>', $requestedCursor);
            })
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return $events->map(function (SyncReceivedEvent $event): array {
            $entityType = $this->contactPayloadNormalizer->normalizeEntityType($event->entity_type);
            $payload = $event->payload ?? [];

            if ($entityType === 'contacts' && is_array($payload)) {
                $payload = $this->contactPayloadNormalizer->normalizePayload($payload);
            }

            return [
                'event_id' => $event->event_id,
                'entity_type' => $entityType,
                'entity_id' => $event->entity_id,
                'operation' => $event->operation,
                'occurred_at' => ($event->occurred_at ?? $event->processed_at ?? $event->updated_at)?->toIso8601String(),
                'cursor_at' => ($event->updated_at ?? $event->processed_at ?? $event->occurred_at)?->toIso8601String(),
                'payload' => $payload,
            ];
        });
    }

    /**
     * Return the current business profile as a sync change when needed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getBusinessProfileChanges(
        Business $business,
        ?Carbon $requestedCursor,
        CarbonInterface $responseBoundary
    ): Collection {
        if ($business->updated_at && $business->updated_at->greaterThan($responseBoundary)) {
            return collect();
        }

        if ($requestedCursor && $business->updated_at && $business->updated_at->lessThanOrEqualTo($requestedCursor)) {
            return collect();
        }

        return collect([
            [
                'event_id' => 'server:business_profile:'.$business->id.':'.$business->updated_at?->timestamp,
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => ($business->updated_at ?? $business->created_at ?? now())?->toIso8601String(),
                'cursor_at' => ($business->updated_at ?? $business->created_at ?? now())?->toIso8601String(),
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
        ?Carbon $requestedCursor,
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

        if ($requestedCursor && $updatedAt->lessThanOrEqualTo($requestedCursor)) {
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

    private function parseCursor(?string $cursor): ?Carbon
    {
        if (! is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        try {
            return Carbon::parse($cursor);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Return incremental product deltas for the current business.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getProductChanges(
        Business $business,
        ?Carbon $requestedCursor,
        CarbonInterface $responseBoundary,
        int $limit
    ): Collection {
        $products = Product::query()
            ->withTrashed()
            ->where('business_id', $business->id)
            ->where('updated_at', '<=', $responseBoundary)
            ->when($requestedCursor, function ($query) use ($requestedCursor) {
                $query->where('updated_at', '>', $requestedCursor);
            })
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
        unset($change['cursor_at']);

        return $change;
    }

    /**
     * Return unresolved conflicts visible to the client.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getOpenConflicts(Business $business, ?Carbon $requestedCursor): array
    {
        return SyncConflict::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->when($requestedCursor, function ($query) use ($requestedCursor) {
                $query->where('updated_at', '>', $requestedCursor);
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
}
