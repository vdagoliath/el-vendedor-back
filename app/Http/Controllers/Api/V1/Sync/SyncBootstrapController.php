<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\PushSyncRequest;
use App\Models\Business;
use App\Support\Licensing\BusinessLicensePricingResolver;
use App\Support\Sync\SyncCompatibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncBootstrapController extends Controller
{
    public function __construct(
        private readonly BusinessLicensePricingResolver $pricingResolver,
        private readonly SyncCompatibility $syncCompatibility
    ) {}

    /**
     * Return the bootstrap contract for a device entering sync.
     */
    public function show(Request $request): JsonResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'sync_version' => $this->syncCompatibility->currentProtocolVersion(),
            'compatibility' => $this->syncCompatibility->bootstrapPayload(),
            'current_business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'address' => $business->address,
                'phone' => $business->phone,
                'default_currency' => $business->default_currency ?? 'CUP',
                'license_expires_at' => $business->license_expires_at?->toIso8601String(),
                'license_catalog' => $this->pricingResolver->catalog(),
                'license_quote' => $this->pricingResolver->quote($business),
            ],
            'capabilities' => [
                'push' => true,
                'pull' => true,
                'conflicts' => true,
                'inventory_events' => true,
            ],
            'supported_entities' => [
                'business_profile',
                'license_catalog',
                'license_quote',
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
                'cash_register_sessions',
            ],
            'push_contract' => [
                'max_batch_size' => PushSyncRequest::MAX_BATCH_SIZE,
                'operations' => ['create', 'update', 'delete', 'upsert'],
            ],
            'pull_contract' => [
                'cursor_type' => 'iso8601',
                'default_limit' => 500,
            ],
        ]);
    }
}
