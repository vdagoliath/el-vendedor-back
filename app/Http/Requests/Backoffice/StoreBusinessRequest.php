<?php

namespace App\Http\Requests\Backoffice;

use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $business = $this->route('business');
        $businessId = $business instanceof Business ? $business->getKey() : $business;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('businesses', 'slug')->ignore($businessId)],
            'province' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'default_currency' => ['required', 'string', 'max:10'],
            'allow_zero_stock_sales' => ['nullable', 'boolean'],
            'enable_debt_management' => ['nullable', 'boolean'],
            'print_sale_receipt_enabled' => ['nullable', 'boolean'],
            'product_code_prefix' => ['nullable', 'string', 'max:20'],
            'product_code_digits' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => $this->filled('slug')
                ? Str::slug(trim((string) $this->input('slug')))
                : null,
            'province' => $this->filled('province') ? trim((string) $this->input('province')) : null,
            'municipality' => $this->filled('municipality') ? trim((string) $this->input('municipality')) : null,
            'street' => $this->filled('street') ? trim((string) $this->input('street')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'default_currency' => Str::upper(trim((string) $this->input('default_currency', 'CUP'))),
            'product_code_prefix' => $this->filled('product_code_prefix') ? trim((string) $this->input('product_code_prefix')) : null,
            'product_code_digits' => (int) $this->input('product_code_digits', 0),
        ]);
    }
}
