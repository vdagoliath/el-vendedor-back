<?php

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\PointOfSale;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;

test('analytics users can list points of sale with session aggregates for their business', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $warehouse = Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => 'warehouse-main',
        'name' => 'Almacén principal',
    ]);

    $pos = PointOfSale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'pos-front',
        'name' => 'Caja del frente',
        'warehouse_external_id' => $warehouse->external_id,
        'employees' => [['id' => 'emp-1'], ['id' => 'emp-2']],
    ]);

    CashRegisterSession::factory()
        ->for($business)
        ->create([
            'pos_external_id' => $pos->external_id,
            'warehouse_external_id' => $warehouse->external_id,
            'status' => 'open',
        ]);

    CashRegisterSession::factory()
        ->for($business)
        ->closed()
        ->create([
            'pos_external_id' => $pos->external_id,
            'warehouse_external_id' => $warehouse->external_id,
        ]);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.points-of-sale.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('backoffice/PointsOfSale')
        ->where('stats.points_of_sale_count', 1)
        ->where('stats.open_sessions_count', 1)
        ->where('stats.closed_sessions_count', 1)
        ->where('points_of_sale.0.external_id', 'pos-front')
        ->where('points_of_sale.0.warehouse_name', 'Almacén principal')
        ->where('points_of_sale.0.sessions_total', 2)
        ->where('points_of_sale.0.sessions_open', 1)
        ->where('points_of_sale.0.sessions_closed', 1)
        ->where('points_of_sale.0.employees_count', 2)
        ->whereNotNull('points_of_sale.0.open_session')
    );
});

test('users without analytics permission are forbidden', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.points-of-sale.index'))
        ->assertForbidden();
});

test('point of sale sessions endpoint scopes data to the current business', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    PointOfSale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'pos-mine',
        'name' => 'Caja mía',
    ]);

    PointOfSale::query()->create([
        'business_id' => $otherBusiness->id,
        'external_id' => 'pos-other',
        'name' => 'Caja ajena',
    ]);

    $session = CashRegisterSession::factory()
        ->for($business)
        ->closed()
        ->create([
            'pos_external_id' => 'pos-mine',
        ]);

    Sale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'sale-1',
        'reference' => 'V-001',
        'pos_external_id' => 'pos-mine',
        'cash_register_session_id' => $session->external_id,
        'total' => 150.00,
        'status' => 'completed',
        'transaction_at' => now(),
    ]);

    Sale::query()->create([
        'business_id' => $business->id,
        'external_id' => 'sale-2',
        'reference' => 'V-002',
        'pos_external_id' => 'pos-mine',
        'cash_register_session_id' => $session->external_id,
        'total' => 50.50,
        'status' => 'completed',
        'transaction_at' => now(),
    ]);

    $response = $this->actingAs($superAdmin)
        ->get(route('backoffice.points-of-sale.sessions', 'pos-mine'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('backoffice/PointOfSaleSessions')
        ->where('pointOfSale.external_id', 'pos-mine')
        ->where('stats.sessions_count', 1)
        ->where('stats.closed_count', 1)
        ->where('stats.sales_total', 200.5)
        ->where('sessions.0.sales_count', 2)
        ->where('sessions.0.sales_total', 200.5)
    );

    // POS belonging to another business is not reachable from the current one.
    $this->actingAs($superAdmin)
        ->get(route('backoffice.points-of-sale.sessions', 'pos-other'))
        ->assertNotFound();
});
