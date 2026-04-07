<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\SyncReceivedEvent;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');
});

function syncHeaders(string $appVersion = '1.0.0'): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => $appVersion,
    ];
}

test('sync push normalizes provider records into contacts', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);

    $token = $user->createToken('sync-device')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(syncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => [
                'id' => 'device-provider-1',
                'name' => 'Android',
                'platform' => 'android',
                'app_version' => '1.0.0',
            ],
            'changes' => [
                [
                    'event_id' => 'evt-provider-1',
                    'entity_type' => 'providers',
                    'entity_id' => 'supplier-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'supplier_name' => 'Ferreteria Central',
                        'contact_type' => 'proveedor',
                        'phone' => '555-0101',
                    ],
                ],
            ],
        ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('accepted.0.entity_type', 'contacts')
        ->assertJsonPath('accepted.0.entity_id', 'supplier-1')
        ->assertJsonPath('meta.server_sync_version', 1);

    $event = SyncReceivedEvent::query()
        ->where('business_id', $business->id)
        ->where('event_id', 'evt-provider-1')
        ->firstOrFail();

    expect($event->entity_type)->toBe('contacts');
    expect($event->payload['name'] ?? null)->toBe('Ferreteria Central');
    expect($event->payload['type'] ?? null)->toBe('supplier');

    $pullResponse = $this->withToken($token)
        ->withHeaders(syncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=device-provider-1');

    $pullResponse->assertOk();

    $contactChange = collect($pullResponse->json('changes'))
        ->first(fn (array $change): bool => ($change['entity_id'] ?? null) === 'supplier-1');

    expect($contactChange)->not->toBeNull();
    expect($contactChange['entity_type'])->toBe('contacts');
    expect($contactChange['payload']['name'] ?? null)->toBe('Ferreteria Central');
    expect($contactChange['payload']['type'] ?? null)->toBe('supplier');
});

test('employees can not sync supplier contacts', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);

    $token = $user->createToken('sync-device')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(syncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => [
                'id' => 'device-employee-1',
                'app_version' => '1.0.0',
            ],
            'changes' => [
                [
                    'event_id' => 'evt-employee-provider-1',
                    'entity_type' => 'providers',
                    'entity_id' => 'supplier-employee-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'supplier_name' => 'Proveedor Bloqueado',
                    ],
                ],
            ],
        ]);

    $response
        ->assertAccepted()
        ->assertJsonCount(0, 'accepted')
        ->assertJsonPath('rejected.0.entity_type', 'contacts')
        ->assertJsonPath('rejected.0.reason', 'Tu rol actual no puede gestionar proveedores.')
        ->assertJsonPath('rejected.0.code', 'sync_permission_denied')
        ->assertJsonPath('rejected.0.retryable', false);

    $event = SyncReceivedEvent::query()
        ->where('business_id', $business->id)
        ->where('event_id', 'evt-employee-provider-1')
        ->firstOrFail();

    expect($event->status)->toBe('failed');
});

test('employees can sync customer contacts', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);

    $token = $user->createToken('sync-device')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(syncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => [
                'id' => 'device-employee-2',
                'app_version' => '1.0.0',
            ],
            'changes' => [
                [
                    'event_id' => 'evt-employee-customer-1',
                    'entity_type' => 'customers',
                    'entity_id' => 'customer-employee-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'customer_name' => 'Cliente Permitido',
                    ],
                ],
            ],
        ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('accepted.0.entity_type', 'contacts')
        ->assertJsonPath('accepted.0.entity_id', 'customer-employee-1');

    $event = SyncReceivedEvent::query()
        ->where('business_id', $business->id)
        ->where('event_id', 'evt-employee-customer-1')
        ->firstOrFail();

    expect($event->status)->toBe('applied');
    expect($event->payload['type'] ?? null)->toBe('customer');
    expect($event->payload['name'] ?? null)->toBe('Cliente Permitido');
});
