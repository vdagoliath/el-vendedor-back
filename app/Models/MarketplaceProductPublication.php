<?php

namespace App\Models;

use Database\Factories\MarketplaceProductPublicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceProductPublication extends Model
{
    /** @use HasFactory<MarketplaceProductPublicationFactory> */
    use HasFactory;

    public const string StatusDraft = 'draft';

    public const string StatusPublished = 'published';

    public const string StatusPaused = 'paused';

    public const string StatusArchived = 'archived';

    protected $fillable = [
        'business_id',
        'product_external_id',
        'warehouse_external_id',
        'status',
        'public_title',
        'public_description',
        'public_price',
        'currency',
        'images',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'public_price' => 'decimal:4',
            'images' => 'array',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::StatusPublished);
    }
}
