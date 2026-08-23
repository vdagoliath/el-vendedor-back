<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');
});

function multiCurrencySyncHeaders(): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => '1.0.0',
    ];
}

function setupMultiCurrencySyncUser(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->create(['default_currency' => 'CUP']);

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);

    return [
        'business' => $business,
        'token' => $user->createToken('sync-multi-currency')->plainTextToken,
    ];
}

function pullUntilEntity($testCase, string $token, string $deviceId, string $entityType, string $entityId, int $maxIterations = 20): ?array
{
    $cursor = '';

    for ($i = 0; $i < $maxIterations; $i++) {
        $response = $testCase->withToken($token)
            ->withHeaders(multiCurrencySyncHeaders())
            ->getJson('/api/v1/sync/pull?device_id='.urlencode($deviceId).'&cursor='.urlencode($cursor));

        $response->assertOk();

        $match = collect($response->json('changes'))
            ->first(fn (array $change): bool => ($change['entity_type'] ?? null) === $entityType
                && ($change['entity_id'] ?? null) === $entityId);

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

test('sync persists and pulls multi currency product prices and sale snapshots', function () {
    ['business' => $business, 'token' => $token] = setupMultiCurrencySyncUser();

    $response = $this->withToken($token)
        ->withHeaders(multiCurrencySyncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => ['id' => 'device-mc-push', 'app_version' => '1.0.0'],
            'changes' => [
                [
                    'event_id' => 'evt-mc-product-1',
                    'entity_type' => 'products',
                    'entity_id' => 'product-mc-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'code' => 'MC-001',
                        'title' => 'Producto multi moneda',
                        'regular_price' => 1200,
                        'purchase_price' => 800,
                        'pricesByCurrency' => [
                            'USD' => [
                                'regular_price' => 10,
                                'purchase_price' => 6.5,
                                'updatedAt' => '2026-08-23T10:00:00.000Z',
                            ],
                        ],
                    ],
                ],
                [
                    'event_id' => 'evt-mc-sale-1',
                    'entity_type' => 'sales',
                    'entity_id' => 'sale-mc-1',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'type' => 'sale',
                        'reference' => 'V-MC-001',
                        'warehouseId' => 'warehouse-main',
                        'status' => 'completed',
                        'currency' => 'USD',
                        'exchangeRateFromBase' => 120,
                        'total' => 20,
                        'totalBase' => 0.17,
                        'lines' => [
                            [
                                'productId' => 'product-mc-1',
                                'productTitle' => 'Producto multi moneda',
                                'price' => 10,
                                'priceBase' => 0.0833,
                                'amount' => 2,
                                'subTotal' => 20,
                                'subTotalBase' => 0.17,
                                'currency' => 'USD',
                                'exchangeRateFromBase' => 120,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertAccepted();

    $product = Product::query()
        ->where('business_id', $business->id)
        ->where('external_id', 'product-mc-1')
        ->firstOrFail();
    expect($product->prices_by_currency['USD']['regular_price'] ?? null)->toBe(10);

    $sale = Sale::query()
        ->with('lines')
        ->where('business_id', $business->id)
        ->where('external_id', 'sale-mc-1')
        ->firstOrFail();

    expect((float) $sale->total_base)->toBe(0.17);
    expect((float) $sale->exchange_rate_from_base)->toBe(120.0);
    expect($sale->lines)->toHaveCount(1);
    expect((float) $sale->lines->first()->price_base)->toBe(0.0833);
    expect($sale->lines->first()->currency)->toBe('USD');

    $pulledProduct = pullUntilEntity($this, $token, 'device-mc-pull', 'products', 'product-mc-1');
    expect($pulledProduct['payload']['pricesByCurrency']['USD']['purchase_price'] ?? null)->toBe(6.5);

    $pulledSale = pullUntilEntity($this, $token, 'device-mc-pull-sale', 'sales', 'sale-mc-1');
    expect($pulledSale['payload']['currency'] ?? null)->toBe('USD');
    expect($pulledSale['payload']['totalBase'] ?? null)->toBe(0.17);
    expect((float) ($pulledSale['payload']['exchangeRateFromBase'] ?? 0))->toBe(120.0);
    expect($pulledSale['payload']['lines'][0]['priceBase'] ?? null)->toBe(0.0833);
    expect($pulledSale['payload']['lines'][0]['subTotalBase'] ?? null)->toBe(0.17);
});
