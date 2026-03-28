<?php

namespace App\Http\Requests\Backoffice;

use App\Enums\BackofficeRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBackofficeUserRoleRequest extends FormRequest
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
            'backoffice_role' => ['nullable', Rule::enum(BackofficeRole::class)],
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
            'backoffice_role.enum' => 'El rol seleccionado no es valido para el backoffice.',
        ];
    }

    /**
     * Resolve the submitted backoffice role.
     */
    public function backofficeRole(): ?BackofficeRole
    {
        return $this->enum('backoffice_role', BackofficeRole::class);
    }
}
