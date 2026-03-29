<?php

namespace Database\Seeders;

use App\Enums\BackofficeRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupremeAdminSeeder extends Seeder
{
    private const string EMAIL = 'vdagoliath@gmail.com';

    private const string NAME = 'Administrador Supremo';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supremeAdmin = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::NAME,
                'email' => self::EMAIL,
                'locale' => config('app.locale'),
                'email_verified_at' => now(),
                'backoffice_role' => BackofficeRole::SuperAdmin,
                'is_platform_admin' => true,
                'password' => Hash::make('password'),
                'remember_token' => Str::random(10),
            ],
        );

        if (! $supremeAdmin->is_platform_admin || $supremeAdmin->getEffectiveBackofficeRole() !== BackofficeRole::SuperAdmin) {
            $supremeAdmin->forceFill([
                'backoffice_role' => BackofficeRole::SuperAdmin,
                'is_platform_admin' => true,
            ])->save();
        }
    }
}
