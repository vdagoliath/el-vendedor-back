<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\MarketplaceProductPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceProductPublication>
 */
class MarketplaceProductPublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_external_id' => 'product-'.$this->faker->unique()->bothify('??-###'),
            'warehouse_external_id' => 'warehouse-ecommerce',
            'status' => MarketplaceProductPublication::StatusPublished,
            'public_title' => $this->faker->words(3, true),
            'public_description' => $this->faker->sentence(),
            'public_price' => $this->faker->randomFloat(2, 1, 500),
            'currency' => 'USD',
            'images' => [],
            'metadata' => [],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => MarketplaceProductPublication::StatusDraft,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => MarketplaceProductPublication::StatusPaused,
        ]);
    }
}
