<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('implementers can update a team member profile and change their role', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $member = User::factory()->create();

    $business->users()->attach($member, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $implementer->switchCurrentBusiness($business);

    $response = $this->actingAs($implementer)->put(
        route('backoffice.team.members.update', $member),
        [
            'name' => 'Nombre Actualizado',
            'email' => 'actualizado@example.com',
            'role' => BusinessRole::Owner->value,
            'is_active' => '1',
        ],
    );

    $response->assertRedirect(route('backoffice.team.index'));

    $member->refresh();
    expect($member->name)->toBe('Nombre Actualizado');
    expect($member->email)->toBe('actualizado@example.com');
    expect($member->ownsBusiness($business))->toBeTrue();
});

test('implementers can detach a team member from the current business', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $member = User::factory()->create(['current_business_id' => null]);

    $business->users()->attach($member, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);
    $member->switchCurrentBusiness($business);

    $implementer->switchCurrentBusiness($business);

    $response = $this->actingAs($implementer)->delete(
        route('backoffice.team.members.destroy', $member),
    );

    $response->assertRedirect(route('backoffice.team.index'));
    expect($business->users()->whereKey($member->id)->exists())->toBeFalse();
    expect($member->fresh()->current_business_id)->toBeNull();
});

test('implementers can not remove themselves from a business they manage', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();

    $business->users()->attach($implementer, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->delete(route('backoffice.team.members.destroy', $implementer))
        ->assertRedirect(route('backoffice.team.index'));

    expect($business->users()->whereKey($implementer->id)->exists())->toBeTrue();
});

test('implementers can reset a team member password', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $member = User::factory()->create(['password' => Hash::make('viejo-pass')]);

    $business->users()->attach($member, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);
    $implementer->switchCurrentBusiness($business);

    $response = $this->actingAs($implementer)->put(
        route('backoffice.team.members.password.update', $member),
        [
            'password' => 'NuevaPass123!',
            'password_confirmation' => 'NuevaPass123!',
        ],
    );

    $response->assertRedirect(route('backoffice.team.index'));
    expect(Hash::check('NuevaPass123!', $member->fresh()->password))->toBeTrue();
});

test('password reset rejects unconfirmed passwords', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $member = User::factory()->create();

    $business->users()->attach($member, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->put(route('backoffice.team.members.password.update', $member), [
            'password' => 'Mismatched123!',
            'password_confirmation' => 'Different123!',
        ])
        ->assertSessionHasErrors('password');
});

test('users without backoffice role can not modify a member of a business', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $member = User::factory()->create();

    $business->users()->attach($owner, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);
    $business->users()->attach($member, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);
    $owner->switchCurrentBusiness($business);

    $this->actingAs($owner)
        ->put(route('backoffice.team.members.update', $member), [
            'name' => 'Hack',
            'email' => 'hack@example.com',
            'role' => BusinessRole::Owner->value,
            'is_active' => '1',
        ])
        ->assertForbidden();
});
