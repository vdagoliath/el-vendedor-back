<?php

namespace App\Http\Requests\Api\V1\CashRegister;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:191'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'opened_at' => ['nullable', 'date'],
            'warehouse_external_id' => ['nullable', 'string', 'max:191'],
            'opened_by' => ['nullable', 'array'],
            'opened_by.id' => ['nullable', 'string', 'max:191'],
            'opened_by.role' => ['nullable', 'string', 'max:32'],
            'opened_by.name' => ['nullable', 'string', 'max:191'],
            'opened_by.deviceId' => ['nullable', 'string', 'max:191'],
            'opened_by.deviceName' => ['nullable', 'string', 'max:191'],
            'initial_inventory_snapshot' => ['nullable', 'array'],
        ];
    }
}
