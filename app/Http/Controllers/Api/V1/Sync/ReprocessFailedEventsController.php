<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\ReprocessFailedEventsRequest;
use App\Models\Business;
use App\Models\SyncReceivedEvent;
use App\Support\Sync\SyncEventReprocessor;
use Illuminate\Http\JsonResponse;

class ReprocessFailedEventsController extends Controller
{
    public function __construct(
        private readonly SyncEventReprocessor $reprocessor
    ) {}

    /**
     * Replay `sync_received_events` rows that are stuck in `failed` status.
     *
     * Typical use case: a push session was interrupted mid-batch and part of
     * the payload never materialized in the destination tables. Without this
     * endpoint the client had to manually re-enqueue every pending change.
     */
    public function store(ReprocessFailedEventsRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');
        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $query = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('status', 'failed');

        $entityTypes = $request->entityTypes();
        if (! empty($entityTypes)) {
            $query->whereIn('entity_type', $entityTypes);
        }

        $eventIds = $request->eventIds();
        if (! empty($eventIds)) {
            $query->whereIn('event_id', $eventIds);
        }

        $events = $query
            ->orderBy('id')
            ->limit($request->resolvedLimit())
            ->get();

        $applied = 0;
        $stillFailed = 0;
        $perEntity = [];
        $reasons = [];

        foreach ($events as $event) {
            $status = $this->reprocessor->reprocess($business, $event);
            $entity = $event->entity_type;
            $perEntity[$entity] ??= ['attempted' => 0, 'applied' => 0, 'failed' => 0];
            $perEntity[$entity]['attempted']++;

            if ($status === 'applied') {
                $applied++;
                $perEntity[$entity]['applied']++;

                continue;
            }

            $stillFailed++;
            $perEntity[$entity]['failed']++;

            if ($event->error_message) {
                $reasons[] = [
                    'event_id' => $event->event_id,
                    'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id,
                    'error' => $event->error_message,
                ];
            }
        }

        // Devolvemos, además, el stock restante de eventos fallidos para que el
        // caller sepa si necesita invocar el endpoint de nuevo.
        $remainingFailed = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('status', 'failed')
            ->count();

        return response()->json([
            'attempted' => $events->count(),
            'applied' => $applied,
            'still_failed' => $stillFailed,
            'remaining_failed' => $remainingFailed,
            'per_entity' => $perEntity,
            'reasons' => array_slice($reasons, 0, 50),
        ]);
    }

    /**
     * Return a summary of how many failed events exist without replaying anything.
     */
    public function summary(ReprocessFailedEventsRequest $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');
        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $counts = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('status', 'failed')
            ->selectRaw('entity_type, COUNT(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type')
            ->all();

        return response()->json([
            'total_failed' => array_sum($counts),
            'per_entity' => $counts,
        ]);
    }
}
