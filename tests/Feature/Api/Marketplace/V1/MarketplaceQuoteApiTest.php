<?php

use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\MarketplaceProductPublication;
use App\Models\MarketplaceQuote;
use App\Models\MarketplaceQuoteLine;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\Warehouse;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;

function createQuoteWarehouse(Business $business, string $externalId = 'warehouse-ecommerce'): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => 'Almacén eCommerce',
    ]);
}

function createQuoteProduct(Business $business, string $externalId, string $title): Product
{
    return Product::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'code' => strtoupper($externalId),
        'title' => $title,
        'regular_price' => 10,
        'purchase_price' => 5,
    ]);
}

function createQuotePublication(
    Business $business,
    string $productExternalId,
    float $price = 10,
    string $warehouseExternalId = 'warehouse-ecommerce'
): MarketplaceProductPublication {
    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'status' => MarketplaceProductPublication::StatusPublished,
        'public_title' => 'Producto Marketplace',
        'public_description' => 'Producto cotizable',
        'public_price' => $price,
        'currency' => 'USD',
        'images' => [],
        'metadata' => [],
    ]);
}

function seedQuoteStock(Business $business, string $productExternalId, string $warehouseExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'qty' => $qty,
    ]);
}

it('creates a marketplace quote from published catalog products', function () {
    $business = Business::factory()->create();
    createQuoteWarehouse($business);
    createQuoteProduct($business, 'coffee', 'Cafe premium');
    $publication = createQuotePublication($business, 'coffee', 12.5);
    seedQuoteStock($business, 'coffee', 'warehouse-ecommerce', 5);

    $response = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 2],
        ],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', MarketplaceQuote::StatusQuoted)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.subtotal', 25)
        ->assertJsonPath('data.grand_total', 25)
        ->assertJsonPath('data.lines.0.publication_id', $publication->id)
        ->assertJsonPath('data.lines.0.product_external_id', 'coffee')
        ->assertJsonPath('data.lines.0.warehouse_external_id', 'warehouse-ecommerce')
        ->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.lines.0.subtotal', 25);

    expect(MarketplaceQuote::query()->count())->toBe(1)
        ->and(MarketplaceQuoteLine::query()->count())->toBe(1);
});

it('reserves a marketplace quote using ecommerce warehouse inventory', function () {
    $business = Business::factory()->create();
    createQuoteWarehouse($business);
    createQuoteProduct($business, 'coffee', 'Cafe premium');
    $publication = createQuotePublication($business, 'coffee', 10);
    seedQuoteStock($business, 'coffee', 'warehouse-ecommerce', 5);
    seedQuoteStock($business, 'coffee', 'warehouse-physical', 99);

    $quoteId = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 3],
        ],
    ])->json('data.id');

    $response = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve");

    $response->assertSuccessful()
        ->assertJsonPath('data.status', MarketplaceQuote::StatusReserved)
        ->assertJsonPath('data.lines.0.warehouse_external_id', 'warehouse-ecommerce');

    $reservation = InventoryReservation::query()->with('lines')->firstOrFail();

    expect($reservation->owner_type)->toBe('marketplace_quote')
        ->and($reservation->lines)->toHaveCount(1)
        ->and($reservation->lines->first()->warehouse_external_id)->toBe('warehouse-ecommerce')
        ->and((float) $reservation->lines->first()->quantity)->toBe(3.0)
        ->and(app(InventoryAvailabilityService::class)->availableFor($business->id, 'coffee', 'warehouse-ecommerce'))->toBe(2.0)
        ->and(app(InventoryAvailabilityService::class)->availableFor($business->id, 'coffee', 'warehouse-physical'))->toBe(99.0);
});

it('rejects quotes when ecommerce warehouse availability is insufficient', function () {
    $business = Business::factory()->create();
    createQuoteWarehouse($business);
    createQuoteProduct($business, 'coffee', 'Cafe premium');
    $publication = createQuotePublication($business, 'coffee', 10);
    seedQuoteStock($business, 'coffee', 'warehouse-ecommerce', 1);
    seedQuoteStock($business, 'coffee', 'warehouse-physical', 99);

    $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 2],
        ],
    ])->assertUnprocessable();

    expect(MarketplaceQuote::query()->count())->toBe(0);
});

it('does not reserve an expired marketplace quote', function () {
    $business = Business::factory()->create();
    createQuoteProduct($business, 'coffee', 'Cafe premium');

    $quote = MarketplaceQuote::factory()->expired()->create();
    MarketplaceQuoteLine::factory()->create([
        'marketplace_quote_id' => $quote->id,
        'business_id' => $business->id,
        'product_external_id' => 'coffee',
        'warehouse_external_id' => 'warehouse-ecommerce',
        'quantity' => 1,
    ]);

    $this->postJson("/api/marketplace/v1/quotes/{$quote->id}/reserve")
        ->assertUnprocessable();

    expect(InventoryReservation::query()->count())->toBe(0);
});
