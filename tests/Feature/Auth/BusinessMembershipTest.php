<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

test('businesses can have owners and employees', function () {
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    $employee = User::factory()->create();

    $business->users()->attach($owner, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $business->users()->attach($employee, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    expect($business->owners()->pluck('users.id')->all())->toBe([$owner->id]);
    expect($business->employees()->pluck('users.id')->all())->toBe([$employee->id]);
});

test('owners can manage their business while employees can only sell', function () {
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    $employee = User::factory()->create();

    $owner->businesses()->attach($business, [
        'role' => BusinessRole::Owner->value,
        'is_active' => true,
    ]);

    $employee->businesses()->attach($business, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    expect($owner->ownsBusiness($business))->toBeTrue();
    expect($owner->canManageBusiness($business))->toBeTrue();
    expect($owner->canSellInBusiness($business))->toBeTrue();

    expect($employee->ownsBusiness($business))->toBeFalse();
    expect($employee->canManageBusiness($business))->toBeFalse();
    expect($employee->canSellInBusiness($business))->toBeTrue();
});

test('platform administrators can manage any business', function () {
    $business = Business::factory()->create();
    $administrator = User::factory()->platformAdmin()->create();

    expect($administrator->isPlatformAdministrator())->toBeTrue();
    expect($administrator->canManageBusiness($business))->toBeTrue();
    expect($administrator->canSellInBusiness($business))->toBeTrue();
});
