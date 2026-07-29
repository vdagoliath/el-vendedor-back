<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WeightJournal extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'status',
        'pos_external_id',
        'pos_name',
        'cash_register_session_external_id',
        'warehouse_external_id',
        'payment_method',
        'items',
        'total_sold_quantity',
        'total_loss_quantity',
        'total',
        'sale_external_id',
        'sale_reference',
        'notes',
        'opened_at',
        'closed_at',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_sold_quantity' => 'decimal:4',
            'total_loss_quantity' => 'decimal:4',
            'total' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
