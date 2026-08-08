<?php

use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\MarketplaceProductPublication;
use App\Models\MarketplaceQuote;
use App\Models\MasterOrder;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\StockProjection;
use App\Models\Warehouse;

function createOrderWarehouse(Business $business, string $externalId = 'warehouse-ecommerce'): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => 'Almacén eCommerce',
    ]);
}

function createOrderProduct(Business $business, string $externalId, string $title): Product
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

function createOrderPublication(Business $business, string $productExternalId, float $price): MarketplaceProductPublication
{
    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'status' => MarketplaceProductPublication::StatusPublished,
        'public_title' => 'Producto '.$productExternalId,
        'public_description' => 'Producto para orden',
        'public_price' => $price,
        'currency' => 'USD',
        'images' => [],
        'metadata' => [],
    ]);
}

function seedOrderStock(Business $business, string $productExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'qty' => $qty,
    ]);
}

it('confirms a reserved marketplace quote into master and seller orders', function () {
    $business = Business::factory()->create();
    createOrderWarehouse($business);
    createOrderProduct($business, 'coffee', 'Cafe premium');
    $publication = createOrderPublication($business, 'coffee', 12.5);
    seedOrderStock($business, 'coffee', 5);

    $quoteId = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 2],
        ],
    ])->json('data.id');

    $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve")
        ->assertSuccessful();

    $response = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm", [
        'recipient' => ['name' => 'Cliente Demo', 'phone' => '+5355555555'],
        'delivery_address' => ['country' => 'CU', 'province' => 'La Habana', 'street' => 'Calle 1'],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', MasterOrder::StatusConfirmed)
        ->assertJsonPath('data.recipient.name', 'Cliente Demo')
        ->assertJsonPath('data.delivery_address.province', 'La Habana')
        ->assertJsonPath('data.grand_total', 25)
        ->assertJsonCount(1, 'data.seller_orders')
        ->assertJsonPath('data.seller_orders.0.business_id', $business->id)
        ->assertJsonPath('data.seller_orders.0.status', SellerOrder::StatusReserved)
        ->assertJsonPath('data.seller_orders.0.lines.0.product_external_id', 'coffee')
        ->assertJsonPath('data.seller_orders.0.lines.0.warehouse_external_id', 'warehouse-ecommerce');

    expect(MasterOrder::query()->count())->toBe(1)
        ->and(SellerOrder::query()->count())->toBe(1)
        ->and(InventoryReservation::query()->firstOrFail()->status)->toBe(InventoryReservation::StatusActive)
        ->and(MarketplaceQuote::query()->findOrFail($quoteId)->status)->toBe(MarketplaceQuote::StatusConverted);
});

it('creates one seller order per business and exposes the master order by number', function () {
    $firstBusiness = Business::factory()->create();
    $secondBusiness = Business::factory()->create();
    createOrderWarehouse($firstBusiness);
    createOrderWarehouse($secondBusiness);
    createOrderProduct($firstBusiness, 'coffee', 'Cafe premium');
    createOrderProduct($secondBusiness, 'sugar', 'Azucar fina');
    $coffee = createOrderPublication($firstBusiness, 'coffee', 10);
    $sugar = createOrderPublication($secondBusiness, 'sugar', 4);
    seedOrderStock($firstBusiness, 'coffee', 3);
    seedOrderStock($secondBusiness, 'sugar', 8);

    $quoteId = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $coffee->id, 'quantity' => 2],
            ['publication_id' => $sugar->id, 'quantity' => 5],
        ],
    ])->json('data.id');

    $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve")
        ->assertSuccessful();

    $orderNumber = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm")
        ->assertSuccessful()
        ->json('data.order_number');

    $this->getJson("/api/marketplace/v1/orders/{$orderNumber}")
        ->assertSuccessful()
        ->assertJsonPath('data.order_number', $orderNumber)
        ->assertJsonCount(2, 'data.seller_orders')
        ->assertJsonPath('data.grand_total', 40);

    expect(SellerOrder::query()->count())->toBe(2)
        ->and(SellerOrder::query()->where('business_id', $firstBusiness->id)->firstOrFail()->subtotal)->toBe('20.0000')
        ->and(SellerOrder::query()->where('business_id', $secondBusiness->id)->firstOrFail()->subtotal)->toBe('20.0000');
});

it('does not create duplicate orders when confirming the same quote twice', function () {
    $business = Business::factory()->create();
    createOrderWarehouse($business);
    createOrderProduct($business, 'coffee', 'Cafe premium');
    $publication = createOrderPublication($business, 'coffee', 10);
    seedOrderStock($business, 'coffee', 5);

    $quoteId = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 1],
        ],
    ])->json('data.id');

    $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve")
        ->assertSuccessful();

    $firstOrderNumber = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm")
        ->assertSuccessful()
        ->json('data.order_number');
    $secondOrderNumber = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm")
        ->assertSuccessful()
        ->json('data.order_number');

    expect($secondOrderNumber)->toBe($firstOrderNumber)
        ->and(MasterOrder::query()->count())->toBe(1)
        ->and(SellerOrder::query()->count())->toBe(1);
});

it('rejects confirmation when the quote was not reserved', function () {
    $quote = MarketplaceQuote::factory()->create();

    $this->postJson("/api/marketplace/v1/quotes/{$quote->id}/confirm")
        ->assertUnprocessable();

    expect(MasterOrder::query()->count())->toBe(0);
});
