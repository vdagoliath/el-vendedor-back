<?php

namespace App\Support\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Tuple-based sync cursor: (updated_at, id).
 *
 * Encoded format: "{ISO8601}|{id}". Backwards compatible with the previous
 * timestamp-only format (if the payload has no pipe, the id defaults to 0).
 */
class SyncCursor
{
    public function __construct(
        public readonly ?Carbon $updatedAt,
        public readonly int $id
    ) {}

    public static function parse(?string $encoded): ?self
    {
        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        $encoded = trim($encoded);
        [$tsPart, $idPart] = str_contains($encoded, '|')
            ? explode('|', $encoded, 2)
            : [$encoded, '0'];

        try {
            $updatedAt = Carbon::parse($tsPart);
        } catch (Throwable) {
            return null;
        }

        $id = is_numeric($idPart) ? (int) $idPart : 0;

        return new self($updatedAt, $id);
    }

    public static function fromRecord(?Carbon $updatedAt, int $id): string
    {
        $ts = ($updatedAt ?? now())->toIso8601String();

        return $ts.'|'.$id;
    }

    /**
     * Apply the tuple-based filter to an Eloquent query:
     * (updated_at, id) > (cursor.updated_at, cursor.id)
     */
    public function applyFilter(Builder $query, string $updatedAtColumn = 'updated_at', string $idColumn = 'id'): Builder
    {
        $updatedAt = $this->updatedAt;
        $id = $this->id;

        if (! $updatedAt) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($updatedAt, $id, $updatedAtColumn, $idColumn) {
            $q->where($updatedAtColumn, '>', $updatedAt)
                ->orWhere(function (Builder $q2) use ($updatedAt, $id, $updatedAtColumn, $idColumn) {
                    $q2->where($updatedAtColumn, '=', $updatedAt)
                        ->where($idColumn, '>', $id);
                });
        });
    }

    public function toString(): string
    {
        return self::fromRecord($this->updatedAt, $this->id);
    }
}
