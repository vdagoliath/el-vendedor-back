<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBreakdown extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'source_product_external_id',
        'target_product_external_id',
        'warehouse_external_id',
        'source_quantity',
        'target_quantity',
        'conversion_ratio',
        'source_title_snapshot',
        'target_title_snapshot',
        'source_unit_symbol_snapshot',
        'target_unit_symbol_snapshot',
        'source_unit_cost',
        'target_unit_cost',
        'previous_source_quantity',
        'previous_target_quantity',
        'breakdown_at',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'source_quantity' => 'decimal:4',
            'target_quantity' => 'decimal:4',
            'conversion_ratio' => 'decimal:4',
            'source_unit_cost' => 'decimal:4',
            'target_unit_cost' => 'decimal:4',
            'previous_source_quantity' => 'decimal:4',
            'previous_target_quantity' => 'decimal:4',
            'breakdown_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
