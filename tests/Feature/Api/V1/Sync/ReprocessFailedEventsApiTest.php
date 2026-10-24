<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SyncReceivedEvent;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->business = Business::factory()->create();

    $this->user->businesses()->attach($this->business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $this->user->switchCurrentBusiness($this->business);

    $ownerAccess = $this->user->createToken('reprocess-owner', ['sync:owner']);
    $ownerAccess->accessToken->forceFill(['business_id' => $this->business->id])->save();
    $this->ownerToken = $ownerAccess->plainTextToken;

    $sellerAccess = $this->user->createToken('reprocess-seller', ['sync:seller']);
    $sellerAccess->accessToken->forceFill(['business_id' => $this->business->id])->save();
    $this->sellerToken = $sellerAccess->plainTextToken;
});

test('summary returns per-entity counts of failed events', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-failed-1',
        'entity_type' => 'products',
        'entity_id' => 'prod-1',
        'operation' => 'upsert',
        'payload' => null,
        'status' => 'failed',
    ]);
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-failed-2',
        'entity_type' => 'contacts',
        'entity_id' => 'c-1',
        'operation' => 'upsert',
        'payload' => null,
        'status' => 'failed',
    ]);
    // Un evento ya aplicado que no debería aparecer en el summary.
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-ok',
        'entity_type' => 'products',
        'entity_id' => 'prod-ok',
        'operation' => 'upsert',
        'payload' => null,
        'status' => 'applied',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->getJson('/api/v1/sync/failed-events');

    $response
        ->assertOk()
        ->assertJsonPath('total_failed', 2)
        ->assertJsonPath('per_entity.products', 1)
        ->assertJsonPath('per_entity.contacts', 1);
});

test('reprocess replays a failed product event into the products table', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-prod-replay',
        'entity_type' => 'products',
        'entity_id' => 'prod-replay',
        'operation' => 'upsert',
        'payload' => [
            'code' => 'PR-1',
            'title' => 'Producto Replay',
            'regular_price' => 15,
            'purchase_price' => 10,
            'stockByWarehouse' => [],
        ],
        'status' => 'failed',
        'error_message' => 'Timeout original',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/sync/reprocess-failed-events', ['entity_types' => ['products']]);

    $response
        ->assertOk()
        ->assertJsonPath('attempted', 1)
        ->assertJsonPath('applied', 1)
        ->assertJsonPath('still_failed', 0)
        ->assertJsonPath('per_entity.products.applied', 1);

    expect(Product::query()->where('external_id', 'prod-replay')->exists())->toBeTrue();

    $event = SyncReceivedEvent::query()->where('event_id', 'evt-prod-replay')->firstOrFail();
    expect($event->status)->toBe('applied');
    expect($event->error_message)->toBeNull();
});

test('reprocess replays a failed contact event via SyncEntityApplier', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-contact-replay',
        'entity_type' => 'contacts',
        'entity_id' => 'contact-replay',
        'operation' => 'upsert',
        'payload' => [
            'name' => 'Cliente Replay',
            'mobile' => '5551234',
            'type' => 'customer',
        ],
        'status' => 'failed',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/sync/reprocess-failed-events');

    $response
        ->assertOk()
        ->assertJsonPath('applied', 1);

    expect(Contact::query()->where('external_id', 'contact-replay')->exists())->toBeTrue();
});

test('reprocess keeps events in failed state when the replay still errors out', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-bad-product',
        'entity_type' => 'products',
        'entity_id' => 'prod-bad',
        'operation' => 'upsert',
        'payload' => [
            // Falta `code` → applyProductChange tira excepción.
            'title' => 'Producto Incompleto',
            'regular_price' => 0,
            'purchase_price' => 0,
        ],
        'status' => 'failed',
        'error_message' => 'original error',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/sync/reprocess-failed-events');

    $response
        ->assertOk()
        ->assertJsonPath('attempted', 1)
        ->assertJsonPath('applied', 0)
        ->assertJsonPath('still_failed', 1);

    $event = SyncReceivedEvent::query()->where('event_id', 'evt-bad-product')->firstOrFail();
    expect($event->status)->toBe('failed');
    expect($event->error_message)->toContain('codigo');
});

test('reprocess can be filtered by event_ids', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-A',
        'entity_type' => 'contacts',
        'entity_id' => 'ca',
        'operation' => 'upsert',
        'payload' => ['name' => 'A', 'type' => 'customer'],
        'status' => 'failed',
    ]);
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-B',
        'entity_type' => 'contacts',
        'entity_id' => 'cb',
        'operation' => 'upsert',
        'payload' => ['name' => 'B', 'type' => 'customer'],
        'status' => 'failed',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/sync/reprocess-failed-events', [
            'event_ids' => ['evt-A'],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('attempted', 1)
        ->assertJsonPath('applied', 1);

    expect(Contact::query()->where('external_id', 'ca')->exists())->toBeTrue();
    expect(Contact::query()->where('external_id', 'cb')->exists())->toBeFalse();
});

test('sellers cannot call the reprocess endpoint', function () {
    SyncReceivedEvent::query()->create([
        'business_id' => $this->business->id,
        'event_id' => 'evt-seller',
        'entity_type' => 'contacts',
        'entity_id' => 'c-seller',
        'operation' => 'upsert',
        'payload' => ['name' => 'S', 'type' => 'customer'],
        'status' => 'failed',
    ]);

    $response = $this->withToken($this->sellerToken)
        ->postJson('/api/v1/sync/reprocess-failed-events');

    $response->assertForbidden();
});

test('unauthenticated requests are rejected', function () {
    $this->postJson('/api/v1/sync/reprocess-failed-events')->assertUnauthorized();
    $this->getJson('/api/v1/sync/failed-events')->assertUnauthorized();
});
