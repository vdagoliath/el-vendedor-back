<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\PointOfSale;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PointOfSaleController extends Controller
{
    public function index(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $timezone = $this->resolveTimezone();
        $search = trim($request->string('search')->toString());

        $warehousesByExternalId = Warehouse::query()
            ->where('business_id', $business->id)
            ->pluck('name', 'external_id');

        $pointsOfSale = PointOfSale::query()
            ->where('business_id', $business->id)
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get();

        $sessionStats = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->selectRaw('pos_external_id, status, COUNT(*) as total')
            ->groupBy('pos_external_id', 'status')
            ->get()
            ->groupBy('pos_external_id');

        $openSessions = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->open()
            ->get()
            ->keyBy('pos_external_id');

        $items = $pointsOfSale->map(function (PointOfSale $pos) use ($sessionStats, $openSessions, $warehousesByExternalId, $timezone): array {
            $stats = $sessionStats->get($pos->external_id, collect());
            $openSession = $openSessions->get($pos->external_id);

            return [
                'external_id' => $pos->external_id,
                'name' => $pos->name,
                'warehouse_external_id' => $pos->warehouse_external_id,
                'warehouse_name' => $pos->warehouse_external_id
                    ? ($warehousesByExternalId[$pos->warehouse_external_id] ?? null)
                    : null,
                'employees_count' => is_array($pos->employees) ? count($pos->employees) : 0,
                'sessions_total' => (int) $stats->sum('total'),
                'sessions_open' => (int) $stats->where('status', 'open')->sum('total'),
                'sessions_closed' => (int) $stats->where('status', 'closed')->sum('total'),
                'open_session' => $openSession ? $this->mapSession($openSession, $timezone) : null,
                'updated_at' => $pos->updated_at?->setTimezone($timezone)->toIso8601String(),
            ];
        })->values();

        return Inertia::render('backoffice/PointsOfSale', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'points_of_sale_count' => $items->count(),
                'open_sessions_count' => (int) $items->sum('sessions_open'),
                'closed_sessions_count' => (int) $items->sum('sessions_closed'),
            ],
            'points_of_sale' => $items->all(),
        ]);
    }

    public function sessions(Request $request, string $posExternalId): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $pointOfSale = PointOfSale::query()
            ->where('business_id', $business->id)
            ->where('external_id', $posExternalId)
            ->firstOrFail();

        $timezone = $this->resolveTimezone();
        $status = trim($request->string('status')->toString());
        $startDate = $this->normalizeDateInput($request->string('start_date')->toString());
        $endDate = $this->normalizeDateInput($request->string('end_date')->toString());

        $sessions = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->forPos($posExternalId)
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($startDate, function (Builder $query, string $date) use ($timezone): void {
                $query->where('opened_at', '>=', Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay());
            })
            ->when($endDate, function (Builder $query, string $date) use ($timezone): void {
                $query->where('opened_at', '<=', Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay());
            })
            ->orderByDesc('opened_at')
            ->get();

        $salesAggregates = Sale::query()
            ->where('business_id', $business->id)
            ->whereIn('cash_register_session_id', $sessions->pluck('external_id'))
            ->selectRaw('cash_register_session_id, COUNT(*) as sales_count, COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('cash_register_session_id')
            ->get()
            ->keyBy('cash_register_session_id');

        $items = $sessions->map(fn (CashRegisterSession $session): array => $this->mapSession(
            $session,
            $timezone,
            $salesAggregates->get($session->external_id)
        ))->all();

        $warehouseName = $pointOfSale->warehouse_external_id
            ? Warehouse::query()
                ->where('business_id', $business->id)
                ->where('external_id', $pointOfSale->warehouse_external_id)
                ->value('name')
            : null;

        return Inertia::render('backoffice/PointOfSaleSessions', [
            'currentBusiness' => $this->mapBusiness($business),
            'pointOfSale' => [
                'external_id' => $pointOfSale->external_id,
                'name' => $pointOfSale->name,
                'warehouse_external_id' => $pointOfSale->warehouse_external_id,
                'warehouse_name' => $warehouseName,
            ],
            'filters' => [
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'stats' => [
                'sessions_count' => count($items),
                'open_count' => collect($items)->where('status', 'open')->count(),
                'closed_count' => collect($items)->where('status', 'closed')->count(),
                'sales_total' => round((float) collect($items)->sum('sales_total'), 2),
            ],
            'sessions' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSession(
        CashRegisterSession $session,
        string $timezone,
        mixed $salesAggregate = null
    ): array {
        $openedAt = $session->opened_at?->copy()->setTimezone($timezone);
        $closedAt = $session->closed_at?->copy()->setTimezone($timezone);

        return [
            'external_id' => $session->external_id,
            'status' => $session->status,
            'opened_at' => $openedAt?->toIso8601String(),
            'closed_at' => $closedAt?->toIso8601String(),
            'duration_minutes' => $openedAt && $closedAt
                ? (int) $openedAt->diffInMinutes($closedAt)
                : null,
            'opening_balance' => (float) $session->opening_balance,
            'closing_balance' => $session->closing_balance !== null ? (float) $session->closing_balance : null,
            'opened_by' => $this->mapActor($session->opened_by),
            'closed_by' => $this->mapActor($session->closed_by),
            'sales_count' => $salesAggregate ? (int) $salesAggregate->sales_count : 0,
            'sales_total' => $salesAggregate ? round((float) $salesAggregate->sales_total, 2) : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $actor
     * @return array<string, string>|null
     */
    private function mapActor(?array $actor): ?array
    {
        if (! $actor) {
            return null;
        }

        return [
            'role' => (string) ($actor['role'] ?? 'unknown'),
            'name' => trim((string) ($actor['name'] ?? $actor['deviceName'] ?? 'Desconocido')) ?: 'Desconocido',
            'device_name' => trim((string) ($actor['deviceName'] ?? '')),
        ];
    }

    private function normalizeDateInput(string $value): ?string
    {
        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
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

    private function resolveTimezone(): string
    {
        $appTimezone = (string) config('app.timezone', 'UTC');

        return $appTimezone !== 'UTC' ? $appTimezone : 'America/Havana';
    }
}
