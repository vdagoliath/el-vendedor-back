<?php

namespace App\Http\Resources\Api\Catalog\V1;

use App\Models\CatalogStorefront;
use App\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogStorefront */
class CatalogStorefrontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->subdomain,
            'business_name' => $this->whenLoaded('business', fn () => $this->business?->name) ?? $this->display_name,
            'display_name' => $this->display_name,
            'tagline' => (string) ($this->tagline ?? ''),
            'about' => (string) ($this->about ?? ''),
            'city' => (string) ($this->city ?? ''),
            'address' => (string) ($this->address ?? ''),
            'cover' => CatalogMedia::absolute($this->cover_url),
            'logo' => CatalogMedia::absolute($this->logo_url),
            'contact' => [
                'whatsapp' => (string) $this->whatsapp,
                'phone' => (string) ($this->phone ?: $this->whatsapp),
                'email' => $this->email,
            ],
            'social' => [
                'instagram' => $this->social['instagram'] ?? null,
                'facebook' => $this->social['facebook'] ?? null,
                'telegram' => $this->social['telegram'] ?? null,
            ],
            'hours' => $this->hours ?? [],
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'delivery_note' => $this->delivery_note,
            'payment_note' => $this->payment_note,
            'theme' => [
                'accent' => $this->accent_color,
                'accent_contrast' => $this->accent_contrast,
            ],
            // Categorías derivadas de los productos (las inyecta CatalogService).
            'categories' => $this->categories ?? [],
            'published' => (bool) $this->is_published,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
