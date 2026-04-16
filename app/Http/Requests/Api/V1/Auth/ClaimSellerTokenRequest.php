<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ClaimSellerTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitation_code' => ['required', 'string', 'max:32'],
            'device_uuid' => ['required', 'string', 'max:191'],
            'device_name' => ['required', 'string', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'invitation_code' => strtoupper(trim((string) $this->input('invitation_code'))),
            'device_uuid' => trim((string) $this->input('device_uuid')),
            'device_name' => trim((string) $this->input('device_name')),
        ]);
    }
}
