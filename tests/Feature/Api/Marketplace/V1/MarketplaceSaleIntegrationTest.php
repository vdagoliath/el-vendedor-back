<?php

use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\MarketplaceProductPublication;
use App\Models\MasterOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SellerOrder;
use App\Models\StockProjection;
use App\Models\Warehouse;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;

function createSaleIntegrationWarehouse(Business $business, string $externalId = 'warehouse-ecommerce'): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => 'Almacén eCommerce',
    ]);
}

function createSaleIntegrationProduct(Business $business, string $externalId, string $title): Product
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

function createSaleIntegrationPublication(Business $business, string $productExternalId, float $price): MarketplaceProductPublication
{
    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'status' => MarketplaceProductPublication::StatusPublished,
        'public_title' => 'Producto '.$productExternalId,
        'public_description' => 'Producto para venta marketplace',
        'public_price' => $price,
        'currency' => 'USD',
        'images' => [],
        'metadata' => [],
    ]);
}

function seedSaleIntegrationStock(Business $business, string $productExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'qty' => $qty,
    ]);
}

function createSaleIntegrationSellerOrder(mixed $testCase, Business $business, int $quantity = 3): SellerOrder
{
    createSaleIntegrationWarehouse($business);
    createSaleIntegrationProduct($business, 'coffee', 'Cafe premium');
    $publication = createSaleIntegrationPublication($business, 'coffee', 12.5);
    seedSaleIntegrationStock($business, 'coffee', 5);

    $quoteId = $testCase->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => $quantity],
        ],
    ])->json('data.id');

    $testCase->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve")
        ->assertSuccessful();

    $testCase->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm", [
        'recipient' => ['name' => 'Cliente Marketplace'],
        'delivery_address' => ['country' => 'CU', 'province' => 'La Habana'],
    ])->assertSuccessful();

    return SellerOrder::query()->with(['reservation', 'lines'])->firstOrFail();
}

it('accepts a seller order by creating an operative sale and consuming reserved stock once', function () {
    $business = Business::factory()->create();
    $sellerOrder = createSaleIntegrationSellerOrder($this, $business);

    expect(app(InventoryAvailabilityService::class)->availableFor($business->id, 'coffee', 'warehouse-ecommerce'))->toBe(2.0);

    $response = $this->postJson("/api/marketplace/v1/seller-orders/{$sellerOrder->id}/accept");

    $response->assertSuccessful()
        ->assertJsonPath('data.status', MasterOrder::StatusInFulfillment)
        ->assertJsonPath('data.seller_orders.0.status', SellerOrder::StatusAccepted)
        ->assertJsonPath('data.seller_orders.0.lines.0.product_external_id', 'coffee');

    $sellerOrder->refresh();
    $reservation = InventoryReservation::query()->firstOrFail();
    $sale = Sale::query()->with('lines')->firstOrFail();

    expect($sellerOrder->status)->toBe(SellerOrder::StatusAccepted)
        ->and($sellerOrder->sale_id)->toBe($sale->id)
        ->and($sale->external_id)->toBe("marketplace:{$sellerOrder->seller_order_number}")
        ->and($sale->warehouse_external_id)->toBe('warehouse-ecommerce')
        ->and($sale->payment_method)->toBe('marketplace')
        ->and($sale->status)->toBe('pending')
        ->and($sale->lines)->toHaveCount(1)
        ->and((float) $sale->lines->first()->amount)->toBe(3.0)
        ->and((float) StockProjection::query()->firstOrFail()->qty)->toBe(2.0)
        ->and($reservation->status)->toBe(InventoryReservation::StatusConfirmed)
        ->and(app(InventoryAvailabilityService::class)->availableFor($business->id, 'coffee', 'warehouse-ecommerce'))->toBe(2.0);

    $this->postJson("/api/marketplace/v1/seller-orders/{$sellerOrder->id}/accept")
        ->assertSuccessful();

    expect(Sale::query()->count())->toBe(1)
        ->and((float) StockProjection::query()->firstOrFail()->qty)->toBe(2.0);
});

it('does not create a sale when the seller order reservation expired', function () {
    $business = Business::factory()->create();
    $sellerOrder = createSaleIntegrationSellerOrder($this, $business);

    InventoryReservation::query()->firstOrFail()->forceFill([
        'expires_at' => now()->subMinute(),
    ])->save();

    $this->postJson("/api/marketplace/v1/seller-orders/{$sellerOrder->id}/accept")
        ->assertUnprocessable();

    expect(Sale::query()->count())->toBe(0)
        ->and(SellerOrder::query()->firstOrFail()->status)->toBe(SellerOrder::StatusReserved)
        ->and((float) StockProjection::query()->firstOrFail()->qty)->toBe(5.0);
});
