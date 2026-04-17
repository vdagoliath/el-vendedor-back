<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitOfMeasure extends Model
{
    use SoftDeletes;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'business_id',
        'external_id',
        'name',
        'symbol',
        'category',
        'ratio',
        'is_reference',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'ratio' => 'decimal:6',
            'is_reference' => 'boolean',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
