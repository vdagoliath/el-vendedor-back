<?php

namespace App\Modules\Catalog;

use App\Models\Business;
use App\Models\CatalogProduct;
use App\Models\CatalogStorefront;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Módulo Catálogo: catálogo online por negocio, independiente del marketplace.
 *
 * - Solo lectura pública por subdominio.
 * - Precio y moneda propios del negocio (sin conversión de tasa).
 * - El pedido no pasa por aquí: lo cierra el negocio por WhatsApp.
 */
class CatalogService
{
    /** Subdominios que no pueden asignarse a un negocio. */
    public const RESERVED_SUBDOMAINS = [
        'www', 'api', 'app', 'admin', 'backoffice', 'mail', 'cdn', 'static',
        'assets', 'catalogo', 'catalog', 'soporte', 'help', 'blog', 'status',
        'staging', 'dev', 'test', 'miraloaki',
    ];

    public const SORTS = ['recommended', 'price_asc', 'price_desc', 'title'];

    public function publicStorefront(string $subdomain): ?CatalogStorefront
    {
        $normalized = strtolower(trim($subdomain));

        if ($normalized === '') {
            return null;
        }

        return CatalogStorefront::query()
            ->published()
            ->with('business')
            ->where('subdomain', $normalized)
            ->first();
    }

    /**
     * Catálogo del negocio actual (se crea como borrador si no existe todavía).
     */
    public function forBusiness(Business $business): CatalogStorefront
    {
        $existing = CatalogStorefront::query()->where('business_id', $business->id)->first();

        if ($existing instanceof CatalogStorefront) {
            return $existing;
        }

        $whatsapp = preg_replace('/\D+/', '', (string) $business->phone) ?? '';

        return CatalogStorefront::query()->create([
            'business_id' => $business->id,
            'subdomain' => $this->uniqueSubdomain((string) ($business->slug ?: $business->name), $business->id),
            'display_name' => $business->name,
            'whatsapp' => $whatsapp,
            'phone' => $business->phone,
            'address' => $business->address,
            'city' => $business->municipality ?: null,
            'currency' => strtoupper((string) ($business->default_currency ?: 'CUP')),
            'accent_color' => '#0F766E',
            'is_published' => false,
        ]);
    }

    /**
     * Categorías derivadas de los productos visibles del catálogo.
     *
     * @return array<int, array{id: string, name: string, emoji: string}>
     */
    public function categories(CatalogStorefront $storefront): array
    {
        return CatalogProduct::query()
            ->where('business_id', $storefront->business_id)
            ->where('is_visible', true)
            ->whereNotNull('category_name')
            ->where('category_name', '!=', '')
            ->selectRaw('category_name, MAX(category_emoji) as category_emoji, MIN(sort) as min_sort')
            ->groupBy('category_name')
            ->orderBy('min_sort')
            ->orderBy('category_name')
            ->get()
            ->map(fn ($row): array => [
                'id' => Str::slug((string) $row->category_name),
                'name' => (string) $row->category_name,
                'emoji' => (string) ($row->category_emoji ?: '🛍️'),
            ])
            ->all();
    }

    /**
     * @param  array{q?:string|null, category?:string|null, only_available?:bool, featured?:bool, sort?:string|null, per_page?:int}  $filters
     */
    public function products(CatalogStorefront $storefront, array $filters = []): LengthAwarePaginatorContract
    {
        $query = CatalogProduct::query()
            ->where('business_id', $storefront->business_id)
            ->where('is_visible', true)
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->where('title', 'like', $term)->orWhere('description', 'like', $term);
                });
            })
            ->when($filters['featured'] ?? false, fn (Builder $query): Builder => $query->where('is_featured', true))
            ->when($filters['only_available'] ?? false, fn (Builder $query): Builder => $query->where('is_available', true));

        $category = $filters['category'] ?? null;

        if (is_string($category) && $category !== '') {
            $name = $this->categoryNameBySlug($storefront, $category);

            if ($name === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('category_name', $name);
            }
        }

        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate(min(max((int) ($filters['per_page'] ?? 24), 1), 60));
    }

    public function product(CatalogStorefront $storefront, string $slug): ?CatalogProduct
    {
        return CatalogProduct::query()
            ->where('business_id', $storefront->business_id)
            ->where('is_visible', true)
            ->where('slug', $slug)
            ->first();
    }

    private function categoryNameBySlug(CatalogStorefront $storefront, string $slug): ?string
    {
        return CatalogProduct::query()
            ->where('business_id', $storefront->business_id)
            ->where('is_visible', true)
            ->whereNotNull('category_name')
            ->distinct()
            ->pluck('category_name')
            ->first(fn ($name): bool => Str::slug((string) $name) === $slug);
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('price')->orderBy('sort')->orderBy('title'),
            'price_desc' => $query->orderByDesc('price')->orderBy('sort')->orderBy('title'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('is_featured')->orderBy('sort')->orderBy('title'),
        };
    }

    public function uniqueSubdomain(string $seed, ?int $ignoreBusinessId = null): string
    {
        $base = substr(Str::slug($seed) ?: 'negocio', 0, 48);

        if (in_array($base, self::RESERVED_SUBDOMAINS, true)) {
            $base .= '-tienda';
        }

        $candidate = $base;
        $suffix = 2;

        while (! $this->subdomainAvailable($candidate, $ignoreBusinessId)) {
            $candidate = $base.'-'.$suffix;
            $suffix += 1;
        }

        return $candidate;
    }

    public function subdomainAvailable(string $subdomain, ?int $ignoreBusinessId = null): bool
    {
        $slug = strtolower(trim($subdomain));

        if ($slug === '' || in_array($slug, self::RESERVED_SUBDOMAINS, true)) {
            return false;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $slug) !== 1) {
            return false;
        }

        return ! CatalogStorefront::query()
            ->where('subdomain', $slug)
            ->when($ignoreBusinessId, fn (Builder $query, int $id): Builder => $query->where('business_id', '!=', $id))
            ->exists();
    }

    public function uniqueProductSlug(int $businessId, string $title, ?int $ignoreId = null): string
    {
        $base = substr(Str::slug($title) ?: 'producto', 0, 150);
        $candidate = $base;
        $suffix = 2;

        while (CatalogProduct::query()
            ->where('business_id', $businessId)
            ->where('slug', $candidate)
            ->when($ignoreId, fn (Builder $query, int $id): Builder => $query->where('id', '!=', $id))
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix += 1;
        }

        return $candidate;
    }
}
