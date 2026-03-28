<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\SyncReceivedEvent;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    /**
     * Show the backoffice dashboard for the current business.
     */
    public function show(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canAccessBackofficeDashboard(), 403);

        $timezone = $this->resolveMetricsTimezone();
        $startDate = $this->normalizeDateInput($request->string('start_date')->toString());
        $endDate = $this->normalizeDateInput($request->string('end_date')->toString());

        $products = Product::query()
            ->where('business_id', $business->id)
            ->orderBy('title')
            ->get();

        $sales = $this->syncStore->latestPayloads($business, 'sales')
            ->filter(fn (array $sale): bool => ($sale['status'] ?? 'pending') === 'completed')
            ->values();

        $purchases = $this->syncStore->latestPayloads($business, 'purchases')
            ->filter(fn (array $purchase): bool => ($purchase['status'] ?? 'pending') === 'completed')
            ->values();

        $expenses = $this->syncStore->latestPayloads($business, 'expenses')->values();

        $productsByExternalId = $products
            ->filter(fn (Product $product): bool => filled($product->external_id))
            ->keyBy(fn (Product $product): string => (string) $product->external_id);

        $filteredSales = $this->filterBillsByDateRange($sales, $startDate, $endDate, $timezone);
        $filteredPurchases = $this->filterBillsByDateRange($purchases, $startDate, $endDate, $timezone);
        $filteredExpenses = $this->filterExpensesByDateRange($expenses, $startDate, $endDate, $timezone);

        $salesTotal = $filteredSales->sum(fn (array $sale): float => (float) ($sale['total'] ?? 0));
        $purchasesTotal = $filteredPurchases->sum(fn (array $purchase): float => (float) ($purchase['total'] ?? 0));
        $expensesTotal = $filteredExpenses->sum(fn (array $expense): float => (float) ($expense['amount'] ?? 0));
        $topExpenseCategories = $this->buildTopExpenseCategories($filteredExpenses);
        $lowStockProducts = $products->filter(fn (Product $product): bool => $product->min_stock !== null && $this->resolveStockTotal($product) <= (float) $product->min_stock);
        $periodPerformance = $this->buildPeriodPerformance($sales, $productsByExternalId, $timezone);
        $salesStats = $this->buildSalesStats($sales, $productsByExternalId);
        $latestActivityAt = $this->resolveLatestActivityAt($products, $business);

        return Inertia::render('Dashboard', [
            'currentBusiness' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'default_currency' => $business->default_currency ?? 'CUP',
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'overview' => [
                'sales' => round($salesTotal, 2),
                'purchases' => round($purchasesTotal, 2),
                'expenses' => round($expensesTotal, 2),
                'outgoing' => round($purchasesTotal + $expensesTotal, 2),
                'balance' => round($salesTotal - ($purchasesTotal + $expensesTotal), 2),
                'daily_average_expenses' => round($this->calculateDailyAverageExpenses($filteredExpenses, $startDate, $endDate, $timezone), 2),
            ],
            'periodPerformance' => $periodPerformance,
            'inventory' => [
                'low_stock_count' => $lowStockProducts->count(),
                'total_value' => round($this->calculateInventoryValue($products), 2),
            ],
            'topProduct' => $salesStats['top_product'],
            'totalProfit' => round($salesStats['total_profit'], 2),
            'topExpenseCategories' => $topExpenseCategories,
            'stats' => [
                'latest_activity_at' => $latestActivityAt?->toIso8601String(),
                'completed_sales_count' => $sales->count(),
                'completed_purchases_count' => $purchases->count(),
                'expenses_count' => $expenses->count(),
            ],
        ]);
    }

    /**
     * Filter bills using local day boundaries, matching the mobile app behaviour.
     *
     * @param  Collection<int, array<string, mixed>>  $bills
     * @return Collection<int, array<string, mixed>>
     */
    private function filterBillsByDateRange(Collection $bills, ?string $startDate, ?string $endDate, string $timezone): Collection
    {
        [$startAt, $endAt] = $this->buildDateRangeBounds($startDate, $endDate, $timezone);

        if (! $startAt && ! $endAt) {
            return $bills;
        }

        return $bills
            ->filter(function (array $bill) use ($startAt, $endAt, $timezone): bool {
                $dateTime = $this->parseTimestamp($bill['dateTime'] ?? null, $timezone);

                if (! $dateTime) {
                    return false;
                }

                if ($startAt && $dateTime->lt($startAt)) {
                    return false;
                }

                if ($endAt && $dateTime->gt($endAt)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Filter expenses using local day boundaries, matching the mobile app behaviour.
     *
     * @param  Collection<int, array<string, mixed>>  $expenses
     * @return Collection<int, array<string, mixed>>
     */
    private function filterExpensesByDateRange(Collection $expenses, ?string $startDate, ?string $endDate, string $timezone): Collection
    {
        [$startAt, $endAt] = $this->buildDateRangeBounds($startDate, $endDate, $timezone);

        if (! $startAt && ! $endAt) {
            return $expenses;
        }

        return $expenses
            ->filter(function (array $expense) use ($startAt, $endAt, $timezone): bool {
                $date = $this->parseTimestamp($expense['date'] ?? null, $timezone);

                if (! $date) {
                    return false;
                }

                if ($startAt && $date->lt($startAt)) {
                    return false;
                }

                if ($endAt && $date->gt($endAt)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Build sales totals and profits for day/week/month.
     *
     * @param  Collection<int, array<string, mixed>>  $sales
     * @param  Collection<string, Product>  $productsByExternalId
     * @return array<string, array<string, float>>
     */
    private function buildPeriodPerformance(Collection $sales, Collection $productsByExternalId, string $timezone): array
    {
        $today = Carbon::now($timezone);
        $todayKey = $today->format('Y-m-d');
        $startOfWeekKey = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $startOfMonthKey = $today->copy()->startOfMonth()->format('Y-m-d');

        $daySales = $this->filterBillsByDateRange($sales, $todayKey, $todayKey, $timezone);
        $weekSales = $this->filterBillsByDateRange($sales, $startOfWeekKey, $todayKey, $timezone);
        $monthSales = $this->filterBillsByDateRange($sales, $startOfMonthKey, $todayKey, $timezone);

        return [
            'day' => $this->calculatePeriodPerformance($daySales, $productsByExternalId),
            'week' => $this->calculatePeriodPerformance($weekSales, $productsByExternalId),
            'month' => $this->calculatePeriodPerformance($monthSales, $productsByExternalId),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sales
     * @param  Collection<string, Product>  $productsByExternalId
     * @return array<string, mixed>
     */
    private function buildSalesStats(Collection $sales, Collection $productsByExternalId): array
    {
        $salesStats = [];
        $totalProfit = 0.0;

        foreach ($sales as $sale) {
            foreach (($sale['lines'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $qty = $this->resolveLineQuantity($line);

                if ($qty <= 0) {
                    continue;
                }

                $productId = (string) ($line['productId'] ?? '');
                $label = $this->resolveLineProductLabel($line);
                $product = $productId !== '' ? $productsByExternalId->get($productId) : null;
                $title = $product?->title ?: $label;
                $statsKey = $productId !== '' ? $productId : $label;
                $sellPrice = (float) ($line['price'] ?? 0);
                $buyPrice = $product ? (float) $product->purchase_price : 0.0;
                $lineProfit = ($sellPrice - $buyPrice) * $qty;

                if (! array_key_exists($statsKey, $salesStats)) {
                    $salesStats[$statsKey] = [
                        'title' => $title,
                        'qty' => 0.0,
                        'profit' => 0.0,
                    ];
                }

                $salesStats[$statsKey]['qty'] += $qty;
                $salesStats[$statsKey]['profit'] += $lineProfit;
                $totalProfit += $lineProfit;
            }
        }

        $topProduct = null;

        if ($salesStats !== []) {
            $topProduct = collect($salesStats)
                ->sortByDesc('qty')
                ->values()
                ->first();

            if (is_array($topProduct)) {
                $topProduct = [
                    'title' => $topProduct['title'],
                    'qty' => round((float) $topProduct['qty'], 2),
                ];
            }
        }

        return [
            'top_product' => $topProduct,
            'total_profit' => round($totalProfit, 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sales
     * @param  Collection<string, Product>  $productsByExternalId
     * @return array{sales: float, profit: float}
     */
    private function calculatePeriodPerformance(Collection $sales, Collection $productsByExternalId): array
    {
        return [
            'sales' => round($sales->sum(fn (array $sale): float => (float) ($sale['total'] ?? 0)), 2),
            'profit' => round($this->calculateProfitFromSales($sales, $productsByExternalId), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sales
     * @param  Collection<string, Product>  $productsByExternalId
     */
    private function calculateProfitFromSales(Collection $sales, Collection $productsByExternalId): float
    {
        $totalProfit = 0.0;

        foreach ($sales as $sale) {
            foreach (($sale['lines'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $qty = $this->resolveLineQuantity($line);

                if ($qty <= 0) {
                    continue;
                }

                $productId = (string) ($line['productId'] ?? '');
                $product = $productId !== '' ? $productsByExternalId->get($productId) : null;
                $sellPrice = (float) ($line['price'] ?? 0);
                $buyPrice = $product ? (float) $product->purchase_price : 0.0;

                $totalProfit += ($sellPrice - $buyPrice) * $qty;
            }
        }

        return $totalProfit;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filteredExpenses
     * @return array<int, array{name: string, total: float}>
     */
    private function buildTopExpenseCategories(Collection $filteredExpenses): array
    {
        return $filteredExpenses
            ->groupBy(fn (array $expense): string => trim((string) ($expense['category'] ?? 'Sin categoria')) ?: 'Sin categoria')
            ->map(fn (Collection $items, string $name): array => [
                'name' => $name,
                'total' => round($items->sum(fn (array $expense): float => (float) ($expense['amount'] ?? 0)), 2),
            ])
            ->sortByDesc('total')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function calculateInventoryValue(Collection $products): float
    {
        return $products->sum(function (Product $product): float {
            return $this->resolveStockTotal($product) * (float) $product->purchase_price;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filteredExpenses
     */
    private function calculateDailyAverageExpenses(Collection $filteredExpenses, ?string $startDate, ?string $endDate, string $timezone): float
    {
        if ($filteredExpenses->isEmpty()) {
            return 0.0;
        }

        $total = $filteredExpenses->sum(fn (array $expense): float => (float) ($expense['amount'] ?? 0));

        $startAt = $startDate
            ? $this->buildBoundary($startDate, false, $timezone)
            : $filteredExpenses
                ->map(fn (array $expense): ?Carbon => $this->parseTimestamp($expense['date'] ?? null, $timezone))
                ->filter()
                ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                ->first();

        $endAt = $endDate
            ? $this->buildBoundary($endDate, true, $timezone)
            : Carbon::now($timezone);

        if (! $startAt || ! $endAt) {
            return 0.0;
        }

        $diffDays = max($startAt->diffInDays($endAt) + 1, 1);

        return $total / $diffDays;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function resolveLatestActivityAt(Collection $products, Business $business): ?Carbon
    {
        $lastProductUpdate = $products
            ->pluck('updated_at')
            ->filter()
            ->sortDesc()
            ->first();

        $lastEventUpdate = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('status', 'applied')
            ->max('updated_at');

        return collect([
            $lastProductUpdate instanceof Carbon ? $lastProductUpdate : ($lastProductUpdate ? Carbon::parse($lastProductUpdate) : null),
            $lastEventUpdate ? Carbon::parse($lastEventUpdate) : null,
        ])
            ->filter()
            ->sortDesc()
            ->first();
    }

    private function resolveStockTotal(Product $product): float
    {
        $stockByWarehouse = $product->stock_by_warehouse;

        if (! is_array($stockByWarehouse) || $stockByWarehouse === []) {
            return 0.0;
        }

        return collect($stockByWarehouse)
            ->sum(fn (mixed $item): float => is_array($item) ? (float) ($item['quantity'] ?? 0) : 0.0);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function buildDateRangeBounds(?string $startDate, ?string $endDate, string $timezone): array
    {
        return [
            $startDate ? $this->buildBoundary($startDate, false, $timezone) : null,
            $endDate ? $this->buildBoundary($endDate, true, $timezone) : null,
        ];
    }

    private function buildBoundary(string $date, bool $isEnd, string $timezone): Carbon
    {
        $boundary = Carbon::createFromFormat('Y-m-d', $date, $timezone);

        return $isEnd ? $boundary->endOfDay() : $boundary->startOfDay();
    }

    private function parseTimestamp(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDateInput(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function resolveLineQuantity(array $line): float
    {
        return (float) ($line['amount'] ?? $line['quantity'] ?? 0);
    }

    private function resolveLineProductLabel(array $line): string
    {
        return trim((string) ($line['productTitle'] ?? $line['productName'] ?? 'Producto sin nombre')) ?: 'Producto sin nombre';
    }

    private function resolveMetricsTimezone(): string
    {
        $appTimezone = (string) config('app.timezone', 'UTC');

        return $appTimezone !== 'UTC' ? $appTimezone : 'America/Havana';
    }
}
