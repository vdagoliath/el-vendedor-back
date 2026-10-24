<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use App\Models\CatalogStorefront;
use App\Support\CatalogImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Limpia las imágenes del catálogo que ya no referencia ningún negocio ni
 * producto (por ejemplo, si un push se interrumpió después de subir el archivo).
 *
 *   php artisan catalog:prune-images --dry-run
 *   php artisan catalog:prune-images
 */
class PruneCatalogImagesCommand extends Command
{
    protected $signature = 'catalog:prune-images {--dry-run : Muestra lo que se borraría sin borrar nada}';

    protected $description = 'Elimina imágenes de catálogo que ya no referencia ningún negocio ni producto';

    public function handle(): int
    {
        $referenced = [];

        foreach (CatalogStorefront::query()->get(['cover_url', 'logo_url']) as $storefront) {
            foreach ([$storefront->cover_url, $storefront->logo_url] as $url) {
                $path = CatalogImage::relativePath(is_string($url) ? $url : null);

                if ($path !== null) {
                    $referenced[$path] = true;
                }
            }
        }

        foreach (CatalogProduct::query()->get(['images']) as $product) {
            foreach ($product->images ?? [] as $url) {
                $path = CatalogImage::relativePath(is_string($url) ? $url : null);

                if ($path !== null) {
                    $referenced[$path] = true;
                }
            }
        }

        $files = Storage::disk('public')->allFiles('catalog');
        $orphans = array_values(array_diff($files, array_keys($referenced)));
        $dryRun = (bool) $this->option('dry-run');

        foreach ($orphans as $path) {
            if (! $dryRun) {
                Storage::disk('public')->delete($path);
            }
        }

        $this->info(sprintf(
            '%s %d imagen(es) huérfana(s) de %d archivo(s) en catalog/.',
            $dryRun ? 'Se borrarían' : 'Se borraron',
            count($orphans),
            count($files),
        ));

        return self::SUCCESS;
    }
}
