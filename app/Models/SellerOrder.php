<?php

namespace App\Models;

use Database\Factories\SellerOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    public const string StatusReserved = 'reserved';

    public const string StatusAccepted = 'accepted';

    public const string StatusPreparing = 'preparing';

    public const string StatusReady = 'ready';

    public const string StatusDispatched = 'dispatched';

    public const string StatusDelivered = 'delivered';

    public const string StatusCancelled = 'cancelled';

    protected $fillable = [
        'master_order_id',
        'business_id',
        'seller_order_number',
        'status',
        'sale_id',
        'reservation_id',
        'subtotal',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
        ];
    }

    public function masterOrder(): BelongsTo
    {
        return $this->belongsTo(MasterOrder::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SellerOrderLine::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MarketplaceOrderStatusHistory::class);
    }
}
