<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'name',
        'mobile',
        'source_created_at',
        'source_updated_at',
        'last_received_event_id',
    ];

    protected function casts(): array
    {
        return [
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
