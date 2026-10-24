<?php

namespace App\Http\Requests\Backoffice;

use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClearBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $business = $this->route('business');

        return $business instanceof Business
            && $this->user()?->canManageBusinessFromBackoffice($business);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $business = $this->route('business');

                if (! $business instanceof Business) {
                    return;
                }

                if ($this->string('confirmation')->toString() !== $business->slug) {
                    $validator->errors()->add(
                        'confirmation',
                        'Escribe el slug exacto del negocio para confirmar la limpieza.'
                    );
                }
            },
        ];
    }
}
