<?php

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCurrency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('forbids non super admins from the miraloaki management pages', function () {
    $implementer = User::factory()->backofficeImplementer()->create();

    $this->actingAs($implementer)
        ->get(route('backoffice.marketplace.currencies.index'))
        ->assertForbidden();
});

it('lets a super admin manage currencies with a single default', function () {
    $admin = User::factory()->backofficeSuperAdmin()->create();

    $this->actingAs($admin)
        ->get(route('backoffice.marketplace.currencies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('backoffice/MarketplaceCurrencies'));

    $this->actingAs($admin)->post(route('backoffice.marketplace.currencies.store'), [
        'code' => 'USD',
        'name' => 'Dólar estadounidense (USD)',
        'symbol' => 'US$',
        'decimals' => 2,
        'is_default' => true,
        'sort' => 1,
    ])->assertRedirect(route('backoffice.marketplace.currencies.index'));

    $this->actingAs($admin)->post(route('backoffice.marketplace.currencies.store'), [
        'code' => 'MXN',
        'name' => 'Peso mexicano (MXN)',
        'symbol' => 'MX$',
        'decimals' => 2,
        'is_default' => true,
        'sort' => 2,
    ])->assertRedirect();

    expect(MarketplaceCurrency::query()->where('is_default', true)->pluck('code')->all())->toBe(['MXN']);
});

it('creates and updates categories with an uploaded image', function () {
    $admin = User::factory()->backofficeSuperAdmin()->create();

    $this->actingAs($admin)->post(route('backoffice.marketplace.categories.store'), [
        'slug' => 'alimentos',
        'name' => 'Alimentos',
        'color' => '#f97316',
        'sort' => 1,
        'is_active' => true,
        'image' => UploadedFile::fake()->image('alimentos.jpg', 600, 300),
    ])->assertRedirect(route('backoffice.marketplace.categories.index'));

    $category = MarketplaceCategory::query()->firstOrFail();
    expect($category->image_url)->toStartWith('/marketplace/categories/');

    $imagePath = public_path(ltrim((string) $category->image_url, '/'));
    expect(is_file($imagePath))->toBeTrue();

    $this->actingAs($admin)->put(route('backoffice.marketplace.categories.update', $category), [
        'slug' => 'alimentos',
        'name' => 'Alimentos y granos',
        'color' => '#f97316',
        'sort' => 1,
        'is_active' => true,
        'remove_image' => true,
    ])->assertRedirect();

    expect($category->fresh()->image_url)->toBeNull();
    expect(is_file($imagePath))->toBeFalse();
});

it('shows display rates and lets a super admin refresh them', function () {
    Http::fake([
        'api.qvapay.com/*' => Http::response([
            'BANK_CUP' => ['average' => 120.5, 'updated_at' => '2026-09-09T00:00:00Z'],
        ]),
        'open.er-api.com/*' => Http::response([
            'result' => 'success',
            'rates' => ['CAD' => 1.35, 'MXN' => 19.2],
        ]),
    ]);

    $admin = User::factory()->backofficeSuperAdmin()->create();

    $this->actingAs($admin)
        ->get(route('backoffice.marketplace.rates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backoffice/MarketplaceRates')
            ->where('rates.base', 'USD')
            ->where('rates.rates.CUP', 120.5));

    $this->actingAs($admin)
        ->post(route('backoffice.marketplace.rates.refresh'))
        ->assertRedirect(route('backoffice.marketplace.rates.index'));
});

it('lists consumers with a consumer session and allows revoking them', function () {
    $admin = User::factory()->backofficeSuperAdmin()->create();
    $consumer = User::factory()->create(['email' => 'comprador@example.com']);
    $consumer->createToken('consumer', ['consumer']);
    User::factory()->create(['email' => 'vendedor@example.com']);

    $this->actingAs($admin)
        ->get(route('backoffice.marketplace.consumers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backoffice/MarketplaceConsumers')
            ->has('consumers.data', 1)
            ->where('consumers.data.0.email', 'comprador@example.com'));

    $this->actingAs($admin)
        ->post(route('backoffice.marketplace.consumers.revoke', $consumer))
        ->assertRedirect(route('backoffice.marketplace.consumers.index'));

    expect($consumer->tokens()->where('name', 'consumer')->count())->toBe(0);
});
