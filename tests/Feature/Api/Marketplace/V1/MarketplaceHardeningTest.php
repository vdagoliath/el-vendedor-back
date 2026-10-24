<?php

use App\Events\InventoryReservationExpired;
use App\Events\MarketplaceOrderConfirmed;
use App\Events\MarketplaceQuoteReserved;
use App\Events\MarketplaceSellerOrderAccepted;
use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\MarketplaceProductPublication;
use App\Models\MasterOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SellerOrder;
use App\Models\StockProjection;
use App\Models\Warehouse;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Modules\Marketplace\Operations\MarketplaceOperationalMetricsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

function createHardeningWarehouse(Business $business): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => 'warehouse-ecommerce',
        'name' => 'Almacén eCommerce',
    ]);
}

function createHardeningPublication(Business $business): MarketplaceProductPublication
{
    Product::query()->create([
        'business_id' => $business->id,
        'external_id' => 'coffee',
        'code' => 'COFFEE',
        'title' => 'Cafe premium',
        'regular_price' => 10,
        'purchase_price' => 5,
    ]);

    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => 'coffee',
        'warehouse_external_id' => 'warehouse-ecommerce',
        'qty' => 5,
    ]);

    return MarketplaceProductPublication::query()->create([
        'business_id' => $business->id,
        'product_external_id' => 'coffee',
        'warehouse_external_id' => 'warehouse-ecommerce',
        'status' => MarketplaceProductPublication::StatusPublished,
        'public_title' => 'Cafe premium',
        'public_description' => 'Producto listo para Marketplace',
        'public_price' => 12.5,
        'currency' => 'USD',
        'images' => [],
        'metadata' => [],
    ]);
}

it('stores payment and delivery readiness snapshots when confirming a quote', function () {
    Event::fake([
        MarketplaceQuoteReserved::class,
        MarketplaceOrderConfirmed::class,
        MarketplaceSellerOrderAccepted::class,
    ]);

    $business = Business::factory()->create();
    createHardeningWarehouse($business);
    $publication = createHardeningPublication($business);

    $quoteId = $this->postJson('/api/marketplace/v1/quotes', [
        'lines' => [
            ['publication_id' => $publication->id, 'quantity' => 2],
        ],
    ])->json('data.id');

    $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/reserve")
        ->assertSuccessful();

    Event::assertDispatched(MarketplaceQuoteReserved::class, fn (MarketplaceQuoteReserved $event): bool => $event->quote->id === $quoteId
        && count($event->reservationIds) === 1);

    $orderResponse = $this->postJson("/api/marketplace/v1/quotes/{$quoteId}/confirm", [
        'payment' => [
            'status' => 'authorized',
            'provider' => 'manual',
            'reference' => 'pay-ref-1',
            'amount' => 25,
        ],
        'delivery' => [
            'status' => 'requested',
            'provider' => 'local',
            'method' => 'pickup',
            'reference' => 'del-ref-1',
        ],
    ]);

    $orderResponse->assertSuccessful()
        ->assertJsonPath('data.payment_status', 'authorized')
        ->assertJsonPath('data.delivery_status', 'requested')
        ->assertJsonPath('data.payment.reference', 'pay-ref-1')
        ->assertJsonPath('data.delivery.reference', 'del-ref-1');

    Event::assertDispatched(MarketplaceOrderConfirmed::class, fn (MarketplaceOrderConfirmed $event): bool => $event->masterOrder->payment_status === 'authorized'
        && $event->masterOrder->delivery_status === 'requested');

    $sellerOrder = SellerOrder::query()->firstOrFail();

    $this->postJson("/api/marketplace/v1/seller-orders/{$sellerOrder->id}/accept")
        ->assertSuccessful();

    Event::assertDispatched(MarketplaceSellerOrderAccepted::class, fn (MarketplaceSellerOrderAccepted $event): bool => $event->sellerOrder->id === $sellerOrder->id
        && $event->sale instanceof Sale);

    $snapshot = app(MarketplaceOperationalMetricsService::class)->snapshot();

    expect($snapshot['converted_quotes'])->toBe(1)
        ->and($snapshot['quote_conversion_rate'])->toBe(1.0)
        ->and($snapshot['accepted_seller_orders'])->toBe(1)
        ->and($snapshot['accepted_seller_orders_without_sale'])->toBe(0)
        ->and(MasterOrder::query()->firstOrFail()->payment_snapshot['reference'])->toBe('pay-ref-1');
});

it('reports marketplace health as json', function () {
    MasterOrder::query()->create([
        'order_number' => 'MO-HEALTH-1',
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'pending',
        'subtotal' => 0,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 0,
        'currency' => 'USD',
    ]);

    Artisan::call('marketplace:health', ['--json' => true]);

    $metrics = json_decode(Artisan::output(), true);

    expect($metrics)->toBeArray()
        ->and($metrics['confirmed_orders'])->toBe(1)
        ->and($metrics['pending_payment_orders'])->toBe(1)
        ->and($metrics['pending_delivery_orders'])->toBe(1);
});

it('dispatches an event when past due reservations expire', function () {
    Event::fake([InventoryReservationExpired::class]);

    $reservation = InventoryReservation::factory()->expired()->create();

    $expired = app(InventoryReservationService::class)->expirePastDue();

    expect($expired)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(InventoryReservation::StatusExpired);

    Event::assertDispatched(InventoryReservationExpired::class, fn (InventoryReservationExpired $event): bool => $event->reservation->id === $reservation->id);
});

it('has marketplace hardening columns available', function () {
    expect(Schema::hasColumns('master_orders', [
        'payment_status',
        'delivery_status',
        'payment_snapshot',
        'delivery_snapshot',
    ]))->toBeTrue();
});
