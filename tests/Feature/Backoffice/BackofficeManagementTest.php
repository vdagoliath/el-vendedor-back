<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('users without a backoffice role can not view the backoffice business management page', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();

    $owner->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('backoffice.businesses.index'))
        ->assertForbidden();
});

test('implementers can view the backoffice business management page', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    Business::factory()->create();

    $this->actingAs($implementer)
        ->get(route('backoffice.businesses.index'))
        ->assertOk();
});

test('backoffice business page shares the context needed by the sidebar layout', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    Business::factory()->create([
        'name' => 'Casa Central',
        'slug' => 'casa-central',
    ]);

    $this->actingAs($implementer)
        ->get(route('backoffice.businesses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backoffice/Businesses')
            ->where('name', config('app.name'))
            ->where('locale', app()->getLocale())
            ->where('auth.user.backoffice_role', 'implementer')
            ->where('auth.user.backoffice.can_prepare_businesses', true)
            ->where('auth.user.current_business.name', 'Casa Central')
            ->where('auth.user.current_business.slug', 'casa-central'),
        );
});

test('implementers can create a business from backoffice without becoming owners', function () {
    $user = User::factory()->backofficeImplementer()->create();

    $response = $this->actingAs($user)->post(route('backoffice.businesses.store'), [
        'name' => 'Mi Bodega',
    ]);

    $business = Business::query()->where('slug', 'mi-bodega')->firstOrFail();

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect($user->fresh()->ownsBusiness($business))->toBeFalse();
    expect($user->fresh()->current_business_id)->toBe($business->id);
});

test('implementers can switch their current business from backoffice', function () {
    $user = User::factory()->backofficeImplementer()->create();
    $firstBusiness = Business::factory()->create();
    $secondBusiness = Business::factory()->create();

    $user->switchCurrentBusiness($firstBusiness);

    $response = $this->actingAs($user)->put(route('backoffice.current-business.update'), [
        'business_id' => $secondBusiness->id,
    ]);

    $response->assertRedirect();
    expect($user->fresh()->current_business_id)->toBe($secondBusiness->id);
});

test('implementers can view and manage the current business team page', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();

    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.team.index'))
        ->assertOk();
});

test('implementers can add employees from the backoffice team page', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();

    $implementer->switchCurrentBusiness($business);

    $response = $this->actingAs($implementer)->post(route('backoffice.team.store'), [
        'name' => 'Nueva Empleada',
        'email' => 'empleada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => BusinessRole::Employee->value,
    ]);

    $employee = User::query()->where('email', 'empleada@example.com')->firstOrFail();

    $response->assertRedirect(route('backoffice.team.index'));
    expect($employee->isEmployeeOf($business))->toBeTrue();
});
