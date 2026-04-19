<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\PointOfSale;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');

    $this->user = User::factory()->create();
    $this->business = Business::factory()->create();

    $this->user->businesses()->attach($this->business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $this->user->switchCurrentBusiness($this->business);

    $owner = $this->user->createToken('owner-device', ['sync:owner']);
    $owner->accessToken->forceFill(['business_id' => $this->business->id])->save();
    $this->ownerToken = $owner->plainTextToken;

    $seller = $this->user->createToken('seller-device', ['sync:seller']);
    $seller->accessToken->forceFill(['business_id' => $this->business->id])->save();
    $this->sellerToken = $seller->plainTextToken;

    $this->pos = PointOfSale::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'pos-1',
        'name' => 'POS Principal',
        'warehouse_external_id' => 'warehouse-1',
    ]);
});

test('seller can read active session for a POS (returns null when none open)', function () {
    $response = $this->withToken($this->sellerToken)
        ->getJson("/api/v1/cash-register/pos/{$this->pos->external_id}/active-session");

    $response->assertOk()->assertJsonPath('session', null);
});

test('seller can open a session for a POS', function () {
    $response = $this->withToken($this->sellerToken)
        ->postJson("/api/v1/cash-register/pos/{$this->pos->external_id}/open-session", [
            'external_id' => 'session-uuid-1',
            'opening_balance' => 250.00,
            'opened_by' => [
                'id' => 'emp-1',
                'role' => 'seller',
                'name' => 'Ana',
                'deviceId' => 'dev-1',
                'deviceName' => 'Tablet POS',
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('joined', false)
        ->assertJsonPath('session.external_id', 'session-uuid-1')
        ->assertJsonPath('session.pos_external_id', $this->pos->external_id)
        ->assertJsonPath('session.warehouse_external_id', 'warehouse-1')
        ->assertJsonPath('session.status', 'open')
        ->assertJsonPath('session.opening_balance', 250)
        ->assertJsonPath('session.opened_by.name', 'Ana');

    expect(CashRegisterSession::query()->where('external_id', 'session-uuid-1')->exists())->toBeTrue();
});

test('opening with the same external_id again is idempotent', function () {
    CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-2',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
    ])->create();

    $response = $this->withToken($this->sellerToken)
        ->postJson("/api/v1/cash-register/pos/{$this->pos->external_id}/open-session", [
            'external_id' => 'session-uuid-2',
            'opening_balance' => 100.00,
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('joined', false)
        ->assertJsonPath('session.external_id', 'session-uuid-2');
});

test('opening with a different external_id when one is already open returns the existing session as joined', function () {
    CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-existing',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
    ])->create();

    $response = $this->withToken($this->sellerToken)
        ->postJson("/api/v1/cash-register/pos/{$this->pos->external_id}/open-session", [
            'external_id' => 'session-uuid-new',
            'opening_balance' => 100.00,
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('joined', true)
        ->assertJsonPath('session.external_id', 'session-uuid-existing');

    expect(CashRegisterSession::query()->where('external_id', 'session-uuid-new')->exists())->toBeFalse();
});

test('active-session returns the open session when present', function () {
    CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-3',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
    ])->create();

    $response = $this->withToken($this->sellerToken)
        ->getJson("/api/v1/cash-register/pos/{$this->pos->external_id}/active-session");

    $response
        ->assertOk()
        ->assertJsonPath('session.external_id', 'session-uuid-3')
        ->assertJsonPath('session.status', 'open');
});

test('any seller can close an open session', function () {
    $session = CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-4',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
    ])->create();

    $response = $this->withToken($this->sellerToken)
        ->postJson("/api/v1/cash-register/sessions/{$session->external_id}/close", [
            'closing_balance' => 800.00,
            'closed_by' => [
                'id' => 'emp-2',
                'role' => 'seller',
                'name' => 'Beto',
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('session.status', 'closed')
        ->assertJsonPath('session.closing_balance', 800)
        ->assertJsonPath('session.closed_by.name', 'Beto');
});

test('owner can close a session remotely with role recorded', function () {
    $session = CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-5',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
    ])->create();

    $response = $this->withToken($this->ownerToken)
        ->postJson("/api/v1/cash-register/sessions/{$session->external_id}/close", [
            'closing_balance' => 500.00,
            'closed_by' => [
                'id' => 'owner-1',
                'role' => 'owner',
                'name' => 'Dueño',
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('session.closed_by.role', 'owner');
});

test('closing an already closed session returns 409', function () {
    $session = CashRegisterSession::factory()->closed()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-6',
        'pos_external_id' => $this->pos->external_id,
    ])->create();

    $response = $this->withToken($this->sellerToken)
        ->postJson("/api/v1/cash-register/sessions/{$session->external_id}/close", [
            'closing_balance' => 100.00,
        ]);

    $response
        ->assertStatus(409)
        ->assertJsonPath('code', 'cash_register_session_already_closed')
        ->assertJsonPath('session.status', 'closed');
});

test('summary aggregates totals from sales linked to the session', function () {
    $session = CashRegisterSession::factory()->state([
        'business_id' => $this->business->id,
        'external_id' => 'session-uuid-7',
        'pos_external_id' => $this->pos->external_id,
        'status' => 'open',
        'opening_balance' => 200.00,
    ])->create();

    Sale::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'sale-1',
        'cash_register_session_id' => $session->external_id,
        'total' => 100.00,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);
    Sale::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'sale-2',
        'cash_register_session_id' => $session->external_id,
        'total' => 50.00,
        'status' => 'completed',
        'payment_method' => 'card',
    ]);
    Sale::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'sale-3',
        'cash_register_session_id' => $session->external_id,
        'total' => 30.00,
        'status' => 'credit',
        'payment_method' => 'credit',
    ]);
    Sale::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'sale-cancelled',
        'cash_register_session_id' => $session->external_id,
        'total' => 999.00,
        'status' => 'canceled',
        'payment_method' => 'cash',
    ]);

    $response = $this->withToken($this->sellerToken)
        ->getJson("/api/v1/cash-register/sessions/{$session->external_id}/summary");

    $response
        ->assertOk()
        ->assertJsonPath('summary.sales_count', 3)
        ->assertJsonPath('summary.sales_total', 180)
        ->assertJsonPath('summary.totals_by_method.cash', 100)
        ->assertJsonPath('summary.totals_by_method.card', 50)
        ->assertJsonPath('summary.totals_by_method.credit', 30)
        ->assertJsonPath('summary.expected_balance', 300)
        ->assertJsonPath('summary.discrepancy', null);
});

test('opening for a POS that does not belong to the current business returns 404', function () {
    $response = $this->withToken($this->sellerToken)
        ->postJson('/api/v1/cash-register/pos/pos-not-existing/open-session', [
            'external_id' => 'session-uuid-x',
            'opening_balance' => 0,
        ]);

    $response->assertNotFound();
});

test('endpoints require an authenticated token with sync ability', function () {
    $this->getJson("/api/v1/cash-register/pos/{$this->pos->external_id}/active-session")
        ->assertUnauthorized();
});

test('sellers cannot push cash_register_sessions through the generic sync push', function () {
    $response = $this->withToken($this->sellerToken)
        ->withHeaders([
            'X-Sync-Version' => '1',
            'X-Client-App-Version' => '1.0.0',
        ])
        ->postJson('/api/v1/sync/push', [
            'device' => [
                'id' => 'device-seller-cr-1',
                'app_version' => '1.0.0',
            ],
            'changes' => [
                [
                    'event_id' => 'evt-cr-1',
                    'entity_type' => 'cash_register_sessions',
                    'entity_id' => 'session-x',
                    'operation' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => ['status' => 'open'],
                ],
            ],
        ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('rejected.0.entity_type', 'cash_register_sessions')
        ->assertJsonPath('rejected.0.code', 'sync_entity_forbidden');
});
