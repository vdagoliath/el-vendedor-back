<?php

namespace App\Models;

use Database\Factories\SyncConflictFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    /** @use HasFactory<SyncConflictFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'user_id',
        'device_id',
        'event_id',
        'entity_type',
        'entity_id',
        'conflict_type',
        'local_payload',
        'remote_payload',
        'status',
        'resolution_notes',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_payload' => 'array',
            'remote_payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Get the business that owns the conflict.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the user related to the conflict.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
