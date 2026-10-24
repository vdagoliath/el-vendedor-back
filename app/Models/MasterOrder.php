<?php

namespace App\Models;

use Database\Factories\MasterOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterOrder extends Model
{
    /** @use HasFactory<MasterOrderFactory> */
    use HasFactory;

    public const string StatusPendingPayment = 'pending_payment';

    public const string StatusConfirmed = 'confirmed';

    public const string StatusPartiallyConfirmed = 'partially_confirmed';

    public const string StatusInFulfillment = 'in_fulfillment';

    public const string StatusCompleted = 'completed';

    public const string StatusCancelled = 'cancelled';

    public const string StatusRefunded = 'refunded';

    protected $fillable = [
        'marketplace_quote_id',
        'order_number',
        'consumer_id',
        'status',
        'payment_status',
        'delivery_status',
        'recipient_snapshot',
        'delivery_address_snapshot',
        'payment_snapshot',
        'delivery_snapshot',
        'subtotal',
        'delivery_total',
        'fees_total',
        'grand_total',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'recipient_snapshot' => 'array',
            'delivery_address_snapshot' => 'array',
            'payment_snapshot' => 'array',
            'delivery_snapshot' => 'array',
            'subtotal' => 'decimal:4',
            'delivery_total' => 'decimal:4',
            'fees_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(MarketplaceQuote::class, 'marketplace_quote_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MarketplaceOrderStatusHistory::class);
    }
}
