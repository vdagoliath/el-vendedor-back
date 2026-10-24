<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Support\Backoffice\StockAdjustmentsExcelExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentsExcelExporter $excelExporter
    ) {}

    public function index(Request $request): Response
    {
        $business = $this->authorizeAnalyticsAccess($request);
        $filters = $this->resolveFilters($request);

        $productsByExternalId = $this->productsByExternalId($business);
        $warehousesByExternalId = $this->warehousesByExternalId($business);

        $paginated = $this->buildQuery($business, $filters, $productsByExternalId)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockAdjustment $adjustment): array => $this->mapAdjustment($adjustment, $productsByExternalId, $warehousesByExternalId));

        $statsQuery = $this->buildQuery($business, $filters, $productsByExternalId);
        $stats = [
            'count' => (clone $statsQuery)->count(),
            'total_change' => (float) (clone $statsQuery)->sum('change_quantity'),
        ];

        return Inertia::render('backoffice/StockAdjustments', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => $filters,
            'stats' => $stats,
            'adjustments' => $paginated,
            'warehouses' => $warehousesByExternalId
                ->map(fn (Warehouse $warehouse): array => [
                    'external_id' => $warehouse->external_id,
                    'name' => $warehouse->name,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $business = $this->authorizeAnalyticsAccess($request);
        $filters = $this->resolveFilters($request);

        $productsByExternalId = $this->productsByExternalId($business);
        $warehousesByExternalId = $this->warehousesByExternalId($business);

        $adjustments = $this->buildQuery($business, $filters, $productsByExternalId)
            ->get()
            ->map(fn (StockAdjustment $adjustment): array => $this->mapAdjustment($adjustment, $productsByExternalId, $warehousesByExternalId))
            ->all();

        $stream = $this->excelExporter->buildStream($business, $adjustments);
        $filename = sprintf('ajustes-inventario-%s-%s.xlsx', $business->slug ?? $business->id, now()->format('Ymd-His'));

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
     * @return array{search: string, warehouse: string, reason: string, start_date: ?string, end_date: ?string}
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'search' => trim($request->string('search')->toString()),
            'warehouse' => trim($request->string('warehouse')->toString()),
            'reason' => trim($request->string('reason')->toString()),
            'start_date' => $this->normalizeDateInput($request->string('start_date')->toString()),
            'end_date' => $this->normalizeDateInput($request->string('end_date')->toString()),
        ];
    }

    /**
     * @param  array{search: string, warehouse: string, reason: string, start_date: ?string, end_date: ?string}  $filters
     * @param  Collection<string, Product>  $productsByExternalId
     * @return Builder<StockAdjustment>
     */
    private function buildQuery(Business $business, array $filters, Collection $productsByExternalId): Builder
    {
        $timezone = $this->resolveTimezone();

        $query = StockAdjustment::query()
            ->where('business_id', $business->id)
            ->orderByDesc('adjustment_at')
            ->orderByDesc('id');

        if ($filters['warehouse'] !== '') {
            $query->where('warehouse_external_id', $filters['warehouse']);
        }

        if ($filters['reason'] !== '') {
            $query->where('reason', 'like', '%'.$filters['reason'].'%');
        }

        if ($filters['start_date'] !== null) {
            $startAt = Carbon::createFromFormat('Y-m-d', $filters['start_date'], $timezone)->startOfDay()->utc();
            $query->where('adjustment_at', '>=', $startAt);
        }

        if ($filters['end_date'] !== null) {
            $endAt = Carbon::createFromFormat('Y-m-d', $filters['end_date'], $timezone)->endOfDay()->utc();
            $query->where('adjustment_at', '<=', $endAt);
        }

        if ($filters['search'] !== '') {
            $matchingProductIds = $productsByExternalId
                ->filter(function (Product $product) use ($filters): bool {
                    $haystack = strtolower(implode(' ', array_filter([
                        (string) $product->title,
                        (string) $product->code,
                    ])));

                    return str_contains($haystack, strtolower($filters['search']));
                })
                ->keys()
                ->all();

            $query->whereIn('product_external_id', $matchingProductIds === [] ? [''] : $matchingProductIds);
        }

        return $query;
    }

    /**
     * @param  Collection<string, Product>  $productsByExternalId
     * @param  Collection<string, Warehouse>  $warehousesByExternalId
     * @return array<string, mixed>
     */
    private function mapAdjustment(StockAdjustment $adjustment, Collection $productsByExternalId, Collection $warehousesByExternalId): array
    {
        $product = $productsByExternalId->get($adjustment->product_external_id);
        $warehouse = $warehousesByExternalId->get($adjustment->warehouse_external_id);

        return [
            'id' => $adjustment->id,
            'external_id' => $adjustment->external_id,
            'adjustment_at' => $adjustment->adjustment_at?->toIso8601String(),
            'reason' => $adjustment->reason,
            'previous_quantity' => $adjustment->previous_quantity !== null ? (float) $adjustment->previous_quantity : null,
            'target_quantity' => (float) $adjustment->target_quantity,
            'change_quantity' => (float) $adjustment->change_quantity,
            'product' => [
                'external_id' => $adjustment->product_external_id,
                'title' => $product?->title ?? 'Producto desconocido',
                'code' => $product?->code,
            ],
            'warehouse' => [
                'external_id' => $adjustment->warehouse_external_id,
                'name' => $warehouse?->name ?? 'Almacén desconocido',
            ],
        ];
    }

    /**
     * @return Collection<string, Product>
     */
    private function productsByExternalId(Business $business): Collection
    {
        return Product::query()
            ->where('business_id', $business->id)
            ->get(['external_id', 'title', 'code'])
            ->keyBy('external_id');
    }

    /**
     * @return Collection<string, Warehouse>
     */
    private function warehousesByExternalId(Business $business): Collection
    {
        return Warehouse::query()
            ->where('business_id', $business->id)
            ->get(['external_id', 'name'])
            ->keyBy('external_id');
    }

    private function normalizeDateInput(string $value): ?string
    {
        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function resolveTimezone(): string
    {
        $appTimezone = (string) config('app.timezone', 'UTC');

        return $appTimezone !== 'UTC' ? $appTimezone : 'America/Havana';
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
