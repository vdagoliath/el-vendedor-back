<?php

namespace App\Http\Requests\Api\V1\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushSyncRequest extends FormRequest
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
            'device.id' => ['required', 'string', 'max:191'],
            'device.name' => ['nullable', 'string', 'max:255'],
            'device.platform' => ['nullable', 'string', 'max:100'],
            'device.app_version' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:191'],
            'changes' => ['required', 'array', 'min:1', 'max:1000'],
            'changes.*.event_id' => ['required', 'string', 'max:191'],
            'changes.*.entity_type' => ['required', 'string', 'max:100'],
            'changes.*.entity_id' => ['required', 'string', 'max:191'],
            'changes.*.operation' => ['required', 'string', Rule::in(['create', 'update', 'delete', 'upsert'])],
            'changes.*.occurred_at' => ['nullable', 'date'],
            'changes.*.payload' => ['nullable', 'array'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $device = (array) $this->input('device', []);
        $changes = array_map(function (mixed $change): array {
            $normalized = is_array($change) ? $change : [];

            return [
                'event_id' => trim((string) ($normalized['event_id'] ?? '')),
                'entity_type' => trim((string) ($normalized['entity_type'] ?? '')),
                'entity_id' => trim((string) ($normalized['entity_id'] ?? '')),
                'operation' => trim((string) ($normalized['operation'] ?? '')),
                'occurred_at' => $normalized['occurred_at'] ?? null,
                'payload' => isset($normalized['payload']) && is_array($normalized['payload']) ? $normalized['payload'] : null,
            ];
        }, (array) $this->input('changes', []));

        $this->merge([
            'device' => [
                'id' => trim((string) ($device['id'] ?? '')),
                'name' => isset($device['name']) ? trim((string) $device['name']) : null,
                'platform' => isset($device['platform']) ? trim((string) $device['platform']) : null,
                'app_version' => isset($device['app_version']) ? trim((string) $device['app_version']) : null,
            ],
            'cursor' => $this->filled('cursor') ? trim((string) $this->input('cursor')) : null,
            'changes' => $changes,
        ]);
    }
}
