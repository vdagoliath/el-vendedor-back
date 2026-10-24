<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\Support\CatalogImage;
use Illuminate\Foundation\Http\FormRequest;

class UpsertCatalogProductRequest extends FormRequest
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
            'product_external_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:48'],
            'category_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'category_emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'images' => ['sometimes', 'nullable', 'array', 'max:'.CatalogImage::MAX_PRODUCT_IMAGES],
            'images.*' => ['string', 'max:2000'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
