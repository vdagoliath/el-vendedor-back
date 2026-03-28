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

class PurchaseController extends Controller
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

        $purchases = $this->syncStore->latestPayloads($business, 'purchases')
            ->filter(fn (array $purchase): bool => $this->matchesDateRange($purchase['dateTime'] ?? null, $startDate, $endDate, $timezone))
            ->filter(fn (array $purchase): bool => $status === '' || ($purchase['status'] ?? 'pending') === $status)
            ->filter(function (array $purchase) use ($search, $contactsById): bool {
                if ($search === '') {
                    return true;
                }

                $contactName = $this->resolveContactName($purchase['contact'] ?? null, $contactsById, 'Sin proveedor');
                $actorName = strtolower(trim((string) ($purchase['createdBy']['name'] ?? '')));
                $haystack = strtolower(implode(' ', array_filter([
                    (string) ($purchase['reference'] ?? ''),
                    $contactName,
                    $actorName,
                    $this->implodeLineTitles($purchase['lines'] ?? []),
                ])));

                return str_contains($haystack, strtolower($search));
            })
            ->sortByDesc(fn (array $purchase): int => $this->parseTimestamp($purchase['dateTime'] ?? null, $timezone)?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (array $purchase): array => $this->mapPurchase($purchase, $contactsById, $timezone))
            ->all();

        return Inertia::render('backoffice/Purchases', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'stats' => [
                'count' => count($purchases),
                'pending_count' => collect($purchases)->where('status', 'pending')->count(),
                'completed_count' => collect($purchases)->where('status', 'completed')->count(),
                'total_amount' => round(collect($purchases)->sum('total'), 2),
            ],
            'purchases' => $purchases,
        ]);
    }

    public function complete(Request $request, string $entityId): RedirectResponse
    {
        return $this->updateStatus($request, $entityId, 'pending', 'completed', 'La compra pendiente fue completada.');
    }

    public function cancel(Request $request, string $entityId): RedirectResponse
    {
        return $this->updateStatus($request, $entityId, 'completed', 'canceled', 'La compra fue cancelada.');
    }

    public function destroy(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $purchase = $this->syncStore->latestPayloadMap($business, 'purchases')->get($entityId);

        if (! is_array($purchase)) {
            return to_route('backoffice.purchases.index')->with('error', 'La compra no existe o ya no esta disponible.');
        }

        if (($purchase['status'] ?? 'pending') !== 'pending') {
            return to_route('backoffice.purchases.index')->with('error', 'Solo se pueden eliminar compras pendientes.');
        }

        $this->syncStore->appendServerEvent($business, $request->user(), 'purchases', $entityId, 'delete', null);

        return to_route('backoffice.purchases.index')->with('success', 'La compra pendiente fue eliminada y se sincronizara con los dispositivos.');
    }

    private function updateStatus(
        Request $request,
        string $entityId,
        string $expectedStatus,
        string $nextStatus,
        string $successMessage
    ): RedirectResponse {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $purchase = $this->syncStore->latestPayloadMap($business, 'purchases')->get($entityId);

        if (! is_array($purchase)) {
            return to_route('backoffice.purchases.index')->with('error', 'La compra no existe o ya no esta disponible.');
        }

        if (($purchase['status'] ?? 'pending') !== $expectedStatus) {
            return to_route('backoffice.purchases.index')->with('error', 'La compra ya no esta en el estado esperado para esta accion.');
        }

        $purchase['status'] = $nextStatus;
        $this->syncStore->appendServerEvent($business, $request->user(), 'purchases', $entityId, 'upsert', $purchase);

        return to_route('backoffice.purchases.index')->with('success', $successMessage);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $contactsById
     * @return array<string, mixed>
     */
    private function mapPurchase(array $purchase, Collection $contactsById, string $timezone): array
    {
        $lines = collect($purchase['lines'] ?? [])
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
            'id' => $purchase['_entity_id'],
            'reference' => $purchase['reference'] ?? $purchase['_entity_id'],
            'status' => $purchase['status'] ?? 'pending',
            'date_time' => $this->parseTimestamp($purchase['dateTime'] ?? null, $timezone)?->toIso8601String(),
            'total' => round((float) ($purchase['total'] ?? 0), 2),
            'supplier_name' => $this->resolveContactName($purchase['contact'] ?? null, $contactsById, 'Sin proveedor'),
            'created_by' => $this->mapActor($purchase['createdBy'] ?? null),
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
