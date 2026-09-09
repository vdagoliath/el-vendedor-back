<?php

namespace Database\Seeders;

use App\Models\MarketplaceCurrency;
use Illuminate\Database\Seeder;

class MarketplaceCurrencySeeder extends Seeder
{
    /**
     * Monedas de visualizacion gestionadas por el backend (USD por defecto).
     */
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'name' => 'Dólar estadounidense (USD)', 'symbol' => 'US$', 'decimals' => 2, 'is_default' => true, 'sort' => 1],
            ['code' => 'CAD', 'name' => 'Dólar canadiense (CAD)', 'symbol' => 'CA$', 'decimals' => 2, 'is_default' => false, 'sort' => 2],
            ['code' => 'RUB', 'name' => 'Rublo ruso (RUB)', 'symbol' => '₽', 'decimals' => 2, 'is_default' => false, 'sort' => 3],
            ['code' => 'MXN', 'name' => 'Peso mexicano (MXN)', 'symbol' => 'MX$', 'decimals' => 2, 'is_default' => false, 'sort' => 4],
            ['code' => 'UYU', 'name' => 'Peso uruguayo (UYU)', 'symbol' => '$U', 'decimals' => 2, 'is_default' => false, 'sort' => 5],
        ];

        foreach ($currencies as $currency) {
            MarketplaceCurrency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency,
            );
        }
    }
}
