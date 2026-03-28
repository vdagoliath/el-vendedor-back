<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use App\Models\SyncReceivedEvent;
use App\Support\Sync\ContactPayloadNormalizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CurrentBusinessSyncStore
{
    public function __construct(
        private readonly ContactPayloadNormalizer $contactPayloadNormalizer
    ) {}

    /**
     * Resolve the latest synced payload for each entity of the given type.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function latestPayloads(Business $business, string $entityType): Collection
    {
        $queryableEntityTypes = $this->contactPayloadNormalizer->queryEntityTypes($entityType);
        $latestIds = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('entity_type', $queryableEntityTypes)
            ->where('status', 'applied')
            ->selectRaw('MAX(id) as id')
            ->groupBy('entity_id');

        return SyncReceivedEvent::query()
            ->whereIn('id', $latestIds)
            ->orderBy('updated_at')
            ->get()
            ->filter(fn (SyncReceivedEvent $event): bool => $event->operation !== 'delete' && is_array($event->payload))
            ->map(function (SyncReceivedEvent $event) use ($entityType): array {
                $payload = $event->payload ?? [];

                if ($this->contactPayloadNormalizer->normalizeEntityType($entityType) === 'contacts') {
                    $payload = $this->contactPayloadNormalizer->normalizePayload($payload);
                }

                $payload['_entity_id'] = $event->entity_id;
                $payload['_event_id'] = $event->event_id;
                $payload['_operation'] = $event->operation;
                $payload['_updated_at'] = $event->updated_at?->toIso8601String();

                return $payload;
            })
            ->values();
    }

    /**
     * Resolve the latest synced payloads keyed by entity id.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function latestPayloadMap(Business $business, string $entityType): Collection
    {
        return $this->latestPayloads($business, $entityType)
            ->keyBy(fn (array $payload): string => (string) ($payload['_entity_id'] ?? ''));
    }

    /**
     * Append a server-side sync event so devices can pull the change.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function appendServerEvent(
        Business $business,
        ?Authenticatable $user,
        string $entityType,
        string $entityId,
        string $operation,
        ?array $payload = null,
        ?Carbon $occurredAt = null
    ): SyncReceivedEvent {
        return SyncReceivedEvent::query()->create([
            'business_id' => $business->id,
            'user_id' => $user?->getAuthIdentifier(),
            'device_id' => null,
            'event_id' => sprintf(
                'server:%s:%s:%s:%s',
                $entityType,
                $operation,
                $entityId,
                (string) Str::uuid()
            ),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'occurred_at' => $occurredAt ?? now(),
            'payload' => $payload,
            'status' => 'applied',
            'error_message' => null,
            'processed_at' => now(),
        ]);
    }
}
