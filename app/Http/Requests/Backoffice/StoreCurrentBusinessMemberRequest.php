<?php

namespace App\Http\Requests\Backoffice;

use App\Enums\BusinessRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreCurrentBusinessMemberRequest extends FormRequest
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
        return [
            'user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:user_id', 'nullable', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required_without:user_id', 'nullable', 'string', Password::default(), 'confirmed'],
            'role' => ['required', Rule::enum(BusinessRole::class)],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : null,
            'email' => $this->filled('email')
                ? Str::lower(trim((string) $this->input('email')))
                : null,
        ]);
    }
}
