<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductLoss;
use App\Models\Warehouse;
use App\Support\Backoffice\ProductLossesExcelExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductLossController extends Controller
{
    private const LOSS_TYPES = ['damaged', 'expired', 'stolen', 'other'];

    public function __construct(
        private readonly ProductLossesExcelExporter $excelExporter
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
            ->through(fn (ProductLoss $loss): array => $this->mapLoss($loss, $productsByExternalId, $warehousesByExternalId));

        $statsQuery = $this->buildQuery($business, $filters, $productsByExternalId);
        $stats = [
            'count' => (clone $statsQuery)->count(),
            'total_quantity' => (float) (clone $statsQuery)->sum('quantity'),
            'total_cost' => (float) (clone $statsQuery)->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as total')->value('total'),
        ];

        return Inertia::render('backoffice/Losses', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => $filters,
            'stats' => $stats,
            'losses' => $paginated,
            'warehouses' => $warehousesByExternalId
                ->map(fn (Warehouse $warehouse): array => [
                    'external_id' => $warehouse->external_id,
                    'name' => $warehouse->name,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'lossTypes' => self::LOSS_TYPES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $business = $this->authorizeAnalyticsAccess($request);
        $filters = $this->resolveFilters($request);

        $productsByExternalId = $this->productsByExternalId($business);
        $warehousesByExternalId = $this->warehousesByExternalId($business);

        $losses = $this->buildQuery($business, $filters, $productsByExternalId)
            ->get()
            ->map(fn (ProductLoss $loss): array => $this->mapLoss($loss, $productsByExternalId, $warehousesByExternalId))
            ->all();

        $stream = $this->excelExporter->buildStream($business, $losses);
        $filename = sprintf('mermas-%s-%s.xlsx', $business->slug ?? $business->id, now()->format('Ymd-His'));

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
     * @return array{search: string, warehouse: string, loss_type: string, start_date: ?string, end_date: ?string}
     */
    private function resolveFilters(Request $request): array
    {
        $lossType = trim($request->string('loss_type')->toString());

        if ($lossType !== '' && ! in_array($lossType, self::LOSS_TYPES, true)) {
            $lossType = '';
        }

        return [
            'search' => trim($request->string('search')->toString()),
            'warehouse' => trim($request->string('warehouse')->toString()),
            'loss_type' => $lossType,
            'start_date' => $this->normalizeDateInput($request->string('start_date')->toString()),
            'end_date' => $this->normalizeDateInput($request->string('end_date')->toString()),
        ];
    }

    /**
     * @param  array{search: string, warehouse: string, loss_type: string, start_date: ?string, end_date: ?string}  $filters
     * @param  Collection<string, Product>  $productsByExternalId
     * @return Builder<ProductLoss>
     */
    private function buildQuery(Business $business, array $filters, Collection $productsByExternalId): Builder
    {
        $timezone = $this->resolveTimezone();

        $query = ProductLoss::query()
            ->where('business_id', $business->id)
            ->orderByDesc('loss_at')
            ->orderByDesc('id');

        if ($filters['warehouse'] !== '') {
            $query->where('warehouse_external_id', $filters['warehouse']);
        }

        if ($filters['loss_type'] !== '') {
            $query->where('loss_type', $filters['loss_type']);
        }

        if ($filters['start_date'] !== null) {
            $startAt = Carbon::createFromFormat('Y-m-d', $filters['start_date'], $timezone)->startOfDay()->utc();
            $query->where('loss_at', '>=', $startAt);
        }

        if ($filters['end_date'] !== null) {
            $endAt = Carbon::createFromFormat('Y-m-d', $filters['end_date'], $timezone)->endOfDay()->utc();
            $query->where('loss_at', '<=', $endAt);
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
    private function mapLoss(ProductLoss $loss, Collection $productsByExternalId, Collection $warehousesByExternalId): array
    {
        $product = $productsByExternalId->get($loss->product_external_id);
        $warehouse = $warehousesByExternalId->get($loss->warehouse_external_id);

        $quantity = (float) $loss->quantity;
        $unitCost = $loss->unit_cost !== null ? (float) $loss->unit_cost : null;

        return [
            'id' => $loss->id,
            'external_id' => $loss->external_id,
            'loss_at' => $loss->loss_at?->toIso8601String(),
            'loss_type' => $loss->loss_type,
            'notes' => $loss->notes,
            'quantity' => $quantity,
            'previous_quantity' => $loss->previous_quantity !== null ? (float) $loss->previous_quantity : null,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? round($quantity * $unitCost, 4) : null,
            'product' => [
                'external_id' => $loss->product_external_id,
                'title' => $product?->title ?? 'Producto desconocido',
                'code' => $product?->code,
            ],
            'warehouse' => [
                'external_id' => $loss->warehouse_external_id,
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
