<?php

namespace Database\Seeders;

use App\Models\MarketplaceCategory;
use Illuminate\Database\Seeder;

class MarketplaceCategorySeeder extends Seeder
{
    /**
     * Categorias de navegacion del marketplace. `image_url` queda null para que
     * un administrador suba la imagen definitiva; el frontend usa un fallback
     * local por slug mientras tanto.
     */
    public function run(): void
    {
        $categories = [
            ['slug' => 'alimentos', 'name' => 'Alimentos', 'color' => '#f97316'],
            ['slug' => 'carnes-y-congelados', 'name' => 'Carnes y congelados', 'color' => '#dc2626'],
            ['slug' => 'bebidas', 'name' => 'Bebidas', 'color' => '#7c3aed'],
            ['slug' => 'higiene', 'name' => 'Higiene', 'color' => '#0891b2'],
            ['slug' => 'limpieza', 'name' => 'Limpieza', 'color' => '#0284c7'],
            ['slug' => 'hogar', 'name' => 'Hogar', 'color' => '#1e293b'],
            ['slug' => 'bebe', 'name' => 'Bebé', 'color' => '#16a34a'],
            ['slug' => 'farmacia', 'name' => 'Farmacia', 'color' => '#2563eb'],
            ['slug' => 'combos', 'name' => 'Combos', 'color' => '#f59e0b'],
        ];

        foreach ($categories as $index => $category) {
            MarketplaceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [
                    ...$category,
                    'sort' => $index + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
