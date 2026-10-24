<?php

namespace App\Support;

/**
 * URLs de medios del catálogo.
 *
 * Los medios pueden venir absolutos (http/https), como data URI (dataset de
 * ejemplo) o relativos al disco público. La API pública siempre devuelve una
 * URL absoluta para que el catálogo (otro origen) pueda consumirla.
 */
class CatalogMedia
{
    public static function absolute(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $value = trim($path);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^(https?:)?//#i', $value) === 1) {
            return $value;
        }

        if (str_starts_with($value, 'data:')) {
            return $value;
        }

        return url($value);
    }

    /**
     * @param  array<int, mixed>|null  $paths
     * @return array<int, string>
     */
    public static function absoluteMany(?array $paths): array
    {
        return collect($paths ?? [])
            ->filter(fn ($path): bool => is_string($path) && trim($path) !== '')
            ->map(fn (string $path): string => (string) static::absolute($path))
            ->values()
            ->all();
    }
}
