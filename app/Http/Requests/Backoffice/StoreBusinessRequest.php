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
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'default_currency' => ['required', 'string', 'max:10'],
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
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'default_currency' => Str::upper(trim((string) $this->input('default_currency', 'CUP'))),
        ]);
    }
}
