<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncBootstrapController extends Controller
{
    /**
     * Return the bootstrap contract for a device entering sync.
     */
    public function show(Request $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'sync_version' => 1,
            'current_business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'address' => $business->address,
                'phone' => $business->phone,
                'default_currency' => $business->default_currency ?? 'CUP',
                'license_expires_at' => $business->license_expires_at?->toIso8601String(),
            ],
            'capabilities' => [
                'push' => true,
                'pull' => true,
                'conflicts' => true,
                'inventory_events' => true,
            ],
            'supported_entities' => [
                'business_profile',
                'products',
                'categories',
                'contacts',
                'employees',
                'units',
                'warehouses',
                'points_of_sale',
                'employees',
                'sales',
                'purchases',
                'expenses',
                'inventory_events',
            ],
            'push_contract' => [
                'max_batch_size' => 1000,
                'operations' => ['create', 'update', 'delete', 'upsert'],
            ],
            'pull_contract' => [
                'cursor_type' => 'iso8601',
                'default_limit' => 500,
            ],
        ]);
    }
}
