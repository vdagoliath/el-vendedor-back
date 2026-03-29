<?php

namespace Database\Seeders;

use App\Enums\BackofficeRole;
use App\Models\User;
use Illuminate\Database\Seeder;

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
            User::factory()->platformAdmin()->raw([
                'name' => self::NAME,
                'email' => self::EMAIL,
            ]),
        );

        if (! $supremeAdmin->is_platform_admin || $supremeAdmin->getEffectiveBackofficeRole() !== BackofficeRole::SuperAdmin) {
            $supremeAdmin->forceFill([
                'backoffice_role' => BackofficeRole::SuperAdmin,
                'is_platform_admin' => true,
            ])->save();
        }
    }
}
