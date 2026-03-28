<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Show the synced products for the current business.
     */
    public function index(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $search = trim($request->string('search')->toString());

        $productsQuery = Product::query()
            ->where('business_id', $business->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nestedQuery) use ($search) {
                    $nestedQuery
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            });

        $products = $productsQuery
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'code' => $product->code,
                'title' => $product->title,
                'description' => $product->description,
                'type' => $product->type,
                'regular_price' => (float) $product->regular_price,
                'purchase_price' => (float) $product->purchase_price,
                'min_stock' => $product->min_stock !== null ? (float) $product->min_stock : null,
                'stock_total' => $this->resolveStockTotal($product),
                'updated_at' => $product->updated_at?->toIso8601String(),
            ]);

        $allProducts = Product::query()
            ->where('business_id', $business->id)
            ->get(['id', 'min_stock', 'stock_by_warehouse', 'updated_at']);

        $latestSyncAt = $allProducts
            ->pluck('updated_at')
            ->filter()
            ->sortDesc()
            ->first();

        return Inertia::render('backoffice/Products', [
            'currentBusiness' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ],
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'total_products' => $allProducts->count(),
                'low_stock_products' => $allProducts
                    ->filter(fn (Product $product): bool => $product->min_stock !== null && $this->resolveStockTotal($product) <= (float) $product->min_stock)
                    ->count(),
                'latest_sync_at' => $latestSyncAt?->toIso8601String(),
            ],
            'products' => $products,
        ]);
    }

    private function resolveStockTotal(Product $product): float
    {
        $stockByWarehouse = $product->stock_by_warehouse;

        if (! is_array($stockByWarehouse) || $stockByWarehouse === []) {
            return 0;
        }

        return collect($stockByWarehouse)
            ->sum(fn (mixed $item): float => is_array($item) ? (float) ($item['quantity'] ?? 0) : 0.0);
    }
}
