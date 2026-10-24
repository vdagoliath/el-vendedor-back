<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'reference',
        'contact_external_id',
        'contact_snapshot',
        'pos_external_id',
        'warehouse_external_id',
        'cash_register_session_id',
        'total',
        'total_base',
        'status',
        'currency',
        'exchange_rate_from_base',
        'payment_method',
        'credit_balance',
        'payment_breakdown',
        'amount_received',
        'change_amount',
        'cash_breakdown',
        'card_payment_details',
        'created_by',
        'inventory_consumption',
        'sale_id_import',
        'items_imported',
        'transaction_at',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'total_base' => 'decimal:2',
            'exchange_rate_from_base' => 'decimal:6',
            'credit_balance' => 'decimal:2',
            'payment_breakdown' => 'array',
            'amount_received' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cash_breakdown' => 'array',
            'card_payment_details' => 'array',
            'created_by' => 'array',
            'inventory_consumption' => 'array',
            'items_imported' => 'array',
            'contact_snapshot' => 'array',
            'transaction_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('sort_order');
    }
}
