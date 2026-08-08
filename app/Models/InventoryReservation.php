<?php

namespace App\Models;

use Database\Factories\InventoryReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReservation extends Model
{
    /** @use HasFactory<InventoryReservationFactory> */
    use HasFactory;

    public const string StatusActive = 'active';

    public const string StatusConfirmed = 'confirmed';

    public const string StatusReleased = 'released';

    public const string StatusExpired = 'expired';

    public const string StatusCancelled = 'cancelled';

    protected $fillable = [
        'business_id',
        'owner_type',
        'owner_id',
        'status',
        'expires_at',
        'confirmed_at',
        'released_at',
        'expired_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReservationLine::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::StatusActive
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
