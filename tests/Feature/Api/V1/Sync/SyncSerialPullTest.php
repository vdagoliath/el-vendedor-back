<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config()->set('sync.protocol_version', 1);
    config()->set('sync.supported_protocol_versions', [1]);
    config()->set('sync.app_policy', 'same_version');
    config()->set('sync.current_app_version', '1.0.0');
    config()->set('sync.required_app_version', '1.0.0');
});

function serialSyncHeaders(): array
{
    return [
        'X-Sync-Version' => '1',
        'X-Client-App-Version' => '1.0.0',
    ];
}

function setupSerialOwner(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($business);
    $token = $user->createToken('serial-sync-device')->plainTextToken;

    return ['user' => $user, 'business' => $business, 'token' => $token];
}

function pullPage($testCase, string $token, string $deviceId, ?string $cursor = null): array
{
    $url = '/api/v1/sync/pull?device_id='.urlencode($deviceId);
    if ($cursor !== null && $cursor !== '') {
        $url .= '&cursor='.urlencode($cursor);
    }

    $response = $testCase->withToken($token)
        ->withHeaders(serialSyncHeaders())
        ->getJson($url);

    $response->assertOk();

    return [
        'cursor' => (string) $response->json('cursor'),
        'changes' => (array) $response->json('changes'),
        'has_more' => (bool) $response->json('meta.has_more'),
        'served_entity' => $response->json('meta.served_entity'),
        'format' => $response->json('meta.cursor_format'),
    ];
}

test('pull drains products without skipping when contacts share the same updated_at', function () {
    ['business' => $business, 'token' => $token] = setupSerialOwner();

    // Simula el caso del usuario: 3 productos con el mismo updated_at y
    // un contacto con id numérico mayor al de TODOS esos productos. En la
    // estrategia antigua (cursor global tupla), el contacto "envenenaba"
    // el cursor y los productos con id menor quedaban sin servir.
    $sharedTimestamp = Carbon::parse('2026-04-20 12:00:00');

    foreach (['A', 'B', 'C'] as $suffix) {
        $p = new Product;
        $p->timestamps = false;
        $p->forceFill([
            'business_id' => $business->id,
            'external_id' => "product-{$suffix}",
            'code' => "P-{$suffix}",
            'title' => "Producto {$suffix}",
            'type' => 'product',
            'regular_price' => 10,
            'purchase_price' => 5,
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
            'source_created_at' => $sharedTimestamp,
            'source_updated_at' => $sharedTimestamp,
        ])->save();
    }

    // Contacto con updated_at idéntico. Su id autoincremental será mayor
    // que el de los productos (los productos se crearon primero).
    Contact::query()->create([
        'business_id' => $business->id,
        'external_id' => 'contact-noise',
        'name' => 'Ruido',
        'type' => 'customer',
        'created_at' => $sharedTimestamp,
        'updated_at' => $sharedTimestamp,
    ]);

    // Drenamos con limit=2 para forzar paginación dentro de productos.
    $allPulled = collect();
    $cursor = null;
    for ($i = 0; $i < 20; $i++) {
        $response = $this->withToken($token)
            ->withHeaders(serialSyncHeaders())
            ->getJson('/api/v1/sync/pull?device_id=device-serial-1&limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''));

        $response->assertOk();
        $allPulled = $allPulled->concat($response->json('changes'));

        if (! $response->json('meta.has_more')) {
            break;
        }
        $cursor = (string) $response->json('cursor');
    }

    $productIds = $allPulled
        ->filter(fn (array $c): bool => ($c['entity_type'] ?? null) === 'products')
        ->pluck('entity_id')
        ->sort()
        ->values()
        ->all();

    expect($productIds)->toBe(['product-A', 'product-B', 'product-C']);

    $contactIds = $allPulled
        ->filter(fn (array $c): bool => ($c['entity_type'] ?? null) === 'contacts')
        ->pluck('entity_id')
        ->all();

    expect($contactIds)->toContain('contact-noise');
});

test('pull batches multiple entity_types when budget allows', function () {
    ['business' => $business, 'token' => $token] = setupSerialOwner();

    Contact::query()->create([
        'business_id' => $business->id,
        'external_id' => 'c1',
        'name' => 'Cliente Uno',
        'type' => 'customer',
    ]);

    $p = new Product;
    $p->forceFill([
        'business_id' => $business->id,
        'external_id' => 'p1',
        'code' => 'P-1',
        'title' => 'Producto Uno',
        'type' => 'product',
        'regular_price' => 20,
        'purchase_price' => 10,
    ])->save();

    $page = pullPage($this, $token, 'device-batch');

    // Con cupo abundante, un único pull debe agrupar business_profile + contacts
    // + products en el mismo lote.
    expect($page['format'])->toBe('v2-serial');
    $servedEntities = (array) collect($page['changes'])
        ->pluck('entity_type')
        ->unique()
        ->filter(fn (?string $t): bool => $t !== 'license_quote')
        ->values()
        ->all();
    expect($servedEntities)->toContain('business_profile');
    expect($servedEntities)->toContain('contacts');
    expect($servedEntities)->toContain('products');

    // Orden estricto dentro del lote: contacts antes que products.
    $types = collect($page['changes'])
        ->pluck('entity_type')
        ->filter(fn (?string $t): bool => $t !== 'license_quote')
        ->values();
    $contactsIndex = $types->search('contacts');
    $productsIndex = $types->search('products');
    expect($contactsIndex)->toBeLessThan($productsIndex);
});

test('when budget is tight only the first entity gets served and has_more stays true', function () {
    ['business' => $business, 'token' => $token] = setupSerialOwner();

    foreach (['A', 'B'] as $suffix) {
        $c = Contact::query()->create([
            'business_id' => $business->id,
            'external_id' => "c-{$suffix}",
            'name' => "Cliente {$suffix}",
            'type' => 'customer',
        ]);
    }

    $p = new Product;
    $p->forceFill([
        'business_id' => $business->id,
        'external_id' => 'tight-p',
        'code' => 'P-T',
        'title' => 'Producto Tight',
        'type' => 'product',
        'regular_price' => 5,
        'purchase_price' => 2,
    ])->save();

    // limit=1: Business debería ser servido, luego budget = 0 → se corta.
    $response = $this->withToken($token)
        ->withHeaders(serialSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=device-tight&limit=1');

    $response->assertOk();
    expect($response->json('meta.has_more'))->toBeTrue();
    // exact change count depends on whether license_quote counts; verificamos
    // que al menos hay un business_profile servido.
    $businessProfileCount = collect($response->json('changes'))
        ->where('entity_type', 'business_profile')
        ->count();
    expect($businessProfileCount)->toBe(1);
});

test('pull accepts legacy tuple cursor and emits v2-serial cursor afterwards', function () {
    ['business' => $business, 'token' => $token] = setupSerialOwner();

    Contact::query()->create([
        'business_id' => $business->id,
        'external_id' => 'c-legacy',
        'name' => 'Cliente Legacy',
        'type' => 'customer',
    ]);

    $legacyCursor = Carbon::parse('2020-01-01 00:00:00')->toIso8601String().'|0';

    $response = $this->withToken($token)
        ->withHeaders(serialSyncHeaders())
        ->getJson('/api/v1/sync/pull?device_id=device-legacy&cursor='.urlencode($legacyCursor));

    $response->assertOk();
    expect($response->json('meta.cursor_format'))->toBe('v2-serial');
    // El cursor emitido arranca con '{' (JSON), no con la cadena legacy.
    expect(str_starts_with((string) $response->json('cursor'), '{'))->toBeTrue();
});

test('pull eventually marks has_more false after draining all entities', function () {
    ['business' => $business, 'token' => $token] = setupSerialOwner();

    Contact::query()->create([
        'business_id' => $business->id,
        'external_id' => 'c-final',
        'name' => 'Último',
        'type' => 'customer',
    ]);

    $cursor = null;
    $hasMore = true;
    $seenEntities = collect();
    for ($i = 0; $i < 30 && $hasMore; $i++) {
        $page = pullPage($this, $token, 'device-drain', $cursor);
        $cursor = $page['cursor'];
        $hasMore = $page['has_more'];
        $seenEntities = $seenEntities->concat(
            collect($page['changes'])
                ->pluck('entity_type')
                ->unique()
                ->values()
        );
    }

    expect($hasMore)->toBeFalse();
    $unique = $seenEntities->unique()->values()->all();
    expect($unique)->toContain('business_profile');
    expect($unique)->toContain('contacts');
});
