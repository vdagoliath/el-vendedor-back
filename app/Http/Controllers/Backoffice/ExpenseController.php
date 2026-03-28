<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
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
        $startDate = $this->normalizeDateInput($request->string('start_date')->toString());
        $endDate = $this->normalizeDateInput($request->string('end_date')->toString());

        $expenses = $this->syncStore->latestPayloads($business, 'expenses')
            ->filter(fn (array $expense): bool => $this->matchesDateRange($expense['date'] ?? null, $startDate, $endDate, $timezone))
            ->filter(function (array $expense) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                $haystack = strtolower(implode(' ', array_filter([
                    (string) ($expense['description'] ?? ''),
                    (string) ($expense['category'] ?? ''),
                ])));

                return str_contains($haystack, strtolower($search));
            })
            ->sortByDesc(fn (array $expense): int => $this->parseTimestamp($expense['date'] ?? null, $timezone)?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (array $expense): array => [
                'id' => $expense['_entity_id'],
                'date' => $this->parseTimestamp($expense['date'] ?? null, $timezone)?->toIso8601String(),
                'description' => (string) ($expense['description'] ?? ''),
                'category' => (string) ($expense['category'] ?? ''),
                'amount' => round((float) ($expense['amount'] ?? 0), 2),
            ])
            ->all();

        return Inertia::render('backoffice/Expenses', [
            'currentBusiness' => $this->mapBusiness($business),
            'filters' => [
                'search' => $search,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'stats' => [
                'count' => count($expenses),
                'total_amount' => round(collect($expenses)->sum('amount'), 2),
            ],
            'expenses' => $expenses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
        ]);

        $entityId = 'expense_'.now()->format('YmdHisv').'_'.Str::lower(Str::random(6));

        $this->syncStore->appendServerEvent(
            $business,
            $request->user(),
            'expenses',
            $entityId,
            'upsert',
            [
                'date' => Carbon::parse($validated['date'])->toIso8601String(),
                'description' => trim($validated['description']),
                'amount' => round((float) $validated['amount'], 2),
                'category' => trim($validated['category']),
            ]
        );

        return to_route('backoffice.expenses.index')->with('success', 'El gasto fue creado y se sincronizara con los dispositivos.');
    }

    public function update(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $existing = $this->syncStore->latestPayloadMap($business, 'expenses')->get($entityId);

        if (! is_array($existing)) {
            return to_route('backoffice.expenses.index')->with('error', 'El gasto no existe o ya no esta disponible.');
        }

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
        ]);

        $existing['date'] = Carbon::parse($validated['date'])->toIso8601String();
        $existing['description'] = trim($validated['description']);
        $existing['amount'] = round((float) $validated['amount'], 2);
        $existing['category'] = trim($validated['category']);

        $this->syncStore->appendServerEvent($business, $request->user(), 'expenses', $entityId, 'upsert', $existing);

        return to_route('backoffice.expenses.index')->with('success', 'El gasto fue actualizado.');
    }

    public function destroy(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canViewBackofficeAnalytics(), 403);

        $existing = $this->syncStore->latestPayloadMap($business, 'expenses')->get($entityId);

        if (! is_array($existing)) {
            return to_route('backoffice.expenses.index')->with('error', 'El gasto no existe o ya no esta disponible.');
        }

        $this->syncStore->appendServerEvent($business, $request->user(), 'expenses', $entityId, 'delete', null);

        return to_route('backoffice.expenses.index')->with('success', 'El gasto fue eliminado y se sincronizara con los dispositivos.');
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
