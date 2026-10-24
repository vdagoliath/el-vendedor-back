<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CatalogStorefront;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogStorefront>
 */
class CatalogStorefrontFactory extends Factory
{
    protected $model = CatalogStorefront::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'subdomain' => 'tienda-'.$this->faker->unique()->numerify('#####'),
            'display_name' => $this->faker->company(),
            'tagline' => $this->faker->sentence(4),
            'about' => $this->faker->paragraph(),
            'accent_color' => '#0F766E',
            'whatsapp' => '5355'.$this->faker->numerify('#####'),
            'phone' => '+53 55 '.$this->faker->numerify('#####'),
            'address' => $this->faker->streetAddress(),
            'city' => 'La Habana',
            'hours' => [
                ['day' => 1, 'open' => '09:00', 'close' => '18:00'],
                ['day' => 2, 'open' => '09:00', 'close' => '18:00'],
            ],
            'social' => [],
            'currency' => 'CUP',
            'currency_symbol' => null,
            'template' => 'clasico',
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
