<?php

namespace App\Http\Requests\Api\Marketplace\V1;

use Illuminate\Foundation\Http\FormRequest;

class CatalogProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'in_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function perPage(): int
    {
        return min((int) $this->integer('per_page', 15), 50);
    }
}
