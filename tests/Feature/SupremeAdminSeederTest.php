<?php

use App\Enums\BackofficeRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupremeAdminSeeder;
use Illuminate\Support\Facades\Hash;

test('supreme admin seeder creates a single supreme administrator with platform access', function () {
    $this->seed(SupremeAdminSeeder::class);
    $this->seed(SupremeAdminSeeder::class);

    $supremeAdmin = User::query()
        ->where('email', 'admin@example.com')
        ->first();

    expect($supremeAdmin)->not->toBeNull();
    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1);
    expect($supremeAdmin->name)->toBe('Supreme Administrator');
    expect($supremeAdmin->is_platform_admin)->toBeTrue();
    expect($supremeAdmin->backoffice_role)->toBe(BackofficeRole::SuperAdmin);
    expect($supremeAdmin->isBackofficeSuperAdmin())->toBeTrue();
    expect(Hash::check('password', $supremeAdmin->password))->toBeTrue();
});

test('database seeder includes the supreme administrator account', function () {
    $this->seed(DatabaseSeeder::class);

    $supremeAdmin = User::query()
        ->where('email', 'admin@example.com')
        ->first();

    expect($supremeAdmin)->not->toBeNull();
    expect($supremeAdmin->is_platform_admin)->toBeTrue();
    expect($supremeAdmin->isBackofficeSuperAdmin())->toBeTrue();
});
