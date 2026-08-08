<?php

namespace App\Models;

use Database\Factories\MarketplaceQuoteLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceQuoteLine extends Model
{
    /** @use HasFactory<MarketplaceQuoteLineFactory> */
    use HasFactory;

    protected $fillable = [
        'marketplace_quote_id',
        'marketplace_product_publication_id',
        'business_id',
        'product_external_id',
        'warehouse_external_id',
        'title_snapshot',
        'unit_price',
        'quantity',
        'subtotal',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'subtotal' => 'decimal:4',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(MarketplaceQuote::class, 'marketplace_quote_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProductPublication::class, 'marketplace_product_publication_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
