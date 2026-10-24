<?php

namespace App\Models;

use Database\Factories\MarketplaceQuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceQuote extends Model
{
    /** @use HasFactory<MarketplaceQuoteFactory> */
    use HasFactory;

    public const string StatusQuoted = 'quoted';

    public const string StatusReserved = 'reserved';

    public const string StatusExpired = 'expired';

    public const string StatusConverted = 'converted';

    public const string StatusCancelled = 'cancelled';

    protected $fillable = [
        'quote_number',
        'consumer_id',
        'status',
        'subtotal',
        'delivery_total',
        'fees_total',
        'grand_total',
        'currency',
        'expires_at',
        'payload_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'delivery_total' => 'decimal:4',
            'fees_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'expires_at' => 'datetime',
            'payload_snapshot' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MarketplaceQuoteLine::class);
    }
}
