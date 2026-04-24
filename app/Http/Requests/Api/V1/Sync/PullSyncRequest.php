<?php

namespace App\Http\Requests\Api\V1\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PullSyncRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'max:191'],
            // El cursor en formato v2 es un JSON con un sub-cursor por cada
            // entidad sincronizable, así que necesita un margen cómodo.
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'device_id' => trim((string) $this->input('device_id')),
            'cursor' => $this->filled('cursor') ? trim((string) $this->input('cursor')) : null,
        ]);
    }
}
