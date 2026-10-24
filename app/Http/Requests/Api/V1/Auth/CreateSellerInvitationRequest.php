<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CreateSellerInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'employee_external_id' => ['required', 'string', 'max:191'],
            'employee_name' => ['nullable', 'string', 'max:191'],
        ];
    }
}
