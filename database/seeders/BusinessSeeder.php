<?php

namespace Database\Seeders;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@example.com'],
            [
                'name' => 'Demo Business Owner',
                'email' => 'owner@example.com',
                'locale' => config('app.locale'),
                'email_verified_at' => now(),
                'backoffice_role' => null,
                'is_platform_admin' => false,
                'password' => Hash::make('password'),
                'remember_token' => Str::random(10),
            ],
        );

        $business = Business::query()->firstOrCreate(
            ['slug' => 'demo-store'],
            [
                'name' => 'Demo Store',
                'address' => null,
                'phone' => null,
                'default_currency' => null,
                'license_expires_at' => null,
                'is_active' => true,
            ],
        );

        $business->users()->syncWithoutDetaching([
            $owner->id => [
                'role' => BusinessRole::Owner->value,
                'is_active' => true,
            ],
        ]);
    }
}
