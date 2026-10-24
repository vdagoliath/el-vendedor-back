<?php

use App\Models\Business;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('backoffice users can visit the dashboard', function () {
    $user = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();

    $user->switchCurrentBusiness($business);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
