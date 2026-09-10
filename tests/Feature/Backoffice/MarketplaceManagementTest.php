<?php

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCurrency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
