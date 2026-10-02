<?php

namespace App\Http\Requests\Api\Catalog\V1;

use App\Modules\Catalog\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogProductIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
            'only_available' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(CatalogService::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    public function sort(): ?string
    {
        $sort = $this->query('sort');

        return is_string($sort) && in_array($sort, CatalogService::SORTS, true) ? $sort : null;
    }

    public function perPage(): int
    {
        return min(max((int) $this->integer('per_page', 24), 1), 60);
    }
}
