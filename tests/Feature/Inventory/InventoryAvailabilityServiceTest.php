<?php

use App\Models\Business;
use App\Models\StockProjection;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable;

function seedProjectedStock(Business $business, string $productExternalId, string $warehouseExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'qty' => $qty,
    ]);
}

it('resolves inventory availability from stock projections by product and warehouse', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();

    seedProjectedStock($business, 'product-a', 'wh-1', 8);
    seedProjectedStock($business, 'product-a', 'wh-2', 4.5);
    seedProjectedStock($business, 'product-b', 'wh-1', 3);
    seedProjectedStock($otherBusiness, 'product-a', 'wh-1', 99);

    $availability = app(InventoryAvailabilityService::class);

    expect($availability->availableFor($business->id, 'product-a'))->toBe(12.5)
        ->and($availability->availableFor($business->id, 'product-a', 'wh-1'))->toBe(8.0)
        ->and($availability->availableFor($business->id, 'product-a', 'wh-2'))->toBe(4.5)
        ->and($availability->availableFor($business->id, 'product-a', 'missing-warehouse'))->toBe(0.0)
        ->and($availability->availableFor($business->id, 'missing-product'))->toBe(0.0)
        ->and($availability->availableFor($otherBusiness->id, 'product-a'))->toBe(99.0);
});

it('resolves inventory availability in batch without losing caller keys', function () {
    $business = Business::factory()->create();

    seedProjectedStock($business, 'product-a', 'wh-1', 8);
    seedProjectedStock($business, 'product-a', 'wh-2', 4.5);
    seedProjectedStock($business, 'product-b', 'wh-1', 3);

    $availability = app(InventoryAvailabilityService::class)->availableMany([
        'a-total' => [
            'business_id' => $business->id,
            'product_external_id' => 'product-a',
        ],
        'a-wh-2' => [
            'business_id' => $business->id,
            'product_external_id' => 'product-a',
            'warehouse_external_id' => 'wh-2',
        ],
        'b-wh-1' => [
            'business_id' => $business->id,
            'product_external_id' => 'product-b',
            'warehouse_external_id' => 'wh-1',
        ],
        'missing' => [
            'business_id' => $business->id,
            'product_external_id' => 'missing-product',
        ],
    ]);

    expect($availability)->toBe([
        'a-total' => 12.5,
        'a-wh-2' => 4.5,
        'b-wh-1' => 3.0,
        'missing' => 0.0,
    ]);
});

it('asserts requested inventory availability', function () {
    $business = Business::factory()->create();

    seedProjectedStock($business, 'product-a', 'wh-1', 8);

    $availability = app(InventoryAvailabilityService::class);

    $availability->assertAvailable($business->id, 'product-a', 8, 'wh-1');

    expect(fn () => $availability->assertAvailable($business->id, 'product-a', 8.01, 'wh-1'))
        ->toThrow(InsufficientInventoryAvailable::class);
});
