<?php

namespace App\Models;

use App\Models\Concerns\HasServerVersion;
use Database\Factories\CatalogStorefrontFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuración pública del catálogo online de un negocio.
 *
 * El subdominio ({subdomain}.miraloaki.com) identifica al negocio. La moneda y
 * los precios del catálogo son propios del negocio: no pasan por la conversión
 * del marketplace.
 */
class CatalogStorefront extends Model
{
    /** @use HasFactory<CatalogStorefrontFactory> */
    use HasFactory;

    use HasServerVersion;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'subdomain',
        'display_name',
        'tagline',
        'about',
        'cover_url',
        'logo_url',
        'accent_color',
        'accent_contrast',
        'whatsapp',
        'phone',
        'email',
        'address',
        'city',
        'hours',
        'social',
        'currency',
        'currency_symbol',
        'delivery_note',
        'payment_note',
        'template',
        'is_published',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'social' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'business_id', 'business_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public static function findBySubdomain(string $subdomain): ?self
    {
        $normalized = strtolower(trim($subdomain));

        if ($normalized === '') {
            return null;
        }

        return static::query()->where('subdomain', $normalized)->first();
    }
}
