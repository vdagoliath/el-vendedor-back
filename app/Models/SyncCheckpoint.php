<?php

namespace App\Models;

use Database\Factories\SyncCheckpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncCheckpoint extends Model
{
    /** @use HasFactory<SyncCheckpointFactory> */
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
        'last_pushed_cursor',
        'last_pulled_cursor',
        'last_pushed_at',
        'last_pulled_at',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_pushed_at' => 'datetime',
            'last_pulled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * Get the business that owns the checkpoint.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the user related to the checkpoint.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
