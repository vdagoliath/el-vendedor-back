<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->make([
                'name' => 'Test User',
            ])->toArray(),
        );

        $this->call(BusinessSeeder::class);
    }
}
