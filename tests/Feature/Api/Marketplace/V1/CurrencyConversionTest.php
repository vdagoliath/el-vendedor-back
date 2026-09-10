<?php

use App\Models\Business;
use App\Models\MarketplaceProductPublication;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function ccBusiness(): Business
{
    return Business::factory()->create();
}

function ccPublication(Business $business, string $currency = 'USD', float $price = 10.0): MarketplaceProductPublication
{
    Product::query()->create([
        'business_id' => $business->id,
        'external_id' => 'ext-'.uniqid(),
        'code' => 'CC-'.uniqid(),
        'title' => 'Producto conversion',
        'regular_price' => $price,
        'purchase_price' => $price / 2,
    ]);
    $product = Product::query()->latest('id')->firstOrFail();

    Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => 'warehouse-ecommerce',
        'name' => 'Almacén eCommerce',
    ]);

    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $product->external_id,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'qty' => 10,
    ]);

    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $product->external_id,
        'warehouse_external_id' => 'warehouse-ecommerce',
        'status' => MarketplaceProductPublication::StatusPublished,
        'public_title' => 'Producto conversion',
        'public_price' => $price,
        'currency' => $currency,
    ]);
}

function ccFakeRates(): void
{
    Http::fake([
        'api.qvapay.com/*' => Http::response(['BANK_CUP' => ['average' => 120]]),
        'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MXN' => 20, 'CAD' => 1.5]]),
    ]);
}

it('converts catalog prices to the requested operating currency', function () {
    ccFakeRates();
    $business = ccBusiness();
    ccPublication($business, 'USD', 10.0);

    $this->getJson('/api/marketplace/v1/catalog/products?currency=MXN')
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'MXN')
        ->assertJsonPath('data.0.price', 200);

    $this->getJson('/api/marketplace/v1/catalog/products?currency=USD')
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'USD')
        ->assertJsonPath('data.0.price', 10);
});

it('keeps the original price when the rate is unavailable', function () {
    ccFakeRates();
    $business = ccBusiness();
    ccPublication($business, 'USD', 10.0);

    $this->getJson('/api/marketplace/v1/catalog/products?currency=GBP')
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'USD')
        ->assertJsonPath('data.0.price', 10);
});

it('converts the publication detail price as well', function () {
    ccFakeRates();
    $business = ccBusiness();
    $publication = ccPublication($business, 'USD', 10.0);

    $this->getJson("/api/marketplace/v1/catalog/products/{$publication->id}?currency=CAD")
        ->assertOk()
        ->assertJsonPath('data.currency', 'CAD')
        ->assertJsonPath('data.price', 15);
});
