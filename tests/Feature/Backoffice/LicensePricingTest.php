<?php

use App\Models\LicensePricingConfig;
use App\Models\LicensePricingRule;
use App\Models\User;

test('super admins can update a license pricing rule monthly price', function () {
    $admin = User::factory()->backofficeSuperAdmin()->create();

    $rule = LicensePricingRule::query()->create([
        'name' => 'Hasta 1 punto de venta activo',
        'description' => 'Tarifa base.',
        'currency' => 'CUP',
        'monthly_price' => 650,
        'min_active_pos' => 0,
        'max_active_pos' => 1,
        'min_active_owners' => null,
        'max_active_owners' => null,
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->put(
        route('backoffice.license-pricing.rules.update', $rule),
        [
            'name' => $rule->name,
            'description' => $rule->description,
            'currency' => $rule->currency,
            'monthly_price' => 999.50,
            'min_active_pos' => $rule->min_active_pos,
            'max_active_pos' => $rule->max_active_pos,
            'sort_order' => $rule->sort_order,
            'is_active' => '1',
        ],
    );

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect((float) $rule->fresh()->monthly_price)->toBe(999.50);
});

test('super admins can update the active pos operation window', function () {
    $admin = User::factory()->backofficeSuperAdmin()->create();
    LicensePricingConfig::query()->create(['active_pos_operation_window_days' => 30]);

    $response = $this->actingAs($admin)->put(
        route('backoffice.license-pricing.config.update'),
        ['active_pos_operation_window_days' => 45],
    );

    $response->assertRedirect(route('backoffice.businesses.index'));
    expect(LicensePricingConfig::query()->value('active_pos_operation_window_days'))->toBe(45);
});

test('implementers can not update license pricing rules', function () {
    $implementer = User::factory()->backofficeImplementer()->create();

    $rule = LicensePricingRule::query()->create([
        'name' => 'Tramo base',
        'description' => null,
        'currency' => 'CUP',
        'monthly_price' => 500,
        'min_active_pos' => 0,
        'max_active_pos' => 1,
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($implementer)
        ->put(route('backoffice.license-pricing.rules.update', $rule), [
            'name' => $rule->name,
            'currency' => $rule->currency,
            'monthly_price' => 999,
            'min_active_pos' => 0,
            'sort_order' => 10,
        ])
        ->assertForbidden();

    expect((float) $rule->fresh()->monthly_price)->toBe(500.00);
});
