<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imágenes del catálogo: portada, logo e imágenes propias de producto.
 *
 * Se guardan en el disco público bajo `catalog/business-{id}/…`, separadas de
 * `marketplace/…`. `delete()` solo borra archivos de nuestro prefijo, así que
 * una URL de otro origen nunca se toca por accidente.
 */
class CatalogImage
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Tope de imágenes propias por producto. */
    public const MAX_PRODUCT_IMAGES = 3;

    public static function store(Business $business, string $kind, mixed $payload): ?string
    {
        $passthrough = self::passthrough($payload);

        if ($passthrough !== null) {
            return $passthrough;
        }

        $safeKind = in_array($kind, ['cover', 'logo'], true) ? $kind : 'image';
        $decoded = self::decode($payload);

        if ($decoded === null) {
            return null;
        }

        return self::put(sprintf(
            'catalog/business-%s/%s-%s.%s',
            $business->id,
            $safeKind,
            Str::ulid(),
            $decoded['extension'],
        ), $decoded['binary']);
    }

    /** Imagen propia de un producto del catálogo. */
    public static function storeProductImage(Business $business, string $productExternalId, mixed $payload): ?string
    {
        $passthrough = self::passthrough($payload);

        if ($passthrough !== null) {
            return $passthrough;
        }

        $decoded = self::decode($payload);

        if ($decoded === null) {
            return null;
        }

        return self::put(sprintf(
            'catalog/business-%s/products/%s/%s.%s',
            $business->id,
            Str::slug($productExternalId) ?: 'producto',
            Str::ulid(),
            $decoded['extension'],
        ), $decoded['binary']);
    }

    /** Borra un archivo del catálogo a partir de su URL pública. */
    public static function delete(?string $url): void
    {
        $relative = self::relativePath($url);

        if ($relative !== null) {
            Storage::disk('public')->delete($relative);
        }
    }

    /** Ruta relativa dentro del disco público, o null si no es del catálogo. */
    public static function relativePath(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $value = trim($url);

        if (str_starts_with($value, '/storage/')) {
            $path = substr($value, strlen('/storage/'));
        } else {
            $needle = '/storage/';
            $position = strpos($value, $needle);

            if ($position === false) {
                return null;
            }

            $path = substr($value, $position + strlen($needle));
        }

        $path = ltrim($path, '/');

        return str_starts_with($path, 'catalog/') ? $path : null;
    }

    /** Una URL ya pública se conserva tal cual (no se vuelve a subir). */
    private static function passthrough(mixed $payload): ?string
    {
        if (! is_string($payload)) {
            return null;
        }

        $value = trim($payload);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^(https?:)?//#i', $value) === 1 || str_starts_with($value, '/storage/')) {
            return $value;
        }

        return null;
    }

    /**
     * Acepta tanto un data URI como el `{ contentType, data }` que envía la app.
     *
     * @return array{extension: string, binary: string}|null
     */
    private static function decode(mixed $payload): ?array
    {
        $contentType = 'image/jpeg';

        if (is_array($payload)) {
            $raw = trim((string) ($payload['data'] ?? ''));
            $declared = strtolower(trim((string) ($payload['contentType'] ?? '')));

            if (in_array($declared, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $contentType = $declared;
            }

            if (str_contains($raw, ',')) {
                $raw = substr($raw, strpos($raw, ',') + 1);
            }
        } elseif (is_string($payload)) {
            $raw = trim($payload);

            if (preg_match('#^data:(image/(?:jpeg|jpg|png|webp));base64,(.+)$#i', $raw, $matches) === 1) {
                $contentType = strtolower($matches[1]);
                $raw = $matches[2];
            } elseif (str_contains($raw, ',')) {
                $raw = substr($raw, strpos($raw, ',') + 1);
            }
        } else {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        $binary = base64_decode($raw, true);

        if ($binary === false || strlen($binary) > self::MAX_BYTES) {
            return null;
        }

        return [
            'extension' => match ($contentType) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            },
            'binary' => $binary,
        ];
    }

    private static function put(string $path, string $binary): ?string
    {
        if (! Storage::disk('public')->put($path, $binary)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
