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
            User::factory()->raw([
                'name' => 'Demo Business Owner',
                'email' => 'owner@example.com',
            ]),
        );

        $business = Business::query()->firstOrCreate(
            ['slug' => 'demo-store'],
            Business::factory()->raw([
                'name' => 'Demo Store',
            ]),
        );

        $business->users()->syncWithoutDetaching([
            $owner->id => [
                'role' => BusinessRole::Owner->value,
                'is_active' => true,
            ],
        ]);
    }
}
