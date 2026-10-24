<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\SyncDiagnostic;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.2.3');
    config()->set('sync.required_app_version', '1.2.3');
});

function compatibilityHeaders(string $appVersion = '1.2.3', string $syncVersion = '1'): array
{
    return [
        'X-Sync-Version' => $syncVersion,
        'X-Client-App-Version' => $appVersion,
    ];
}

function makeSyncUserAndBusiness(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);

    return [$user, $business];
}

test('bootstrap exposes sync compatibility metadata', function () {
    [$user, $business] = makeSyncUserAndBusiness();
    $token = $user->createToken('sync-bootstrap')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(compatibilityHeaders())
        ->getJson('/api/v1/sync/bootstrap');

    $response
        ->assertOk()
        ->assertJsonPath('sync_version', 1)
        ->assertJsonPath('compatibility.policy', 'same_version')
        ->assertJsonPath('compatibility.enforced', true)
        ->assertJsonPath('compatibility.server_app_version', '1.2.3')
        ->assertJsonPath('compatibility.required_app_version', '1.2.3')
        ->assertJsonPath('meta.server_sync_version', 1)
        ->assertJsonPath('meta.server_app_version', '1.2.3');

    expect($business->id)->toBeGreaterThan(0);
});

test('push rejects incompatible app version and records a diagnostic', function () {
    [$user, $business] = makeSyncUserAndBusiness();
    $token = $user->createToken('sync-incompatible')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(compatibilityHeaders('1.2.2'))
        ->postJson('/api/v1/sync/push', [
            'device' => [
                'id' => 'device-incompatible-1',
                'name' => 'Android',
                'platform' => 'android',
                'app_version' => '1.2.2',
            ],
            'changes' => [
                [
                    'event_id' => 'evt-incompatible-1',
                    'entity_type' => 'products',
                    'entity_id' => 'product-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'code' => 'P-001',
                        'title' => 'Producto incompatible',
                    ],
                ],
            ],
        ]);

    $response
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sync_app_version_mismatch')
        ->assertJsonPath('compatibility.client_app_version', '1.2.2')
        ->assertJsonPath('compatibility.required_app_version', '1.2.3');

    $diagnostic = SyncDiagnostic::query()->latest('id')->first();

    expect($diagnostic)->not->toBeNull();
    expect($diagnostic->business_id)->toBe($business->id);
    expect($diagnostic->stage)->toBe('push');
    expect($diagnostic->status)->toBe('rejected');
    expect($diagnostic->error_code)->toBe('sync_app_version_mismatch');
    expect($diagnostic->client_app_version)->toBe('1.2.2');
});

test('pull rejects incompatible protocol version', function () {
    [$user] = makeSyncUserAndBusiness();
    $token = $user->createToken('sync-protocol')->plainTextToken;

    $response = $this->withToken($token)
        ->withHeaders(compatibilityHeaders('1.2.3', '99'))
        ->getJson('/api/v1/sync/pull?device_id=device-protocol-1');

    $response
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sync_protocol_version_mismatch')
        ->assertJsonPath('compatibility.client_sync_version', 99)
        ->assertJsonPath('compatibility.server_sync_version', 1);
});
