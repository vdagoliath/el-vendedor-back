<?php

namespace App\Support\Sync;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Multi-entity cursor used by the serial pull strategy.
 *
 * Each entity_type has its own (updated_at, id) sub-cursor, so draining one
 * table cannot accidentally skip records in another. Encoded as JSON:
 *   {"v":2,"c":{"products":"2026-04-20T10:00:00Z|123","contacts":"..."}}
 *
 * A legacy tuple cursor (e.g. "2026-04-20T10:00:00Z|738") is still accepted
 * for backwards compatibility: its updated_at is applied to every entity
 * with id=0 (so we may re-deliver records whose updated_at equals that of
 * the legacy cursor, which is safe — the client upserts by external_id).
 */
final class SyncEntityCursorBundle
{
    /**
     * Per-entity cursors keyed by entity_type.
     *
     * The special key '*' holds a legacy fallback that applies to any
     * entity_type that has no explicit sub-cursor yet. It is dropped as
     * soon as we start writing per-entity cursors.
     *
     * @var array<string, SyncCursor>
     */
    private array $entityCursors;

    /**
     * @param  array<string, SyncCursor>  $entityCursors
     */
    public function __construct(array $entityCursors = [])
    {
        $this->entityCursors = $entityCursors;
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function parse(?string $encoded): self
    {
        if (! is_string($encoded)) {
            return new self;
        }

        $trimmed = trim($encoded);
        if ($trimmed === '') {
            return new self;
        }

        if (str_starts_with($trimmed, '{')) {
            return self::parseJson($trimmed);
        }

        $legacy = SyncCursor::parse($trimmed);
        if (! $legacy) {
            return new self;
        }

        // Legacy clients send a single (T, id) tuple. The id came from an
        // arbitrary table, so applying it per-entity would skip records.
        // Normalize to (T, 0) as a shared fallback; per-entity cursors will
        // overwrite it on the first serial response.
        $normalized = new SyncCursor($legacy->updatedAt, 0);

        return new self(['*' => $normalized]);
    }

    private static function parseJson(string $encoded): self
    {
        $decoded = json_decode($encoded, true);
        if (! is_array($decoded)) {
            return new self;
        }

        $entries = is_array($decoded['c'] ?? null) ? $decoded['c'] : [];
        $parsed = [];

        foreach ($entries as $entityType => $cursorString) {
            if (! is_string($entityType) || ! is_string($cursorString)) {
                continue;
            }

            $cursor = SyncCursor::parse($cursorString);
            if ($cursor) {
                $parsed[$entityType] = $cursor;
            }
        }

        return new self($parsed);
    }

    public function cursorFor(string $entityType): ?SyncCursor
    {
        return $this->entityCursors[$entityType] ?? $this->entityCursors['*'] ?? null;
    }

    public function hasExplicitCursor(string $entityType): bool
    {
        return array_key_exists($entityType, $this->entityCursors);
    }

    public function withAdvance(string $entityType, ?CarbonInterface $updatedAt, int $id): self
    {
        $normalized = $updatedAt ? Carbon::instance($updatedAt) : null;

        $next = $this->entityCursors;
        unset($next['*']);
        $next[$entityType] = new SyncCursor($normalized, $id);

        return new self($next);
    }

    public function toString(): string
    {
        $map = [];
        foreach ($this->entityCursors as $type => $cursor) {
            if ($type === '*') {
                continue;
            }
            $map[$type] = $cursor->toString();
        }

        return (string) json_encode([
            'v' => 2,
            'c' => $map,
        ], JSON_UNESCAPED_SLASHES);
    }
}
