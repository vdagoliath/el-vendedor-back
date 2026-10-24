<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogStorefrontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subdomain' => ['sometimes', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/'],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'about' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_contrast' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'whatsapp' => ['sometimes', 'string', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hours' => ['sometimes', 'nullable', 'array', 'max:14'],
            'hours.*.day' => ['required_with:hours', 'integer', 'between:0,6'],
            'hours.*.open' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'hours.*.close' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'hours.*.closed' => ['nullable', 'boolean'],
            'social' => ['sometimes', 'nullable', 'array'],
            'social.instagram' => ['sometimes', 'nullable', 'url', 'max:255'],
            'social.facebook' => ['sometimes', 'nullable', 'url', 'max:255'],
            'social.telegram' => ['sometimes', 'nullable', 'url', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'currency_symbol' => ['sometimes', 'nullable', 'string', 'max:8'],
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'template' => ['sometimes', 'string', 'in:clasico'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
