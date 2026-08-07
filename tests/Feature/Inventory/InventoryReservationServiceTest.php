<?php

use App\Models\Business;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use App\Models\StockProjection;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable;

function seedReservableStock(Business $business, string $productExternalId, string $warehouseExternalId, float $qty): void
{
    StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productExternalId,
        'warehouse_external_id' => $warehouseExternalId,
        'qty' => $qty,
    ]);
}

it('reserves inventory without mutating physical stock', function () {
    $business = Business::factory()->create();
    seedReservableStock($business, 'product-a', 'wh-1', 10);

    $reservation = app(InventoryReservationService::class)->reserve(
        $business->id,
        'marketplace_quote',
        'quote-1',
        [
            ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 3],
        ],
        now()->addMinutes(30),
    );

    $availability = app(InventoryAvailabilityService::class);

    expect($reservation->status)->toBe(InventoryReservation::StatusActive)
        ->and($reservation->lines)->toHaveCount(1)
        ->and($availability->availableFor($business->id, 'product-a', 'wh-1'))->toBe(7.0)
        ->and((float) StockProjection::query()->where('product_external_id', 'product-a')->firstOrFail()->qty)->toBe(10.0);
});

it('ignores released confirmed cancelled and expired reservations in availability', function () {
    $business = Business::factory()->create();
    seedReservableStock($business, 'product-a', 'wh-1', 10);
    seedReservableStock($business, 'product-a', 'wh-2', 5);

    $reservations = app(InventoryReservationService::class);
    $active = $reservations->reserve($business->id, 'marketplace_quote', 'quote-active', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 4],
    ], now()->addMinutes(30));
    $released = $reservations->reserve($business->id, 'marketplace_quote', 'quote-released', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 2],
    ], now()->addMinutes(30));
    $confirmed = $reservations->reserve($business->id, 'marketplace_quote', 'quote-confirmed', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 1],
    ], now()->addMinutes(30));
    $cancelled = $reservations->reserve($business->id, 'marketplace_quote', 'quote-cancelled', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-2', 'quantity' => 1],
    ], now()->addMinutes(30));

    $reservations->release($released);
    $reservations->confirm($confirmed);
    $reservations->cancel($cancelled);

    $expired = InventoryReservation::factory()->expired()->create([
        'business_id' => $business->id,
        'owner_type' => 'marketplace_quote',
        'owner_id' => 'quote-expired',
    ]);
    InventoryReservationLine::factory()->create([
        'inventory_reservation_id' => $expired->id,
        'business_id' => $business->id,
        'product_external_id' => 'product-a',
        'warehouse_external_id' => 'wh-1',
        'quantity' => 2,
    ]);

    $availability = app(InventoryAvailabilityService::class);

    expect($active->fresh()->status)->toBe(InventoryReservation::StatusActive)
        ->and($availability->availableFor($business->id, 'product-a'))->toBe(11.0)
        ->and($availability->availableFor($business->id, 'product-a', 'wh-1'))->toBe(6.0)
        ->and($availability->availableFor($business->id, 'product-a', 'wh-2'))->toBe(5.0);
});

it('prevents over-reserving active availability', function () {
    $business = Business::factory()->create();
    seedReservableStock($business, 'product-a', 'wh-1', 5);

    $reservations = app(InventoryReservationService::class);
    $reservations->reserve($business->id, 'marketplace_quote', 'quote-1', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 4],
    ], now()->addMinutes(30));

    expect(fn () => $reservations->reserve($business->id, 'marketplace_quote', 'quote-2', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 2],
    ], now()->addMinutes(30)))->toThrow(InsufficientInventoryAvailable::class);

    expect(InventoryReservation::query()->where('owner_id', 'quote-2')->exists())->toBeFalse()
        ->and(app(InventoryAvailabilityService::class)->availableFor($business->id, 'product-a', 'wh-1'))->toBe(1.0);
});

it('returns an existing active reservation for the same owner', function () {
    $business = Business::factory()->create();
    seedReservableStock($business, 'product-a', 'wh-1', 5);

    $reservations = app(InventoryReservationService::class);
    $first = $reservations->reserve($business->id, 'marketplace_quote', 'quote-1', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 2],
    ], now()->addMinutes(30));
    $second = $reservations->reserve($business->id, 'marketplace_quote', 'quote-1', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 4],
    ], now()->addMinutes(30));

    expect($second->id)->toBe($first->id)
        ->and($second->lines)->toHaveCount(1)
        ->and((float) $second->lines->first()->quantity)->toBe(2.0)
        ->and(app(InventoryAvailabilityService::class)->availableFor($business->id, 'product-a', 'wh-1'))->toBe(3.0);
});

it('expires past due reservations from the command', function () {
    $business = Business::factory()->create();
    seedReservableStock($business, 'product-a', 'wh-1', 5);

    $pastDue = app(InventoryReservationService::class)->reserve($business->id, 'marketplace_quote', 'quote-past-due', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 2],
    ], now()->subMinute());
    $future = app(InventoryReservationService::class)->reserve($business->id, 'marketplace_quote', 'quote-future', [
        ['product_external_id' => 'product-a', 'warehouse_external_id' => 'wh-1', 'quantity' => 1],
    ], now()->addMinutes(30));

    $this->artisan('inventory:expire-reservations')
        ->expectsOutput('Expired 1 inventory reservations.')
        ->assertSuccessful();

    expect($pastDue->fresh()->status)->toBe(InventoryReservation::StatusExpired)
        ->and($future->fresh()->status)->toBe(InventoryReservation::StatusActive);
});
