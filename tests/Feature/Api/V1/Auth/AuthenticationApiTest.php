<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

test('users can register through the api and receive a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone 15',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'test@example.com');

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->tokens)->toHaveCount(1);
});

test('users can login through the api and receive a token', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Android',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', $user->email);

    expect($user->fresh()->tokens)->toHaveCount(1);
});

test('users can not login through the api with invalid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Android',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh()->tokens)->toHaveCount(0);
});

test('authenticated users can fetch their current profile through the api', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();

    $user->businesses()->attach($business, [
        'role' => BusinessRole::Employee->value,
        'is_active' => true,
    ]);

    $token = $user->createToken('Android')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.is_platform_admin', false)
        ->assertJsonPath('data.businesses.0.slug', $business->slug)
        ->assertJsonPath('data.businesses.0.role', BusinessRole::Employee->value);
});

test('authenticated users can logout through the api', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Android');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Sesion cerrada correctamente.');

    expect($user->fresh()->tokens)->toHaveCount(0);
});
