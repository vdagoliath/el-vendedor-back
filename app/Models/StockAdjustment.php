<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'product_external_id',
        'warehouse_external_id',
        'target_quantity',
        'change_quantity',
        'previous_quantity',
        'reason',
        'adjustment_at',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'decimal:4',
            'change_quantity' => 'decimal:4',
            'previous_quantity' => 'decimal:4',
            'adjustment_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
