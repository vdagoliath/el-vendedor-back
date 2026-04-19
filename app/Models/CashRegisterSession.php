<?php

namespace App\Models;

use Database\Factories\CashRegisterSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashRegisterSession extends Model
{
    /** @use HasFactory<CashRegisterSessionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'pos_external_id',
        'warehouse_external_id',
        'status',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance',
        'opened_by',
        'closed_by',
        'initial_inventory_snapshot',
        'final_inventory_snapshot',
        'last_received_event_id',
        'source_created_at',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'opened_by' => 'array',
            'closed_by' => 'array',
            'initial_inventory_snapshot' => 'array',
            'final_inventory_snapshot' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeForPos(Builder $query, string $posExternalId): Builder
    {
        return $query->where('pos_external_id', $posExternalId);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
