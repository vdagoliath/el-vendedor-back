<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use HasServerVersion;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'external_id',
        'server_version',
        'name',
        'country',
        'province',
        'municipality',
        'street',
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
