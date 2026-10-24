<?php

namespace App\Http\Requests\Api\V1\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReprocessFailedEventsRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 5000;

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
            'entity_types' => ['nullable', 'array'],
            'entity_types.*' => ['string', 'max:100'],
            'event_ids' => ['nullable', 'array'],
            'event_ids.*' => ['string', 'max:191'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function entityTypes(): array
    {
        $types = $this->validated('entity_types') ?? [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $t): string => trim($t), $types),
            static fn (string $t): bool => $t !== ''
        )));
    }

    /**
     * @return array<int, string>
     */
    public function eventIds(): array
    {
        $ids = $this->validated('event_ids') ?? [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $t): string => trim($t), $ids),
            static fn (string $t): bool => $t !== ''
        )));
    }

    public function resolvedLimit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
