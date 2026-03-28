<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    public function index(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $timezone = $this->resolveTimezone();
        $search = trim($request->string('search')->toString());
        $status = trim($request->string('status')->toString());
        $startDate = $this->normalizeDateInput($request->string('start_date')->toString());
        $endDate = $this->normalizeDateInput($request->string('end_date')->toString());
        $contactsById = $this->syncStore->latestPayloadMap($business, 'contacts');

        $sales = $this->syncStore->latestPayloads($business, 'sales')
            ->filter(fn (array $sale): bool => $this->matchesDateRange($sale['dateTime'] ?? null, $startDate, $endDate, $timezone))
            ->filter(fn (array $sale): bool => $status === '' || ($sale['status'] ?? 'pending') === $status)
            ->filter(function (array $sale) use ($search, $contactsById): bool {
                if ($search === '') {
                    return true;
                }

                $contactName = $this->resolveContactName($sale['contact'] ?? null, $contactsById, 'Consumidor Final');
                $actorName = strtolower(trim((string) ($sale['createdBy']['name'] ?? '')));
                $haystack = strtolower(implode(' ', array_filter([
                    (string) ($sale['reference'] ?? ''),
                    $contactName,
                    $actorName,
                    $this->implodeLineTitles($sale['lines'] ?? []),
                ])));

                return str_contains($haystack, strtolower($search));
            })
            ->sortByDesc(fn (array $sale): int => $this->parseTimestamp($sale['dateTime'] ?? null, $timezone)?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (array $sale): array => $this->mapSale($sale, $contactsById, $timezone))
            ->all();

        return Inertia::render('backoffice/Sales', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'stats' => [
                'count' => count($sales),
                'completed_count' => collect($sales)->where('status', 'completed')->count(),
                'returned_count' => collect($sales)->where('status', 'returned')->count(),
                'total_amount' => round(collect($sales)->sum('total'), 2),
            ],
            'sales' => $sales,
        ]);
    }

    public function markReturned(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $sale = $this->syncStore->latestPayloadMap($business, 'sales')->get($entityId);

        if (! is_array($sale)) {
            return to_route('backoffice.sales.index')->with('error', 'La venta no existe o ya no esta disponible.');
        }

        $status = $sale['status'] ?? 'pending';

        if ($status !== 'completed') {
            return to_route('backoffice.sales.index')->with('error', 'Solo se pueden devolver ventas completadas.');
        }

        $sale['status'] = 'returned';
        $this->syncStore->appendServerEvent($business, $request->user(), 'sales', $entityId, 'upsert', $sale);

        return to_route('backoffice.sales.index')->with('success', 'La venta fue marcada como devuelta y se sincronizara con los dispositivos.');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $contactsById
     * @return array<string, mixed>
     */
    private function mapSale(array $sale, Collection $contactsById, string $timezone): array
    {
        $lines = collect($sale['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'product_title' => trim((string) ($line['productTitle'] ?? $line['productName'] ?? 'Producto sin nombre')) ?: 'Producto sin nombre',
                'quantity' => (float) ($line['amount'] ?? $line['quantity'] ?? 0),
                'price' => (float) ($line['price'] ?? 0),
                'subtotal' => (float) ($line['subTotal'] ?? (($line['price'] ?? 0) * ($line['amount'] ?? $line['quantity'] ?? 0))),
            ])
            ->values()
            ->all();

        return [
            'id' => $sale['_entity_id'],
            'reference' => $sale['reference'] ?? $sale['_entity_id'],
            'status' => $sale['status'] ?? 'pending',
            'date_time' => $this->parseTimestamp($sale['dateTime'] ?? null, $timezone)?->toIso8601String(),
            'total' => round((float) ($sale['total'] ?? 0), 2),
            'customer_name' => $this->resolveContactName($sale['contact'] ?? null, $contactsById, 'Consumidor Final'),
            'created_by' => $this->mapActor($sale['createdBy'] ?? null),
            'lines' => $lines,
            'items_count' => count($lines),
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $contactsById
     */
    private function resolveContactName(mixed $contactId, Collection $contactsById, string $fallback): string
    {
        $contact = is_string($contactId) ? $contactsById->get($contactId) : null;

        return trim((string) ($contact['name'] ?? $fallback)) ?: $fallback;
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

    /**
     * @param  array<int, mixed>  $lines
     */
    private function implodeLineTitles(array $lines): string
    {
        return collect($lines)
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): string => (string) ($line['productTitle'] ?? $line['productName'] ?? ''))
            ->implode(' ');
    }

    private function matchesDateRange(mixed $value, ?string $startDate, ?string $endDate, string $timezone): bool
    {
        if (! $startDate && ! $endDate) {
            return true;
        }

        $date = $this->parseTimestamp($value, $timezone);
        if (! $date) {
            return false;
        }

        $startAt = $startDate ? Carbon::createFromFormat('Y-m-d', $startDate, $timezone)->startOfDay() : null;
        $endAt = $endDate ? Carbon::createFromFormat('Y-m-d', $endDate, $timezone)->endOfDay() : null;

        if ($startAt && $date->lt($startAt)) {
            return false;
        }

        if ($endAt && $date->gt($endAt)) {
            return false;
        }

        return true;
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
