<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\Warehouse;
use App\Support\Backoffice\InventoryExcelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryExcelExporter $excelExporter
    ) {}

    public function index(Request $request): Response
    {
        $business = $this->authorizeAnalyticsAccess($request);
        $filters = $this->resolveFilters($request);
        $snapshot = $this->buildSnapshot($business, $filters);

        $rows = $snapshot['rows'];
        $totalRows = count($rows);
        $perPage = 25;
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        return Inertia::render('backoffice/Inventory', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => $filters,
            'stats' => [
                'product_count' => $totalRows,
                'total_quantity' => round(array_sum(array_column($rows, 'total')), 4),
                'critical_count' => count(array_filter($rows, fn (array $row): bool => $row['is_critical'])),
            ],
            'warehouses' => $snapshot['warehouses'],
            'inventory' => [
                'data' => $pageRows,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $business = $this->authorizeAnalyticsAccess($request);
        $filters = $this->resolveFilters($request);
        $snapshot = $this->buildSnapshot($business, $filters);

        $stream = $this->excelExporter->buildStream($business, $snapshot['warehouses'], $snapshot['rows']);
        $filename = sprintf('inventario-%s-%s.xlsx', $business->slug ?? $business->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function authorizeAnalyticsAccess(Request $request): Business
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        return $business;
    }

    /**
     * @return array{search: string, warehouse: string, only_with_stock: bool, only_critical: bool}
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'search' => trim($request->string('search')->toString()),
            'warehouse' => trim($request->string('warehouse')->toString()),
            'only_with_stock' => $request->boolean('only_with_stock'),
            'only_critical' => $request->boolean('only_critical'),
        ];
    }

    /**
     * @param  array{search: string, warehouse: string, only_with_stock: bool, only_critical: bool}  $filters
     * @return array{warehouses: array<int, array{external_id: string, name: string}>, rows: array<int, array<string, mixed>>}
     */
    private function buildSnapshot(Business $business, array $filters): array
    {
        $warehouses = Warehouse::query()
            ->where('business_id', $business->id)
            ->orderBy('name')
            ->get(['external_id', 'name']);

        $warehouseList = $warehouses
            ->map(fn (Warehouse $warehouse): array => [
                'external_id' => $warehouse->external_id,
                'name' => $warehouse->name,
            ])
            ->all();

        $productsQuery = Product::query()
            ->where('business_id', $business->id);

        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $productsQuery->where(function ($query) use ($term): void {
                $query->where('title', 'like', $term)
                    ->orWhere('code', 'like', $term);
            });
        }

        $products = $productsQuery
            ->orderBy('title')
            ->get(['external_id', 'title', 'code', 'min_stock'])
            ->keyBy('external_id');

        $projections = StockProjection::query()
            ->where('business_id', $business->id)
            ->whereIn('product_external_id', $products->keys()->all())
            ->get(['product_external_id', 'warehouse_external_id', 'qty']);

        $quantitiesByProduct = $projections
            ->groupBy('product_external_id')
            ->map(fn (Collection $items): array => $items
                ->mapWithKeys(fn (StockProjection $projection): array => [
                    $projection->warehouse_external_id => (float) $projection->qty,
                ])
                ->all()
            );

        $warehouseIds = array_column($warehouseList, 'external_id');

        $rows = $products
            ->map(function (Product $product) use ($quantitiesByProduct, $warehouseIds): array {
                $quantities = $quantitiesByProduct->get($product->external_id, []);
                $byWarehouse = [];
                $total = 0.0;

                foreach ($warehouseIds as $warehouseId) {
                    $qty = (float) ($quantities[$warehouseId] ?? 0);
                    $byWarehouse[$warehouseId] = $qty;
                    $total += $qty;
                }

                $minStock = $product->min_stock !== null ? (float) $product->min_stock : null;
                $isCritical = $minStock !== null && $total <= $minStock;

                return [
                    'product_external_id' => $product->external_id,
                    'product_title' => $product->title,
                    'product_code' => $product->code,
                    'min_stock' => $minStock,
                    'is_critical' => $isCritical,
                    'by_warehouse' => $byWarehouse,
                    'total' => round($total, 4),
                ];
            })
            ->values()
            ->all();

        if ($filters['warehouse'] !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ($row['by_warehouse'][$filters['warehouse']] ?? 0) > 0
            ));
        }

        if ($filters['only_with_stock']) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['total'] > 0));
        }

        if ($filters['only_critical']) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['is_critical']));
        }

        return [
            'warehouses' => $warehouseList,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBusiness(Business $business): array
    {
        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'default_currency' => $business->default_currency ?? 'CUP',
        ];
    }
}
