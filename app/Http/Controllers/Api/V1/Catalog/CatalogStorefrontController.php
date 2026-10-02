<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\UpdateCatalogStorefrontRequest;
use App\Http\Resources\Api\V1\Catalog\CatalogStorefrontAdminResource;
use App\Models\Business;
use App\Models\CatalogStorefront;
use App\Modules\Catalog\CatalogService;
use App\Support\CatalogImage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Administración del catálogo del negocio actual: subdominio, marca, contacto,
 * horario, moneda propia y publicación. Los pedidos no se gestionan aquí.
 */
class CatalogStorefrontController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function show(Request $request): CatalogStorefrontAdminResource
    {
        return CatalogStorefrontAdminResource::make($this->storefront($request));
    }

    public function update(UpdateCatalogStorefrontRequest $request): CatalogStorefrontAdminResource
    {
        $storefront = $this->storefront($request);
        $data = $request->validated();

        if (array_key_exists('subdomain', $data)) {
            $candidate = strtolower(trim((string) $data['subdomain']));

            if ($candidate !== $storefront->subdomain
                && ! $this->catalog->subdomainAvailable($candidate, $storefront->business_id)) {
                throw ValidationException::withMessages([
                    'subdomain' => 'Ese subdominio no está disponible.',
                ]);
            }

            $data['subdomain'] = $candidate;
        }

        if (array_key_exists('whatsapp', $data)) {
            $data['whatsapp'] = preg_replace('/\D+/', '', (string) $data['whatsapp']) ?? '';
        }

        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        $publishing = array_key_exists('is_published', $data)
            ? (bool) $data['is_published']
            : (bool) $storefront->is_published;

        if ($publishing) {
            $whatsapp = array_key_exists('whatsapp', $data)
                ? (string) $data['whatsapp']
                : (string) $storefront->whatsapp;

            if ($whatsapp === '') {
                throw ValidationException::withMessages([
                    'whatsapp' => 'Para publicar el catálogo necesitas un número de WhatsApp.',
                ]);
            }
        }

        if (array_key_exists('is_published', $data)) {
            $data['published_at'] = $data['is_published']
                ? ($storefront->published_at ?? now())
                : null;
        }

        $storefront->fill($data)->save();

        return CatalogStorefrontAdminResource::make($storefront->refresh());
    }

    public function uploadCover(Request $request): CatalogStorefrontAdminResource
    {
        return $this->uploadImage($request, 'cover', 'cover_url');
    }

    public function uploadLogo(Request $request): CatalogStorefrontAdminResource
    {
        return $this->uploadImage($request, 'logo', 'logo_url');
    }

    private function uploadImage(Request $request, string $kind, string $column): CatalogStorefrontAdminResource
    {
        $validated = $request->validate([
            'image' => ['required', 'string', 'max:8000000'],
        ]);

        $storefront = $this->storefront($request);
        $url = CatalogImage::store($this->business($request), $kind, $validated['image']);

        if ($url === null) {
            throw ValidationException::withMessages([
                'image' => 'No pudimos procesar la imagen (máximo 5 MB en jpg, png o webp).',
            ]);
        }

        $storefront->forceFill([$column => $url])->save();

        return CatalogStorefrontAdminResource::make($storefront->refresh());
    }

    private function storefront(Request $request): CatalogStorefront
    {
        return $this->catalog->forBusiness($this->business($request));
    }

    private function business(Request $request): Business
    {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        return $business;
    }
}
