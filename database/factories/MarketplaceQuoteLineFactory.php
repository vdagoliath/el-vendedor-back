<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\MarketplaceQuote;
use App\Models\MarketplaceQuoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceQuoteLine>
 */
class MarketplaceQuoteLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace_quote_id' => MarketplaceQuote::factory(),
            'marketplace_product_publication_id' => null,
            'business_id' => Business::factory(),
            'product_external_id' => 'product-'.$this->faker->unique()->bothify('??-###'),
            'warehouse_external_id' => 'warehouse-ecommerce',
            'title_snapshot' => $this->faker->words(3, true),
            'unit_price' => 10,
            'quantity' => 1,
            'subtotal' => 10,
            'currency' => 'USD',
        ];
    }
}
