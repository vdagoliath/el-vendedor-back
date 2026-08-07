<?php

namespace App\Models;

use Database\Factories\SellerOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerOrderLine extends Model
{
    /** @use HasFactory<SellerOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'product_external_id',
        'warehouse_external_id',
        'title_snapshot',
        'unit_price',
        'quantity',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'subtotal' => 'decimal:4',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
