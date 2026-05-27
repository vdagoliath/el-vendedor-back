<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');

    $this->user = User::factory()->create();
    $this->business = Business::factory()->create();

    $this->user->businesses()->attach($this->business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $this->user->switchCurrentBusiness($this->business);

    $owner = $this->user->createToken('owner-device', ['sync:owner']);
    $owner->accessToken->forceFill(['business_id' => $this->business->id])->save();
    $this->ownerToken = $owner->plainTextToken;
});

function projectionSyncHeaders(): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => '1.0.0',
    ];
}

function pushChanges($testCase, string $token, string $deviceId, array $changes): \Illuminate\Testing\TestResponse
{
    return $testCase->withToken($token)
        ->withHeaders(projectionSyncHeaders())
        ->postJson('/api/v1/sync/push', [
            'device' => ['id' => $deviceId, 'app_version' => '1.0.0'],
            'changes' => $changes,
        ]);
}

test('a product upsert with _stockSeed seeds the projection', function () {
    pushChanges($this, $this->ownerToken, 'device-projection-1', [[
        'event_id' => 'evt-product-seed',
        'entity_type' => 'products',
        'entity_id' => 'product-A',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'code' => 'P-A',
            'title' => 'Producto A',
            'regular_price' => 100,
            'purchase_price' => 50,
            '_stockSeed' => true,
            'stockByWarehouse' => [
                ['warehouseId' => 'wh-1', 'quantity' => 50],
                ['warehouseId' => 'wh-2', 'quantity' => 30],
            ],
        ],
    ]])->assertAccepted();

    $rows = StockProjection::query()
        ->where('business_id', $this->business->id)
        ->where('product_external_id', 'product-A')
        ->orderBy('warehouse_external_id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and((float) $rows[0]->qty)->toBe(50.0)
        ->and((float) $rows[1]->qty)->toBe(30.0);
});

test('a product upsert without _stockSeed does not touch the projection', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-B',
        'warehouse_external_id' => 'wh-1',
        'qty' => 17,
    ]);

    pushChanges($this, $this->ownerToken, 'device-projection-2', [[
        'event_id' => 'evt-product-rename',
        'entity_type' => 'products',
        'entity_id' => 'product-B',
        'operation' => 'update',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'code' => 'P-B',
            'title' => 'Producto B renombrado',
            'regular_price' => 120,
            'purchase_price' => 60,
            // sin _stockSeed → ignorar el campo de stock
            'stockByWarehouse' => [
                ['warehouseId' => 'wh-1', 'quantity' => 999],
            ],
        ],
    ]])->assertAccepted();

    $row = StockProjection::query()
        ->where('business_id', $this->business->id)
        ->where('product_external_id', 'product-B')
        ->where('warehouse_external_id', 'wh-1')
        ->firstOrFail();

    expect((float) $row->qty)->toBe(17.0);
});

test('a completed sale subtracts from the projection', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-C',
        'warehouse_external_id' => 'wh-1',
        'qty' => 50,
    ]);

    pushChanges($this, $this->ownerToken, 'device-projection-3', [[
        'event_id' => 'evt-sale-1',
        'entity_type' => 'sales',
        'entity_id' => 'sale-1',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'sale',
            'status' => 'completed',
            'warehouseId' => 'wh-1',
            'total' => 200,
            'lines' => [
                ['productId' => 'product-C', 'productTitle' => 'C', 'price' => 50, 'amount' => 4, 'subTotal' => 200],
            ],
        ],
    ]])->assertAccepted();

    $row = StockProjection::query()
        ->where('product_external_id', 'product-C')
        ->where('warehouse_external_id', 'wh-1')
        ->firstOrFail();

    expect((float) $row->qty)->toBe(46.0);
});

test('returning a sale restores the stock', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-D',
        'warehouse_external_id' => 'wh-1',
        'qty' => 50,
    ]);

    // Primero la venta completed → -3
    pushChanges($this, $this->ownerToken, 'device-projection-4', [[
        'event_id' => 'evt-sale-d-1',
        'entity_type' => 'sales',
        'entity_id' => 'sale-d',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'sale',
            'status' => 'completed',
            'warehouseId' => 'wh-1',
            'total' => 150,
            'lines' => [
                ['productId' => 'product-D', 'productTitle' => 'D', 'price' => 50, 'amount' => 3, 'subTotal' => 150],
            ],
        ],
    ]])->assertAccepted();

    expect((float) StockProjection::where('product_external_id', 'product-D')->first()->qty)->toBe(47.0);

    // Luego la transición a returned → +3
    pushChanges($this, $this->ownerToken, 'device-projection-4', [[
        'event_id' => 'evt-sale-d-2',
        'entity_type' => 'sales',
        'entity_id' => 'sale-d',
        'operation' => 'update',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'sale',
            'status' => 'returned',
            'warehouseId' => 'wh-1',
            'total' => 150,
            'lines' => [
                ['productId' => 'product-D', 'productTitle' => 'D', 'price' => 50, 'amount' => 3, 'subTotal' => 150],
            ],
        ],
    ]])->assertAccepted();

    expect((float) StockProjection::where('product_external_id', 'product-D')->first()->qty)->toBe(50.0);
});

test('a completed purchase adds to the projection', function () {
    pushChanges($this, $this->ownerToken, 'device-projection-5', [[
        'event_id' => 'evt-purchase-1',
        'entity_type' => 'purchases',
        'entity_id' => 'purchase-1',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'purchase',
            'status' => 'completed',
            'warehouseId' => 'wh-1',
            'total' => 1000,
            'lines' => [
                ['productId' => 'product-E', 'productTitle' => 'E', 'price' => 10, 'amount' => 100, 'subTotal' => 1000],
            ],
        ],
    ]])->assertAccepted();

    $row = StockProjection::query()
        ->where('product_external_id', 'product-E')
        ->where('warehouse_external_id', 'wh-1')
        ->firstOrFail();

    expect((float) $row->qty)->toBe(100.0);
});

test('a stock movement applies opposite deltas in from and to warehouses', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-F',
        'warehouse_external_id' => 'wh-central',
        'qty' => 100,
    ]);

    pushChanges($this, $this->ownerToken, 'device-projection-6', [[
        'event_id' => 'evt-movement-1',
        'entity_type' => 'stock_movements',
        'entity_id' => 'movement-1',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'productId' => 'product-F',
            'fromWarehouseId' => 'wh-central',
            'toWarehouseId' => 'wh-pdv',
            'quantity' => 25,
            'timestamp' => now()->toIso8601String(),
        ],
    ]])->assertAccepted();

    $central = StockProjection::query()->where('product_external_id', 'product-F')->where('warehouse_external_id', 'wh-central')->firstOrFail();
    $pdv = StockProjection::query()->where('product_external_id', 'product-F')->where('warehouse_external_id', 'wh-pdv')->firstOrFail();

    expect((float) $central->qty)->toBe(75.0)
        ->and((float) $pdv->qty)->toBe(25.0);
});

test('a stock adjustment applies its change_quantity', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-G',
        'warehouse_external_id' => 'wh-1',
        'qty' => 80,
    ]);

    pushChanges($this, $this->ownerToken, 'device-projection-7', [[
        'event_id' => 'evt-adjustment-1',
        'entity_type' => 'stock_adjustments',
        'entity_id' => 'adjustment-1',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'productId' => 'product-G',
            'warehouseId' => 'wh-1',
            'quantity' => 100,        // target
            'changeQuantity' => 20,   // +20 para llegar a 100 desde 80
            'previousQuantity' => 80,
            'reason' => 'Conteo físico',
            'timestamp' => now()->toIso8601String(),
        ],
    ]])->assertAccepted();

    $row = StockProjection::query()
        ->where('product_external_id', 'product-G')
        ->where('warehouse_external_id', 'wh-1')
        ->firstOrFail();

    expect((float) $row->qty)->toBe(100.0);
});

test('a product breakdown subtracts source stock and adds target stock', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'box-cigars',
        'warehouse_external_id' => 'wh-1',
        'qty' => 3,
    ]);

    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'single-cigar',
        'warehouse_external_id' => 'wh-1',
        'qty' => 5,
    ]);

    pushChanges($this, $this->ownerToken, 'device-projection-breakdown', [[
        'event_id' => 'evt-breakdown-1',
        'entity_type' => 'product_breakdowns',
        'entity_id' => 'breakdown-1',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'sourceProductId' => 'box-cigars',
            'targetProductId' => 'single-cigar',
            'warehouseId' => 'wh-1',
            'sourceQuantity' => 1,
            'targetQuantity' => 20,
            'conversionRatio' => 20,
            'timestamp' => now()->toIso8601String(),
        ],
    ]])->assertAccepted();

    $box = StockProjection::query()->where('product_external_id', 'box-cigars')->where('warehouse_external_id', 'wh-1')->firstOrFail();
    $single = StockProjection::query()->where('product_external_id', 'single-cigar')->where('warehouse_external_id', 'wh-1')->firstOrFail();

    expect((float) $box->qty)->toBe(2.0)
        ->and((float) $single->qty)->toBe(25.0);
});

test('a product upsert persists breakdown configuration', function () {
    pushChanges($this, $this->ownerToken, 'device-projection-breakdown-config', [[
        'event_id' => 'evt-product-breakdown-config',
        'entity_type' => 'products',
        'entity_id' => 'box-eggs',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'code' => 'EGG-BOX',
            'title' => 'Carton de huevos',
            'regular_price' => 300,
            'purchase_price' => 240,
            'canBreakdown' => true,
            'breakdownTargetProductId' => 'single-egg',
            'breakdownTargetQuantity' => 30,
            'breakdownTargetTitleSnapshot' => 'Huevo suelto',
            'breakdownTargetUnitSymbolSnapshot' => 'u',
        ],
    ]])->assertAccepted();

    $product = Product::query()->where('external_id', 'box-eggs')->firstOrFail();

    expect($product->can_breakdown)->toBeTrue()
        ->and($product->breakdown_target_product_external_id)->toBe('single-egg')
        ->and((float) $product->breakdown_target_quantity)->toBe(30.0)
        ->and($product->breakdown_target_title_snapshot)->toBe('Huevo suelto')
        ->and($product->breakdown_target_unit_symbol_snapshot)->toBe('u');
});

test('a product upsert persists and pulls recipe configuration', function () {
    pushChanges($this, $this->ownerToken, 'device-projection-recipe-config', [[
        'event_id' => 'evt-product-recipe-config',
        'entity_type' => 'products',
        'entity_id' => 'prepared-coffee',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'code' => 'CAF-PREP',
            'title' => 'Cafe preparado',
            'regular_price' => 80,
            'purchase_price' => 20,
            'hasRecipe' => true,
            'recipeItems' => [
                [
                    'componentProductId' => 'coffee-beans',
                    'quantity' => 0.02,
                    'componentTitleSnapshot' => 'Cafe molido',
                    'componentUnitSymbolSnapshot' => 'kg',
                ],
                [
                    'componentProductId' => 'sugar',
                    'quantity' => 0.01,
                    'componentTitleSnapshot' => 'Azucar',
                    'componentUnitSymbolSnapshot' => 'kg',
                ],
            ],
        ],
    ]])->assertAccepted();

    $product = Product::query()->where('external_id', 'prepared-coffee')->firstOrFail();

    expect($product->has_recipe)->toBeTrue()
        ->and($product->recipe_items)->toHaveCount(2)
        ->and($product->recipe_items[0]['componentProductId'])->toBe('coffee-beans');

    $pull = $this->withToken($this->ownerToken)
        ->withHeaders(projectionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=device-pull-recipe&cursor=v3:0');

    $pull->assertOk();

    $productChange = collect($pull->json('changes'))
        ->first(fn (array $change): bool => ($change['entity_type'] ?? null) === 'products'
            && ($change['entity_id'] ?? null) === 'prepared-coffee');

    expect($productChange)->not->toBeNull()
        ->and($productChange['payload']['hasRecipe'])->toBeTrue()
        ->and($productChange['payload']['recipeItems'])->toHaveCount(2)
        ->and($productChange['payload']['recipeItems'][1]['componentProductId'])->toBe('sugar');
});

test('replaying the same sale event does not double-count', function () {
    StockProjection::query()->create([
        'business_id' => $this->business->id,
        'product_external_id' => 'product-H',
        'warehouse_external_id' => 'wh-1',
        'qty' => 30,
    ]);

    $payload = [
        'event_id' => 'evt-sale-h',
        'entity_type' => 'sales',
        'entity_id' => 'sale-h',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'sale',
            'status' => 'completed',
            'warehouseId' => 'wh-1',
            'total' => 100,
            'lines' => [
                ['productId' => 'product-H', 'productTitle' => 'H', 'price' => 10, 'amount' => 5, 'subTotal' => 50],
            ],
        ],
    ];

    pushChanges($this, $this->ownerToken, 'device-projection-8', [$payload])->assertAccepted();
    pushChanges($this, $this->ownerToken, 'device-projection-8', [$payload])->assertAccepted();

    expect((float) StockProjection::where('product_external_id', 'product-H')->first()->qty)->toBe(25.0);
});

test('the legacy stock_by_warehouse mirrors the projection after each apply', function () {
    pushChanges($this, $this->ownerToken, 'device-projection-9', [[
        'event_id' => 'evt-product-mirror',
        'entity_type' => 'products',
        'entity_id' => 'product-I',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'code' => 'P-I',
            'title' => 'I',
            'regular_price' => 5,
            'purchase_price' => 1,
            '_stockSeed' => true,
            'stockByWarehouse' => [
                ['warehouseId' => 'wh-1', 'quantity' => 10],
            ],
        ],
    ]])->assertAccepted();

    pushChanges($this, $this->ownerToken, 'device-projection-9', [[
        'event_id' => 'evt-sale-mirror',
        'entity_type' => 'sales',
        'entity_id' => 'sale-mirror',
        'operation' => 'create',
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'type' => 'sale',
            'status' => 'completed',
            'warehouseId' => 'wh-1',
            'total' => 5,
            'lines' => [
                ['productId' => 'product-I', 'productTitle' => 'I', 'price' => 5, 'amount' => 1, 'subTotal' => 5],
            ],
        ],
    ]])->assertAccepted();

    $product = Product::query()->where('external_id', 'product-I')->firstOrFail();
    $entries = collect($product->stock_by_warehouse ?? [])
        ->keyBy('warehouseId');

    expect((float) $entries['wh-1']['quantity'])->toBe(9.0);
});
