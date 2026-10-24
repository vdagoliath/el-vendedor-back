<?php

namespace Database\Factories;

use App\Models\InventoryReservation;
use App\Models\InventoryReservationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReservationLine>
 */
class InventoryReservationLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_reservation_id' => InventoryReservation::factory(),
            'business_id' => fn (array $attributes): int => InventoryReservation::query()
                ->findOrFail($attributes['inventory_reservation_id'])
                ->business_id,
            'product_external_id' => 'product-'.$this->faker->unique()->bothify('??-###'),
            'warehouse_external_id' => 'warehouse-'.$this->faker->numberBetween(1, 3),
            'quantity' => $this->faker->randomFloat(2, 1, 10),
        ];
    }
}
