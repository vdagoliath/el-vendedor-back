<?php

namespace App\Modules\Marketplace\Catalog;

use App\Models\MarketplaceProductPublication;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class MarketplaceCatalogService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability
    ) {}

    /**
     * @param  array{q?:string|null, business_id?:int|null, in_stock?:bool, per_page?:int, page?:int, path?:string|null, query?:array<string, mixed>}  $filters
     */
    public function products(array $filters = []): LengthAwarePaginatorContract
    {
        $query = MarketplaceProductPublication::query()
            ->published()
            ->with('business')
            ->when($filters['business_id'] ?? null, function (Builder $query, int $businessId): void {
                $query->where('business_id', $businessId);
            })
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('public_title', 'like', $term)
                        ->orWhere('public_description', 'like', $term)
                        ->orWhereExists(function ($query) use ($term): void {
                            $query->selectRaw('1')
                                ->from('products')
                                ->whereColumn('products.business_id', 'marketplace_product_publications.business_id')
                                ->whereColumn('products.external_id', 'marketplace_product_publications.product_external_id')
                                ->whereNull('products.deleted_at')
                                ->where(function ($query) use ($term): void {
                                    $query
                                        ->where('title', 'like', $term)
                                        ->orWhere('code', 'like', $term);
                                });
                        });
                });
            })
            ->orderBy('public_title');

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);

        if ($filters['in_stock'] ?? false) {
            $publications = $query->get();
            $this->hydrateCatalogData($publications);

            $filtered = $publications
                ->filter(fn (MarketplaceProductPublication $publication): bool => (float) $publication->available_quantity > 0.0)
                ->values();

            $page = max((int) ($filters['page'] ?? 1), 1);

            return new LengthAwarePaginator(
                $filtered->forPage($page, $perPage)->values(),
                $filtered->count(),
                $perPage,
                $page,
                [
                    'path' => $filters['path'] ?? null,
                    'query' => $filters['query'] ?? [],
                ],
            );
        }

        $paginator = $query->paginate($perPage);
        $this->hydrateCatalogData($paginator->getCollection());

        return $paginator;
    }

    public function publishedProduct(MarketplaceProductPublication $publication): ?MarketplaceProductPublication
    {
        if ($publication->status !== MarketplaceProductPublication::StatusPublished) {
            return null;
        }

        $publication->load('business');
        $this->hydrateCatalogData(collect([$publication]));

        return $publication;
    }

    /**
     * @param  Collection<int, MarketplaceProductPublication>  $publications
     */
    private function hydrateCatalogData(Collection $publications): void
    {
        $this->attachProducts($publications);
        $this->attachWarehouses($publications);
        $this->attachAvailability($publications);
    }

    /**
     * @param  Collection<int, MarketplaceProductPublication>  $publications
     */
    private function attachProducts(Collection $publications): void
    {
        if ($publications->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->where(function (Builder $query) use ($publications): void {
                $publications->each(function (MarketplaceProductPublication $publication) use ($query): void {
                    $query->orWhere(function (Builder $query) use ($publication): void {
                        $query
                            ->where('business_id', $publication->business_id)
                            ->where('external_id', $publication->product_external_id);
                    });
                });
            })
            ->get()
            ->keyBy(fn (Product $product): string => $product->business_id.'|'.$product->external_id);

        $publications->each(function (MarketplaceProductPublication $publication) use ($products): void {
            $publication->setRelation(
                'product',
                $products->get($publication->business_id.'|'.$publication->product_external_id)
            );
        });
    }

    /**
     * @param  Collection<int, MarketplaceProductPublication>  $publications
     */
    private function attachWarehouses(Collection $publications): void
    {
        if ($publications->isEmpty()) {
            return;
        }

        $warehouses = Warehouse::query()
            ->where(function (Builder $query) use ($publications): void {
                $publications->each(function (MarketplaceProductPublication $publication) use ($query): void {
                    $query->orWhere(function (Builder $query) use ($publication): void {
                        $query
                            ->where('business_id', $publication->business_id)
                            ->where('external_id', $publication->warehouse_external_id);
                    });
                });
            })
            ->get()
            ->keyBy(fn (Warehouse $warehouse): string => $warehouse->business_id.'|'.$warehouse->external_id);

        $publications->each(function (MarketplaceProductPublication $publication) use ($warehouses): void {
            $publication->setRelation(
                'warehouse',
                $warehouses->get($publication->business_id.'|'.$publication->warehouse_external_id)
            );
        });
    }

    /**
     * @param  Collection<int, MarketplaceProductPublication>  $publications
     */
    private function attachAvailability(Collection $publications): void
    {
        $items = $publications
            ->mapWithKeys(fn (MarketplaceProductPublication $publication): array => [
                'publication:'.$publication->id => [
                    'business_id' => $publication->business_id,
                    'product_external_id' => $publication->product_external_id,
                    'warehouse_external_id' => $publication->warehouse_external_id,
                ],
            ])
            ->all();

        $available = $this->availability->availableMany($items);

        $publications->each(function (MarketplaceProductPublication $publication) use ($available): void {
            $publication->available_quantity = $available['publication:'.$publication->id] ?? 0.0;
        });
    }
}
