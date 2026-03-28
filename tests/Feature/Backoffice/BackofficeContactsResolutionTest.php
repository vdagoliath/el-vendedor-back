<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\SyncReceivedEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('purchases resolve supplier names from legacy provider sync payloads', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();

    $owner->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $owner->switchCurrentBusiness($business);

    SyncReceivedEvent::query()->create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'device_id' => null,
        'event_id' => 'evt-legacy-provider-1',
        'entity_type' => 'providers',
        'entity_id' => 'supplier-legacy-1',
        'operation' => 'upsert',
        'occurred_at' => now(),
        'payload' => [
            'supplier_name' => 'Proveedor Historico',
        ],
        'status' => 'applied',
        'error_message' => null,
        'processed_at' => now(),
    ]);

    SyncReceivedEvent::query()->create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'device_id' => null,
        'event_id' => 'evt-purchase-1',
        'entity_type' => 'purchases',
        'entity_id' => 'purchase-1',
        'operation' => 'upsert',
        'occurred_at' => now(),
        'payload' => [
            'reference' => 'COMP-001',
            'status' => 'pending',
            'dateTime' => now()->toIso8601String(),
            'total' => 150.25,
            'contact' => 'supplier-legacy-1',
            'lines' => [],
        ],
        'status' => 'applied',
        'error_message' => null,
        'processed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('backoffice.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backoffice/Purchases')
            ->where('purchases.0.reference', 'COMP-001')
            ->where('purchases.0.supplier_name', 'Proveedor Historico'),
        );
});
