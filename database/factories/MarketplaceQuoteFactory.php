<?php

namespace Database\Factories;

use App\Models\MarketplaceQuote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceQuote>
 */
class MarketplaceQuoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_number' => 'MQ-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
            'consumer_id' => null,
            'status' => MarketplaceQuote::StatusQuoted,
            'subtotal' => 10,
            'delivery_total' => 0,
            'fees_total' => 0,
            'grand_total' => 10,
            'currency' => 'USD',
            'expires_at' => now()->addMinutes(15),
            'payload_snapshot' => [],
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
