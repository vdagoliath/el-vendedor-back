<?php

use App\Models\User;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('authenticated backoffice users are redirected from home to the business selector', function () {
    $user = User::factory()->backofficeImplementer()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('backoffice.businesses.index', absolute: false));
});
