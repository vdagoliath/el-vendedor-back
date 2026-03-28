<?php

use App\Enums\BackofficeRole;
use App\Models\Business;
use App\Models\User;

test('implementers can access the dashboard and preparation but not backoffice user management', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();

    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($implementer)
        ->get(route('backoffice.preparation.index'))
        ->assertOk();

    $this->actingAs($implementer)
        ->get(route('backoffice.sales.index'))
        ->assertForbidden();

    $this->actingAs($implementer)
        ->get(route('backoffice.access.index'))
        ->assertForbidden();
});

test('super admins can access analytics and manage backoffice users', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();

    $superAdmin->switchCurrentBusiness($business);

    $this->actingAs($superAdmin)
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('backoffice.access.index'))
        ->assertOk();
});

test('super admins can assign backoffice roles', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();

    $response = $this->actingAs($superAdmin)->post(route('backoffice.access.store'), [
        'name' => 'Implementadora',
        'email' => 'implementadora@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'backoffice_role' => BackofficeRole::Implementer->value,
    ]);

    $response->assertRedirect(route('backoffice.access.index'));

    $user = User::query()->where('email', 'implementadora@example.com')->firstOrFail();

    expect($user->backoffice_role)->toBe(BackofficeRole::Implementer);
    expect($user->is_platform_admin)->toBeFalse();
});

test('the last super admin can not lose access', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();

    $response = $this->actingAs($superAdmin)->patch(route('backoffice.access.update', $superAdmin), [
        'backoffice_role' => BackofficeRole::Implementer->value,
    ]);

    $response->assertSessionHasErrors('backoffice_role');
    expect($superAdmin->fresh()->isBackofficeSuperAdmin())->toBeTrue();
});
