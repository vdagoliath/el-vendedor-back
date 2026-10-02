<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\UpsertCatalogProductRequest;
use App\Http\Resources\Api\V1\Catalog\CatalogProductAdminResource;
use App\Models\Business;
use App\Models\CatalogProduct;
use App\Models\MarketplaceProductPublication;
use App\Modules\Catalog\CatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Productos del catálogo del negocio actual.
 *
 * El precio es el que define el dueño: se guarda tal cual y nunca se hereda ni
 * se convierte desde el precio del marketplace.
 */
class CatalogProductController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->business($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'visibility' => ['nullable', 'string', 'in:all,visible,hidden'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $visibility = $validated['visibility'] ?? 'all';

        $products = CatalogProduct::query()
            ->where('business_id', $business->id)
            ->when($validated['q'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->where('title', 'like', $term)->orWhere('category_name', 'like', $term);
                });
            })
            ->when($visibility === 'visible', fn (Builder $query): Builder => $query->where('is_visible', true))
            ->when($visibility === 'hidden', fn (Builder $query): Builder => $query->where('is_visible', false))
            ->orderBy('sort')
            ->orderBy('title')
            ->paginate((int) ($validated['per_page'] ?? 30));

        return CatalogProductAdminResource::collection($products);
    }

    public function upsert(UpsertCatalogProductRequest $request): CatalogProductAdminResource
    {
        $business = $this->business($request);
        $data = $request->validated();
        $externalId = $data['product_external_id'] ?? null;

        $product = is_string($externalId) && $externalId !== ''
            ? CatalogProduct::query()
                ->where('business_id', $business->id)
                ->where('product_external_id', $externalId)
                ->first()
            : null;

        if ($product instanceof CatalogProduct) {
            $product->fill($this->payload($business, $data, $product))->save();
        } else {
            $product = CatalogProduct::query()->create($this->payload($business, $data, null));
        }

        return CatalogProductAdminResource::make($product->refresh());
    }

    public function update(UpsertCatalogProductRequest $request, CatalogProduct $catalogProduct): CatalogProductAdminResource
    {
        $business = $this->business($request);

        abort_unless((int) $catalogProduct->business_id === (int) $business->id, 404);

        $catalogProduct->fill($this->payload($business, $request->validated(), $catalogProduct))->save();

        return CatalogProductAdminResource::make($catalogProduct->refresh());
    }

    public function destroy(Request $request, CatalogProduct $catalogProduct): JsonResponse
    {
        $business = $this->business($request);

        abort_unless((int) $catalogProduct->business_id === (int) $business->id, 404);

        $catalogProduct->delete();

        return response()->json(['message' => 'Producto eliminado del catálogo.']);
    }

    /**
     * Importa las publicaciones del marketplace como ítems del catálogo, usando
     * su precio como punto de partida. El dueño ajusta después el precio real.
     */
    public function import(Request $request): AnonymousResourceCollection
    {
        $business = $this->business($request);

        $publications = MarketplaceProductPublication::query()
            ->published()
            ->where('business_id', $business->id)
            ->orderBy('public_title')
            ->get();

        $existing = CatalogProduct::query()
            ->where('business_id', $business->id)
            ->whereNotNull('product_external_id')
            ->pluck('product_external_id')
            ->all();

        $sort = (int) CatalogProduct::query()->where('business_id', $business->id)->max('sort');
        $created = [];

        foreach ($publications as $publication) {
            if (in_array($publication->product_external_id, $existing, true)) {
                continue;
            }

            $sort += 1;

            $created[] = CatalogProduct::query()->create([
                'business_id' => $business->id,
                'product_external_id' => $publication->product_external_id,
                'slug' => $this->catalog->uniqueProductSlug($business->id, (string) $publication->public_title),
                'title' => $publication->public_title,
                'description' => $publication->public_description,
                'price' => $publication->public_price,
                'currency' => strtoupper((string) $publication->currency),
                'images' => $publication->images ?? [],
                'is_visible' => true,
                'is_featured' => false,
                'is_available' => true,
                'sort' => $sort,
            ]);
        }

        return CatalogProductAdminResource::collection(collect($created));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(Business $business, array $data, ?CatalogProduct $existing): array
    {
        $title = (string) $data['title'];
        $slug = $existing?->slug;

        if ($slug === null || $existing?->title !== $title) {
            $slug = $this->catalog->uniqueProductSlug($business->id, $title, $existing?->id);
        }

        $images = collect($this->field($data, 'images', $existing?->images ?? []))
            ->filter(fn ($image): bool => is_string($image) && trim($image) !== '')
            ->values()
            ->all();

        return [
            'business_id' => $business->id,
            'product_external_id' => $this->field($data, 'product_external_id', $existing?->product_external_id)
                ?: 'catalog-'.Str::ulid(),
            'slug' => $slug,
            'title' => $title,
            'description' => $this->field($data, 'description', $existing?->description),
            'price' => $data['price'],
            'currency' => strtoupper((string) $data['currency']),
            'unit' => $this->field($data, 'unit', $existing?->unit),
            'badge' => $this->field($data, 'badge', $existing?->badge),
            'category_name' => $this->field($data, 'category_name', $existing?->category_name),
            'category_emoji' => $this->field($data, 'category_emoji', $existing?->category_emoji),
            'images' => $images,
            'is_visible' => (bool) $this->field($data, 'is_visible', $existing?->is_visible ?? true),
            'is_featured' => (bool) $this->field($data, 'is_featured', $existing?->is_featured ?? false),
            'is_available' => (bool) $this->field($data, 'is_available', $existing?->is_available ?? true),
            'sort' => (int) $this->field($data, 'sort', $existing?->sort ?? 0),
        ];
    }

    /**
     * Valor enviado (aunque sea null, para poder limpiar campos) o el actual.
     *
     * @param  array<string, mixed>  $data
     */
    private function field(array $data, string $key, mixed $fallback): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $fallback;
    }

    private function business(Request $request): Business
    {
        /** @var Business $business */
        $business = $request->attributes->get('currentBusiness');

        return $business;
    }
}
