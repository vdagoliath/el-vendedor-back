<?php

use App\Models\Business;
use App\Models\MasterOrder;
use App\Models\SellerOrder;
use App\Models\SellerOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists marketplace orders by consumer id', function (): void {
    $business = Business::factory()->create();

    $first = MasterOrder::query()->create([
        'order_number' => 'MO-INDEX-001',
        'consumer_id' => 42,
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'requested',
        'recipient_snapshot' => ['name' => 'Daniel'],
        'delivery_address_snapshot' => ['municipality' => 'Habana'],
        'subtotal' => 10,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 10,
        'currency' => 'USD',
    ]);

    MasterOrder::query()->create([
        'order_number' => 'MO-INDEX-002',
        'consumer_id' => 99,
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'requested',
        'subtotal' => 8,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 8,
        'currency' => 'USD',
    ]);

    $sellerOrder = SellerOrder::query()->create([
        'master_order_id' => $first->id,
        'business_id' => $business->id,
        'seller_order_number' => 'SO-INDEX-001',
        'status' => SellerOrder::StatusReserved,
        'subtotal' => 10,
        'currency' => 'USD',
    ]);

    SellerOrderLine::query()->create([
        'seller_order_id' => $sellerOrder->id,
        'product_external_id' => 'product-1',
        'warehouse_external_id' => 'ecommerce_warehouse',
        'title_snapshot' => 'Producto uno',
        'unit_price' => 10,
        'quantity' => 1,
        'subtotal' => 10,
    ]);

    $this->getJson('/api/marketplace/v1/orders?consumer_id=42')
        ->assertOk()
        ->assertJsonPath('data.0.order_number', 'MO-INDEX-001')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.seller_orders.0.lines.0.title', 'Producto uno');
});

it('lists marketplace orders by order numbers', function (): void {
    MasterOrder::query()->create([
        'order_number' => 'MO-INDEX-003',
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'requested',
        'subtotal' => 12,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 12,
        'currency' => 'USD',
    ]);

    MasterOrder::query()->create([
        'order_number' => 'MO-INDEX-004',
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'requested',
        'subtotal' => 14,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 14,
        'currency' => 'USD',
    ]);

    $this->getJson('/api/marketplace/v1/orders?order_numbers[]=MO-INDEX-004')
        ->assertOk()
        ->assertJsonPath('data.0.order_number', 'MO-INDEX-004')
        ->assertJsonCount(1, 'data');
});

it('does not list marketplace orders without an order filter', function (): void {
    MasterOrder::query()->create([
        'order_number' => 'MO-INDEX-005',
        'consumer_id' => 7,
        'status' => MasterOrder::StatusConfirmed,
        'payment_status' => 'pending',
        'delivery_status' => 'requested',
        'subtotal' => 20,
        'delivery_total' => 0,
        'fees_total' => 0,
        'grand_total' => 20,
        'currency' => 'USD',
    ]);

    $this->getJson('/api/marketplace/v1/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
