<?php

namespace App\Http\Requests\Backoffice;

use App\Enums\BackofficeRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreBackofficeUserRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            'backoffice_role' => ['required', Rule::enum(BackofficeRole::class)],
        ];
    }

    /**
     * Get custom validation messages for the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Debes indicar un correo para el usuario de backoffice.',
            'backoffice_role.required' => 'Selecciona uno de los roles disponibles para el backoffice.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : null,
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * Resolve the submitted backoffice role.
     */
    public function backofficeRole(): ?BackofficeRole
    {
        return $this->enum('backoffice_role', BackofficeRole::class);
    }
}
