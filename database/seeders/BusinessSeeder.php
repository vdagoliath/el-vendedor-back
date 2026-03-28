<?php

namespace Database\Seeders;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@example.com'],
            User::factory()->make([
                'name' => 'Demo Business Owner',
            ])->toArray(),
        );

        $business = Business::query()->firstOrCreate(
            ['slug' => 'demo-store'],
            Business::factory()->make([
                'name' => 'Demo Store',
            ])->toArray(),
        );

        $business->users()->syncWithoutDetaching([
            $owner->id => [
                'role' => BusinessRole::Owner->value,
                'is_active' => true,
            ],
        ]);
    }
}
