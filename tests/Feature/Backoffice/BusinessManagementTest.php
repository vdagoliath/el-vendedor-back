<?php

use App\Models\Business;
use App\Models\User;

test('implementers can update any business profile, not only the current one', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $current = Business::factory()->create();
    $other = Business::factory()->create(['name' => 'Antiguo Nombre']);

    $implementer->switchCurrentBusiness($current);

    $response = $this->actingAs($implementer)->put(
        route('backoffice.businesses.update', $other),
        [
            'name' => 'Nuevo Nombre',
            'slug' => 'nuevo-nombre',
            'address' => null,
            'phone' => null,
            'default_currency' => 'CUP',
        ],
    );

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect($other->fresh()->name)->toBe('Nuevo Nombre');
});

test('implementers can deactivate a business and it clears the current_business pointer of every user', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $owner = User::factory()->create(['current_business_id' => $business->id]);

    $implementer->switchCurrentBusiness($business);

    $response = $this->actingAs($implementer)->delete(route('backoffice.businesses.destroy', $business));

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect($business->fresh()->is_active)->toBeFalse();
    expect($implementer->fresh()->current_business_id)->toBeNull();
    expect($owner->fresh()->current_business_id)->toBeNull();
});

test('implementers can reactivate a previously deactivated business', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create(['is_active' => false]);

    $response = $this->actingAs($implementer)->post(route('backoffice.businesses.restore', $business));

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect($business->fresh()->is_active)->toBeTrue();
});

test('the businesses index lists active and inactive businesses', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    Business::factory()->create(['name' => 'Activo']);
    Business::factory()->create(['name' => 'Inactivo', 'is_active' => false]);

    $response = $this->actingAs($implementer)->get(route('backoffice.businesses.index'));

    $response->assertOk();
    $businesses = collect($response->viewData('page')['props']['businesses']);
    expect($businesses->pluck('name'))->toContain('Activo')->toContain('Inactivo');
});

test('users without backoffice role can not deactivate a business', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $this->actingAs($user)
        ->delete(route('backoffice.businesses.destroy', $business))
        ->assertForbidden();

    expect($business->fresh()->is_active)->toBeTrue();
});
