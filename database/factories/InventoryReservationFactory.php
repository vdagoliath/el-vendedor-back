<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\InventoryReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReservation>
 */
class InventoryReservationFactory extends Factory
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
            'owner_type' => 'test_owner',
            'owner_id' => $this->faker->uuid(),
            'status' => InventoryReservation::StatusActive,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryReservation::StatusActive,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => InventoryReservation::StatusReleased,
            'released_at' => now(),
        ]);
    }
}
