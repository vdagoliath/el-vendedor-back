<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessSequence;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Sync\ServerVersionAllocator;

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
});

function versionSyncHeaders(): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => '1.0.0',
    ];
}

test('the allocator hands out monotonic versions per business and persists last_version', function () {
    $allocator = app(ServerVersionAllocator::class);

    $first = $allocator->next($this->business->id);
    $second = $allocator->next($this->business->id);
    $third = $allocator->next($this->business->id);

    expect($second)->toBe($first + 1)
        ->and($third)->toBe($second + 1);

    $row = BusinessSequence::query()->where('business_id', $this->business->id)->firstOrFail();
    expect((int) $row->last_version)->toBe($third);
});

test('saving a versioned model assigns server_version automatically', function () {
    $category = Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-A',
        'name' => 'Bebidas',
    ]);

    expect((int) $category->server_version)->toBeGreaterThan(0);

    // Updating with a real change advances the version
    $previousVersion = (int) $category->server_version;
    $category->name = 'Bebidas frias';
    $category->save();

    expect((int) $category->server_version)->toBeGreaterThan($previousVersion);
});

test('saving without dirty fields does not waste a version', function () {
    $category = Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-B',
        'name' => 'Limpieza',
    ]);

    $startVersion = (int) $category->server_version;

    // Touching save without changes shouldn't reallocate
    $category->save();

    expect((int) $category->fresh()->server_version)->toBe($startVersion);
});

test('pull with cursor v3 returns changes ordered by server_version', function () {
    Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-1',
        'name' => 'Cat 1',
    ]);
    Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-2',
        'name' => 'Cat 2',
    ]);
    Product::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'prod-1',
        'code' => 'P-1',
        'title' => 'Producto 1',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device&cursor='.urlencode('v3:0'));

    $response
        ->assertOk()
        ->assertJsonPath('meta.cursor_format', 'v3-server-version')
        ->assertJsonPath('meta.has_more', false);

    // license_quote viaja inline y no carga `server_version`; lo
    // descartamos de las aserciones de orden.
    $versionedChanges = collect($response->json('changes'))
        ->filter(fn ($change) => isset($change['server_version']))
        ->values();
    $versions = $versionedChanges->pluck('server_version')->all();

    expect($versions)->not->toBeEmpty()
        ->and($versions === array_values(collect($versions)->sort()->values()->all()))->toBeTrue();

    // El cursor avanzado debe ser v3:<max version vista>
    $cursor = $response->json('cursor');
    expect($cursor)->toStartWith('v3:');
    $cursorVersion = (int) substr($cursor, 3);
    expect($cursorVersion)->toBe(max($versions));
});

test('a second pull with the returned cursor only delivers newer changes', function () {
    Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-x',
        'name' => 'X',
    ]);

    $first = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device&cursor='.urlencode('v3:0'));
    $first->assertOk();

    $cursor = $first->json('cursor');

    // Crear un cambio nuevo después del primer pull
    Product::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'prod-after',
        'code' => 'PA',
        'title' => 'Después',
    ]);

    $second = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device&cursor='.urlencode($cursor));

    $second->assertOk();
    $changes = $second->json('changes');

    // Sólo debería traer el producto nuevo (las categorías vienen antes del cursor)
    expect(collect($changes)->pluck('entity_id')->all())->toContain('prod-after');
    expect(collect($changes)->where('entity_id', 'cat-x')->all())->toBeEmpty();
});

test('pull respects the limit parameter and signals has_more', function () {
    for ($i = 0; $i < 5; $i++) {
        Category::query()->create([
            'business_id' => $this->business->id,
            'external_id' => "cat-{$i}",
            'name' => "Cat {$i}",
        ]);
    }

    $response = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device&limit=3&cursor='.urlencode('v3:0'));

    $response
        ->assertOk()
        ->assertJsonPath('meta.has_more', true);

    // El limit de 3 aplica al contenido versionado; license_quote viaja
    // inline aparte del cupo.
    $versionedCount = collect($response->json('changes'))
        ->filter(fn ($c) => isset($c['server_version']))
        ->count();
    expect($versionedCount)->toBe(3);
});

test('legacy pull cursor still works and is not affected by v3 changes', function () {
    Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-legacy',
        'name' => 'Legacy',
    ]);

    $response = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device');

    $response
        ->assertOk()
        ->assertJsonPath('meta.cursor_format', 'v2-serial');

    expect($response->json('changes'))->not->toBeEmpty();
});

test('bootstrap announces both cursor formats', function () {
    $response = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/bootstrap');

    $response
        ->assertOk()
        ->assertJsonPath('pull_contract.cursor_formats.0', 'v2-serial')
        ->assertJsonPath('pull_contract.cursor_formats.1', 'v3-server-version');
});

test('changes from different entities are interleaved by version, not grouped', function () {
    $cat1 = Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-a',
        'name' => 'A',
    ]);
    $prod1 = Product::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'prod-a',
        'code' => 'PA',
        'title' => 'A',
    ]);
    $cat2 = Category::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'cat-b',
        'name' => 'B',
    ]);
    $prod2 = Product::query()->create([
        'business_id' => $this->business->id,
        'external_id' => 'prod-b',
        'code' => 'PB',
        'title' => 'B',
    ]);

    expect((int) $cat1->server_version)->toBeLessThan((int) $prod1->server_version);
    expect((int) $prod1->server_version)->toBeLessThan((int) $cat2->server_version);
    expect((int) $cat2->server_version)->toBeLessThan((int) $prod2->server_version);

    $response = $this->withToken($this->ownerToken)
        ->withHeaders(versionSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=test-device&cursor='.urlencode('v3:0'));

    $response->assertOk();
    $entityIds = collect($response->json('changes'))
        ->whereIn('entity_id', ['cat-a', 'prod-a', 'cat-b', 'prod-b'])
        ->pluck('entity_id')
        ->values()
        ->all();

    expect($entityIds)->toBe(['cat-a', 'prod-a', 'cat-b', 'prod-b']);
});
