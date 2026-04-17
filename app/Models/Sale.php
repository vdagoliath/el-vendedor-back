<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'reference',
        'contact_external_id',
        'pos_external_id',
        'warehouse_external_id',
        'cash_register_session_id',
        'total',
        'status',
        'currency',
        'payment_method',
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
            'amount_received' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cash_breakdown' => 'array',
            'card_payment_details' => 'array',
            'created_by' => 'array',
            'inventory_consumption' => 'array',
            'items_imported' => 'array',
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
