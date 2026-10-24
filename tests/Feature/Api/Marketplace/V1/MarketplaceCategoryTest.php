<?php

use App\Models\MarketplaceCategory;
use Database\Seeders\MarketplaceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists active backend managed categories ordered by sort', function () {
    (new MarketplaceCategorySeeder)->run();

    $response = $this->getJson('/api/marketplace/v1/categories')->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())->toBe([
        'alimentos',
        'carnes-y-congelados',
        'bebidas',
        'higiene',
        'limpieza',
        'hogar',
        'bebe',
        'farmacia',
        'combos',
    ]);
});

it('hides inactive categories and exposes the managed image', function () {
    $category = MarketplaceCategory::query()->create([
        'slug' => 'alimentos',
        'name' => 'Alimentos',
        'image_url' => 'https://cdn.example.com/alimentos.webp',
        'color' => '#f97316',
        'sort' => 1,
        'is_active' => true,
    ]);

    MarketplaceCategory::query()->create([
        'slug' => 'oculta',
        'name' => 'Oculta',
        'sort' => 2,
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/marketplace/v1/categories')->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['alimentos']);
    expect($response->json('data.0.image_url'))->toBe(url($category->image_url));
    expect($response->json('data.0.image_url'))->toStartWith('http');
});
