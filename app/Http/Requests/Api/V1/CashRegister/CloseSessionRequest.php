<?php

namespace App\Http\Requests\Api\V1\CashRegister;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseSessionRequest extends FormRequest
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
            'closing_balance' => ['required', 'numeric', 'min:0'],
            'closed_at' => ['nullable', 'date'],
            'closed_by' => ['nullable', 'array'],
            'closed_by.id' => ['nullable', 'string', 'max:191'],
            'closed_by.role' => ['nullable', 'string', 'max:32'],
            'closed_by.name' => ['nullable', 'string', 'max:191'],
            'closed_by.deviceId' => ['nullable', 'string', 'max:191'],
            'closed_by.deviceName' => ['nullable', 'string', 'max:191'],
            'final_inventory_snapshot' => ['nullable', 'array'],
        ];
    }
}
