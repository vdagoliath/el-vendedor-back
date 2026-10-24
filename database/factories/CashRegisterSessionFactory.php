<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashRegisterSession>
 */
class CashRegisterSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'external_id' => 'session_'.Str::uuid()->toString(),
            'pos_external_id' => 'pos_'.Str::uuid()->toString(),
            'warehouse_external_id' => 'warehouse_'.Str::uuid()->toString(),
            'status' => 'open',
            'opened_at' => now(),
            'closed_at' => null,
            'opening_balance' => $this->faker->randomFloat(2, 0, 1000),
            'closing_balance' => null,
            'opened_by' => [
                'id' => 'user-'.$this->faker->uuid(),
                'role' => 'seller',
                'name' => $this->faker->name(),
                'deviceId' => $this->faker->uuid(),
                'deviceName' => $this->faker->words(2, true),
            ],
            'closed_by' => null,
            'initial_inventory_snapshot' => null,
            'final_inventory_snapshot' => null,
            'source_created_at' => now(),
            'source_updated_at' => now(),
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'closed',
            'closed_at' => now(),
            'closing_balance' => $this->faker->randomFloat(2, 0, 2000),
            'closed_by' => [
                'id' => 'user-'.$this->faker->uuid(),
                'role' => 'seller',
                'name' => $this->faker->name(),
                'deviceId' => $this->faker->uuid(),
                'deviceName' => $this->faker->words(2, true),
            ],
        ]);
    }
}
