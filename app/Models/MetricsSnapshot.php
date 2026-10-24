<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetricsSnapshot extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'period',
        'period_start',
        'period_end',
        'generated_at',
        'source_runs',
        'source_counts',
        'totals',
        'products',
        'expense_categories',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'source_runs' => 'array',
            'source_counts' => 'array',
            'totals' => 'array',
            'products' => 'array',
            'expense_categories' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
