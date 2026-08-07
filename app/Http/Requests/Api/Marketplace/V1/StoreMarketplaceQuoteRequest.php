<?php

namespace App\Http\Requests\Api\Marketplace\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketplaceQuoteRequest extends FormRequest
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
            'consumer_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.publication_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    /**
     * @return array<int, array{publication_id:int, quantity:float}>
     */
    public function quoteLines(): array
    {
        return collect($this->validated('lines'))
            ->map(fn (array $line): array => [
                'publication_id' => (int) $line['publication_id'],
                'quantity' => (float) $line['quantity'],
            ])
            ->values()
            ->all();
    }
}
