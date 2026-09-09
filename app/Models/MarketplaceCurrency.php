<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MarketplaceCurrency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimals',
        'is_default',
        'sort',
    ];

    protected $casts = [
        'decimals' => 'integer',
        'is_default' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Orden estable por `sort`.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort');
    }

    public function scopeDefaults(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
