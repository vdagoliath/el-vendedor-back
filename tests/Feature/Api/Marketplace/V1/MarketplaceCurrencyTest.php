<?php

use Database\Seeders\MarketplaceCurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('lists backend managed display currencies with USD default', function () {
    (new MarketplaceCurrencySeeder)->run();

    $response = $this->getJson('/api/marketplace/v1/currencies')
        ->assertOk()
        ->assertJsonPath('meta.default', 'USD');

    expect(collect($response->json('data'))->pluck('code')->all())
        ->toBe(['USD', 'CAD', 'RUB', 'MXN', 'UYU']);
});

it('returns display rates with QvaPay CUP and standard codes', function () {
    Http::fake([
        'api.qvapay.com/*' => Http::response([
            'BANK_CUP' => ['average' => 121.5, 'updated_at' => '2026-09-09T00:00:00Z'],
        ]),
        'open.er-api.com/*' => Http::response([
            'result' => 'success',
            'rates' => ['CAD' => 1.35, 'RUB' => 92.1, 'MXN' => 19.2, 'UYU' => 40.1],
        ]),
    ]);

    $this->getJson('/api/marketplace/v1/rates')
        ->assertOk()
        ->assertJsonPath('base', 'USD')
        ->assertJsonPath('rates.USD', 1)
        ->assertJsonPath('rates.CUP', 121.5)
        ->assertJsonPath('rates.CAD', 1.35)
        ->assertJsonPath('error', null);
});

it('degrades gracefully when QvaPay is unavailable', function () {
    Http::fake([
        'api.qvapay.com/*' => Http::response(null, 500),
        'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['CAD' => 1.35]]),
    ]);

    $this->getJson('/api/marketplace/v1/rates')
        ->assertOk()
        ->assertJsonMissingPath('rates.CUP')
        ->assertJsonPath('rates.CAD', 1.35)
        ->assertJsonPath('error', 'QvaPay no disponible.');
});
