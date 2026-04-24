<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');
});

function snapshotSyncHeaders(): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => '1.0.0',
    ];
}

function setupSnapshotBusinessUser(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);
    $token = $user->createToken('sync-device')->plainTextToken;

    return ['user' => $user, 'business' => $business, 'token' => $token];
}

/**
 * Drive the serial pull until a sale with the given external_id appears, or
 * we hit the iteration budget. Mirrors the client-side pull loop.
 */
function pullUntilSale($testCase, string $token, string $deviceId, string $externalId, int $maxIterations = 20): ?array
{
    $cursor = '';
    for ($i = 0; $i < $maxIterations; $i++) {
        $response = $testCase->withToken($token)
            ->withHeaders(snapshotSyncHeaders())
            ->getJson('/api/v1/sync/pull?device_id='.urlencode($deviceId).'&cursor='.urlencode($cursor));

        $response->assertOk();

        $match = collect($response->json('changes'))
            ->first(fn (array $change): bool => ($change['entity_id'] ?? null) === $externalId);

        if ($match) {
            return $match;
        }

        if (! ($response->json('meta.has_more') ?? false)) {
            return null;
        }

        $cursor = (string) $response->json('cursor');
    }

    return null;
}

test('credit sale push persists the contactSnapshot from the seller payload', function () {
    ['business' => $business, 'token' => $token] = setupSnapshotBusinessUser();

    $response = $this->withToken($token)
        ->withHeaders(snapshotSyncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => ['id' => 'device-snapshot-1', 'app_version' => '1.0.0'],
            'changes' => [
                [
                    'event_id' => 'evt-snapshot-1',
                    'entity_type' => 'sales',
                    'entity_id' => 'sale-snapshot-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'type' => 'sale',
                        'reference' => 'V-001',
                        'contact' => 'contact-x',
                        'contactSnapshot' => [
                            'name' => 'María Pérez',
                            'mobile' => '5551111',
                            'idCard' => '85010112345',
                        ],
                        'status' => 'credit',
                        'paymentMethod' => 'credit',
                        'total' => 250,
                        'lines' => [
                            ['productId' => 'p-1', 'productTitle' => 'Producto A', 'price' => 250, 'amount' => 1, 'subTotal' => 250],
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertAccepted();

    $sale = Sale::query()->where('business_id', $business->id)->where('external_id', 'sale-snapshot-1')->firstOrFail();
    expect($sale->contact_snapshot['name'] ?? null)->toBe('María Pérez');
    expect($sale->contact_snapshot['mobile'] ?? null)->toBe('5551111');
    expect($sale->contact_snapshot['idCard'] ?? null)->toBe('85010112345');
});

test('sale pull derives contactSnapshot from the linked contact when available', function () {
    ['user' => $user, 'business' => $business, 'token' => $token] = setupSnapshotBusinessUser();

    Contact::query()->create([
        'business_id' => $business->id,
        'external_id' => 'contact-fresh',
        'name' => 'Juan Cliente',
        'mobile' => '5559999',
        'id_card' => '90050567890',
        'type' => 'customer',
    ]);

    Sale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'sale-fresh',
        'contact_external_id' => 'contact-fresh',
        'reference' => 'V-002',
        'status' => 'credit',
        'payment_method' => 'credit',
        'total' => 500,
    ]);

    $saleChange = pullUntilSale($this, $token, 'device-snapshot-pull-1', 'sale-fresh');

    expect($saleChange)->not->toBeNull();
    expect($saleChange['payload']['contact'] ?? null)->toBe('contact-fresh');
    expect($saleChange['payload']['contactSnapshot']['name'] ?? null)->toBe('Juan Cliente');
    expect($saleChange['payload']['contactSnapshot']['mobile'] ?? null)->toBe('5559999');
    expect($saleChange['payload']['contactSnapshot']['idCard'] ?? null)->toBe('90050567890');
});

test('sale pull falls back to persisted snapshot when contact is not in contacts table', function () {
    ['business' => $business, 'token' => $token] = setupSnapshotBusinessUser();

    Sale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'sale-orphan',
        'contact_external_id' => 'contact-missing',
        'contact_snapshot' => [
            'name' => 'Cliente Desconectado',
            'mobile' => '5552222',
            'idCard' => null,
        ],
        'reference' => 'V-003',
        'status' => 'credit',
        'payment_method' => 'credit',
        'total' => 100,
    ]);

    $saleChange = pullUntilSale($this, $token, 'device-snapshot-pull-2', 'sale-orphan');

    expect($saleChange)->not->toBeNull();
    expect($saleChange['payload']['contactSnapshot']['name'] ?? null)->toBe('Cliente Desconectado');
    expect($saleChange['payload']['contactSnapshot']['mobile'] ?? null)->toBe('5552222');
});

test('sale pull returns null contactSnapshot when neither contact nor stored snapshot exist', function () {
    ['business' => $business, 'token' => $token] = setupSnapshotBusinessUser();

    Sale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'sale-cashier',
        'contact_external_id' => null,
        'reference' => 'V-004',
        'status' => 'completed',
        'payment_method' => 'cash',
        'total' => 50,
    ]);

    $saleChange = pullUntilSale($this, $token, 'device-snapshot-pull-3', 'sale-cashier');

    expect($saleChange)->not->toBeNull();
    expect(array_key_exists('contactSnapshot', $saleChange['payload']))->toBeTrue();
    expect($saleChange['payload']['contactSnapshot'])->toBeNull();
});
