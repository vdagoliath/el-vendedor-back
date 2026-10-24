<?php

namespace App\Http\Requests\Api\Marketplace\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReserveMarketplaceQuoteRequest extends FormRequest
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
        return [];
    }
}
