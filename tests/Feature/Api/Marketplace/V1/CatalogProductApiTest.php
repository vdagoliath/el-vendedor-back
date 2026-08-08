<?php

use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\MarketplaceProductPublication;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\Warehouse;

function createCatalogProduct(Business $business, string $externalId, string $title, string $code): Product
{
    return Product::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'code' => $code,
        'title' => $title,
        'description' => "{$title} description",
        'regular_price' => 10,
        'purchase_price' => 5,
    ]);
}

function createCatalogWarehouse(Business $business, string $externalId = 'warehouse-ecommerce', string $name = 'Almacén eCommerce'): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => $name,
    ]);
}

function createCatalogPublication(
    Business $business,
    string $productExternalId,
    string $title,
    string $status = MarketplaceProductPublication::StatusPublished,
    float $price = 10.0,
    string $warehouseExternalId = 'warehouse-ecommerce'
): MarketplaceProductPublication {
    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'status' => $status,
        'public_title' => $title,
        'public_description' => "{$title} public description",
        'public_price' => $price,
        'currency' => 'USD',
        'images' => [['url' => "https://example.test/{$productExternalId}.jpg"]],
        'metadata' => ['source' => 'test'],
    ]);
}

function seedCatalogStock(Business $business, string $productExternalId, string $warehouseExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'qty' => $qty,
    ]);
}

it('lists published marketplace catalog products with availability', function () {
    $business = Business::factory()->create(['name' => 'Bodega Central']);
    createCatalogWarehouse($business);
    createCatalogProduct($business, 'coffee', 'Cafe Cubita', 'CAF-001');
    createCatalogProduct($business, 'sugar', 'Azucar Blanca', 'AZU-001');

    createCatalogPublication($business, 'coffee', 'Cafe premium', MarketplaceProductPublication::StatusPublished, 12.5);
    createCatalogPublication($business, 'sugar', 'Azucar refinada', MarketplaceProductPublication::StatusDraft, 4.25);
    seedCatalogStock($business, 'coffee', 'warehouse-physical', 20);
    seedCatalogStock($business, 'coffee', 'warehouse-ecommerce', 7);

    $response = $this->getJson('/api/marketplace/v1/catalog/products');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Cafe premium')
        ->assertJsonPath('data.0.product.external_id', 'coffee')
        ->assertJsonPath('data.0.product.code', 'CAF-001')
        ->assertJsonPath('data.0.business.name', 'Bodega Central')
        ->assertJsonPath('data.0.warehouse.external_id', 'warehouse-ecommerce')
        ->assertJsonPath('data.0.warehouse.name', 'Almacén eCommerce')
        ->assertJsonPath('data.0.price', 12.5)
        ->assertJsonPath('data.0.availability.available', 7)
        ->assertJsonPath('data.0.availability.in_stock', true);
});

it('shows only published marketplace catalog products', function () {
    $business = Business::factory()->create();
    createCatalogWarehouse($business);
    createCatalogProduct($business, 'coffee', 'Cafe Cubita', 'CAF-001');
    createCatalogProduct($business, 'sugar', 'Azucar Blanca', 'AZU-001');

    $published = createCatalogPublication($business, 'coffee', 'Cafe premium');
    $draft = createCatalogPublication($business, 'sugar', 'Azucar refinada', MarketplaceProductPublication::StatusDraft);
    seedCatalogStock($business, 'coffee', 'warehouse-ecommerce', 2);

    $this->getJson("/api/marketplace/v1/catalog/products/{$published->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $published->id)
        ->assertJsonPath('data.availability.available', 2);

    $this->getJson("/api/marketplace/v1/catalog/products/{$draft->id}")
        ->assertNotFound();
});

it('searches catalog products by public fields and materialized product fields', function () {
    $business = Business::factory()->create();
    createCatalogWarehouse($business);
    createCatalogProduct($business, 'coffee', 'Cafe Serrano', 'CAF-777');
    createCatalogProduct($business, 'honey', 'Miel Natural', 'MIEL-001');

    createCatalogPublication($business, 'coffee', 'Bebida tostada');
    createCatalogPublication($business, 'honey', 'Endulzante artesanal');

    $response = $this->getJson('/api/marketplace/v1/catalog/products?q=CAF-777');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.external_id', 'coffee');
});

it('filters catalog products by business and active stock availability', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();
    createCatalogWarehouse($business);
    createCatalogWarehouse($otherBusiness);
    createCatalogProduct($business, 'coffee', 'Cafe Cubita', 'CAF-001');
    createCatalogProduct($business, 'sugar', 'Azucar Blanca', 'AZU-001');
    createCatalogProduct($otherBusiness, 'coffee', 'Cafe Otro', 'CAF-002');

    createCatalogPublication($business, 'coffee', 'Cafe premium');
    createCatalogPublication($business, 'sugar', 'Azucar refinada');
    createCatalogPublication($otherBusiness, 'coffee', 'Cafe otro negocio');

    seedCatalogStock($business, 'coffee', 'warehouse-ecommerce', 5);
    seedCatalogStock($business, 'sugar', 'warehouse-ecommerce', 4);
    seedCatalogStock($otherBusiness, 'coffee', 'warehouse-ecommerce', 10);

    $reservation = InventoryReservation::factory()->create([
        'business_id' => $business->id,
        'owner_type' => 'marketplace_quote',
        'owner_id' => 'quote-stock-filter',
        'status' => InventoryReservation::StatusActive,
        'expires_at' => now()->addMinutes(30),
    ]);
    InventoryReservationLine::factory()->create([
        'inventory_reservation_id' => $reservation->id,
        'business_id' => $business->id,
        'product_external_id' => 'sugar',
        'warehouse_external_id' => 'warehouse-ecommerce',
        'quantity' => 4,
    ]);

    $response = $this->getJson("/api/marketplace/v1/catalog/products?business_id={$business->id}&in_stock=1");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.external_id', 'coffee');
});
