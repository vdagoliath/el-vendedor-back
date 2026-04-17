<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SyncConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncConflictController extends Controller
{
    /**
     * Mark a conflict as resolved with the given resolution strategy.
     * Any sync-authorized device can resolve a conflict of its current business.
     */
    public function resolve(Request $request, int $conflict): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');
        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        $validated = $request->validate([
            'resolution' => ['required', 'string', Rule::in(['keep_remote', 'discard_local', 'reviewed'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var SyncConflict|null $record */
        $record = SyncConflict::query()
            ->where('business_id', $business->id)
            ->where('id', $conflict)
            ->first();

        if (! $record) {
            abort(404, 'Conflicto no encontrado.');
        }

        $record->forceFill([
            'status' => 'resolved',
            'resolution_notes' => trim(($validated['resolution']).($validated['notes'] ?? '' ? ' — '.$validated['notes'] : '')),
            'resolved_at' => now(),
        ])->save();

        return response()->json([
            'id' => $record->id,
            'status' => $record->status,
            'resolution_notes' => $record->resolution_notes,
            'resolved_at' => $record->resolved_at?->toIso8601String(),
        ]);
    }
}
