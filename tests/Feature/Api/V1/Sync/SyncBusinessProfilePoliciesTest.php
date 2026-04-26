<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');
});

function setupOwnerForPolicies(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->create([
        'address' => null,
        'phone' => null,
        'policies' => null,
    ]);

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);
    $token = $user->createToken('policies-sync-device')->plainTextToken;

    return ['user' => $user, 'business' => $business, 'token' => $token];
}

test('push business_profile persists address, phone and policies on the business', function () {
    ['business' => $business, 'token' => $token] = setupOwnerForPolicies();

    $payload = [
        'device' => ['id' => 'device-policies-1', 'platform' => 'web', 'app_version' => '1.0.0'],
        'cursor' => null,
        'changes' => [
            [
                'event_id' => (string) Str::uuid(),
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => Carbon::now()->toIso8601String(),
                'payload' => [
                    'businessName' => 'Negocio Demo',
                    'address' => 'Calle 23 #145',
                    'phone' => '+53 5555 5555',
                    'defaultCurrency' => 'CUP',
                    'policies' => [
                        'allowZeroStockSales' => false,
                        'enableDebtManagement' => false,
                        'showPrice' => true,
                        'showStock' => true,
                        'showQrCodeGenerator' => true,
                    ],
                ],
            ],
        ],
    ];

    $this->withToken($token)
        ->withHeaders(['X-Sync-Version' => '1', 'X-Client-App-Version' => '1.0.0'])
        ->postJson('/api/v1/sync/push', $payload)
        ->assertAccepted()
        ->assertJsonPath('accepted.0.entity_type', 'business_profile');

    $business->refresh();

    expect($business->address)->toBe('Calle 23 #145');
    expect($business->phone)->toBe('+53 5555 5555');
    expect($business->policies)->toMatchArray([
        'allowZeroStockSales' => false,
        'enableDebtManagement' => false,
    ]);
});

test('pull returns address, phone and policies in the business_profile change', function () {
    ['business' => $business, 'token' => $token] = setupOwnerForPolicies();

    $business->forceFill([
        'address' => 'Avenida 5ta #321',
        'phone' => '+53 1111 2222',
        'policies' => [
            'allowZeroStockSales' => false,
            'enableDebtManagement' => true,
            'showPrice' => false,
            'showStock' => true,
            'showQrCodeGenerator' => true,
        ],
    ])->save();

    $response = $this->withToken($token)
        ->withHeaders(['X-Sync-Version' => '1', 'X-Client-App-Version' => '1.0.0'])
        ->getJson('/api/v1/sync/pull?device_id=device-policies-pull-1&limit=50')
        ->assertOk();

    $businessProfileChange = collect((array) $response->json('changes'))
        ->firstWhere('entity_type', 'business_profile');

    expect($businessProfileChange)->not->toBeNull();
    expect($businessProfileChange['payload']['address'])->toBe('Avenida 5ta #321');
    expect($businessProfileChange['payload']['phone'])->toBe('+53 1111 2222');
    expect($businessProfileChange['payload']['policies'])->toMatchArray([
        'allowZeroStockSales' => false,
        'enableDebtManagement' => true,
        'showPrice' => false,
    ]);
});

test('push business_profile without policies leaves existing policies untouched', function () {
    ['business' => $business, 'token' => $token] = setupOwnerForPolicies();

    $business->forceFill([
        'policies' => [
            'allowZeroStockSales' => false,
            'enableDebtManagement' => false,
        ],
    ])->save();

    $payload = [
        'device' => ['id' => 'device-policies-2', 'platform' => 'web', 'app_version' => '1.0.0'],
        'cursor' => null,
        'changes' => [
            [
                'event_id' => (string) Str::uuid(),
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => Carbon::now()->toIso8601String(),
                'payload' => [
                    'businessName' => 'Negocio Demo',
                    'address' => 'Nueva Direccion',
                ],
            ],
        ],
    ];

    $this->withToken($token)
        ->withHeaders(['X-Sync-Version' => '1', 'X-Client-App-Version' => '1.0.0'])
        ->postJson('/api/v1/sync/push', $payload)
        ->assertAccepted();

    $business->refresh();

    expect($business->address)->toBe('Nueva Direccion');
    expect($business->policies)->toMatchArray([
        'allowZeroStockSales' => false,
        'enableDebtManagement' => false,
    ]);
});

test('push business_profile merges new policies with the existing ones', function () {
    ['business' => $business, 'token' => $token] = setupOwnerForPolicies();

    $business->forceFill([
        'policies' => [
            'allowZeroStockSales' => true,
            'enableDebtManagement' => true,
            'showQrCodeGenerator' => true,
        ],
    ])->save();

    $payload = [
        'device' => ['id' => 'device-policies-3', 'platform' => 'web', 'app_version' => '1.0.0'],
        'cursor' => null,
        'changes' => [
            [
                'event_id' => (string) Str::uuid(),
                'entity_type' => 'business_profile',
                'entity_id' => 'current_business',
                'operation' => 'upsert',
                'occurred_at' => Carbon::now()->toIso8601String(),
                'payload' => [
                    'businessName' => 'Negocio Demo',
                    'policies' => [
                        'allowZeroStockSales' => false,
                    ],
                ],
            ],
        ],
    ];

    $this->withToken($token)
        ->withHeaders(['X-Sync-Version' => '1', 'X-Client-App-Version' => '1.0.0'])
        ->postJson('/api/v1/sync/push', $payload)
        ->assertAccepted();

    $business->refresh();

    expect($business->policies)->toMatchArray([
        'allowZeroStockSales' => false,
        'enableDebtManagement' => true,
        'showQrCodeGenerator' => true,
    ]);
});
