<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\CatalogStorefront;
use App\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogStorefront */
class CatalogStorefrontAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'subdomain' => $this->subdomain,
            'public_url' => sprintf(
                '%s://%s.%s',
                (string) config('catalog.scheme', 'https'),
                $this->subdomain,
                (string) config('catalog.base_domain', 'miraloaki.com'),
            ),
            'display_name' => $this->display_name,
            'tagline' => $this->tagline,
            'about' => $this->about,
            'cover_url' => CatalogMedia::absolute($this->cover_url),
            'logo_url' => CatalogMedia::absolute($this->logo_url),
            'accent_color' => $this->accent_color,
            'accent_contrast' => $this->accent_contrast,
            'whatsapp' => (string) $this->whatsapp,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'hours' => $this->hours ?? [],
            'social' => $this->social ?? [],
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'delivery_note' => $this->delivery_note,
            'payment_note' => $this->payment_note,
            'template' => $this->template,
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
