<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

test('business users automatically get their first active business as current business', function () {
    $user = User::factory()->create();
    $firstBusiness = Business::factory()->create();
    $secondBusiness = Business::factory()->create();

    $user->businesses()->attach($firstBusiness, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->businesses()->attach($secondBusiness, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $token = $user->createToken('Android')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.current_business.id', $firstBusiness->id);

    expect($user->fresh()->current_business_id)->toBe($firstBusiness->id);
});

test('authenticated users can switch their current business', function () {
    $user = User::factory()->create();
    $firstBusiness = Business::factory()->create();
    $secondBusiness = Business::factory()->create();

    $user->businesses()->attach($firstBusiness, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $user->businesses()->attach($secondBusiness, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $user->switchCurrentBusiness($firstBusiness);

    $token = $user->createToken('Android')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/current-business', [
            'business_id' => $secondBusiness->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Negocio actual actualizado correctamente.')
        ->assertJsonPath('user.current_business.id', $secondBusiness->id);

    expect($user->fresh()->current_business_id)->toBe($secondBusiness->id);
});

test('users can not switch to a business they can not access', function () {
    $user = User::factory()->create();
    $allowedBusiness = Business::factory()->create();
    $forbiddenBusiness = Business::factory()->create();

    $user->businesses()->attach($allowedBusiness, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $token = $user->createToken('Android')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/current-business', [
            'business_id' => $forbiddenBusiness->id,
        ])
        ->assertForbidden();

    expect($user->fresh()->current_business_id)->toBeNull();
});

test('platform administrators can switch to any active business', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $business = Business::factory()->create();
    $token = $administrator->createToken('Android')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/current-business', [
            'business_id' => $business->id,
        ])
        ->assertOk()
        ->assertJsonPath('user.current_business.id', $business->id);

    expect($administrator->fresh()->current_business_id)->toBe($business->id);
});
