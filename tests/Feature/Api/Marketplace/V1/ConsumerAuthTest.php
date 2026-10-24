<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a consumer reusing users and issues a consumer token', function () {
    $response = $this->postJson('/api/marketplace/v1/auth/register', [
        'name' => 'Marta Pérez',
        'email' => 'MARTA@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'locale' => 'es',
    ]);

    $response->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'marta@example.com');

    $user = User::query()->where('email', 'marta@example.com')->firstOrFail();
    expect($user->tokens()->where('name', 'consumer')->exists())->toBeTrue();

    $this->assertTrue(Hash::check('secret-password', $user->password));
});

it('logs in a consumer and exposes the profile with the bearer token', function () {
    $user = User::factory()->create([
        'email' => 'marta@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $login = $this->postJson('/api/marketplace/v1/auth/login', [
        'email' => 'marta@example.com',
        'password' => 'secret-password',
    ]);

    $login->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id);

    $token = $login->json('token');

    $this->withToken($token)->getJson('/api/marketplace/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('email', 'marta@example.com');
});

it('rejects bad credentials with a validation error', function () {
    User::factory()->create(['email' => 'marta@example.com', 'password' => Hash::make('correct')]);

    $this->postJson('/api/marketplace/v1/auth/login', [
        'email' => 'marta@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('requires the consumer ability for protected endpoints and logs out', function () {
    $user = User::factory()->create();

    $token = $user->createToken('consumer', ['consumer'])->plainTextToken;

    $this->withToken($token)->getJson('/api/marketplace/v1/auth/me')->assertOk();

    $this->postJson('/api/marketplace/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    // El token queda revocado en BD (el guard cachea el usuario entre peticiones
    // dentro de un mismo test, por eso la verificacion 401 va en otro caso).
    expect($user->tokens()->count())->toBe(0);
});

it('rejects a revoked consumer token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('consumer', ['consumer'])->plainTextToken;
    $user->tokens()->delete();

    $this->withToken($token)->getJson('/api/marketplace/v1/auth/me')->assertUnauthorized();
});
