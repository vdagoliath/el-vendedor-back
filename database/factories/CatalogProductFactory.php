<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogProduct>
 */
class CatalogProductFactory extends Factory
{
    protected $model = CatalogProduct::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_external_id' => 'product-'.$this->faker->unique()->bothify('??-####'),
            'slug' => 'producto-'.$this->faker->unique()->numerify('#####'),
            'title' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 1, 500),
            'currency' => 'CUP',
            'unit' => 'unidad',
            'category_name' => 'Alimentos',
            'category_emoji' => '🍚',
            'images' => [],
            'is_visible' => true,
            'is_featured' => false,
            'is_available' => true,
            'sort' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }
}
