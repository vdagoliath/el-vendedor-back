<?php

namespace App\Models;

use Database\Factories\MarketplaceOrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderStatusHistory extends Model
{
    /** @use HasFactory<MarketplaceOrderStatusHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'master_order_id',
        'seller_order_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'notes',
    ];

    public function masterOrder(): BelongsTo
    {
        return $this->belongsTo(MasterOrder::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
