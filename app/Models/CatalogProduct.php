<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto del catálogo online.
 *
 * `price` + `currency` son los que definió el dueño y se muestran tal cual en
 * su catálogo. `product_external_id` enlaza con el producto de ElVendedor del
 * que sale el ítem, pero el precio NO se hereda del marketplace.
 */
class CatalogProduct extends Model
{
    /** @use HasFactory<CatalogProductFactory> */
    use HasFactory;

    use HasServerVersion;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'product_external_id',
        'slug',
        'title',
        'description',
        'price',
        'currency',
        'unit',
        'badge',
        'category_name',
        'category_emoji',
        'images',
        'is_visible',
        'is_featured',
        'is_available',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'images' => 'array',
            'is_visible' => 'boolean',
            'is_featured' => 'boolean',
            'is_available' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_featured')->orderBy('sort')->orderBy('title');
    }
}
