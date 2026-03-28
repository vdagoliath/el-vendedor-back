<?php

namespace App\Models;

use Database\Factories\SyncReceivedEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncReceivedEvent extends Model
{
    /** @use HasFactory<SyncReceivedEventFactory> */
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
        'operation',
        'occurred_at',
        'payload',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the business that owns the event.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the user that submitted the event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
