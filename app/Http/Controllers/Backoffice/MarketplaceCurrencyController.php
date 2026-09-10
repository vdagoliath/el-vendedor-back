<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceCurrencyController extends Controller
{
    /**
     * Monedas de visualizacion gestionadas por MiraloAki.
     */
    public function index(): Response
    {
        return Inertia::render('backoffice/MarketplaceCurrencies', [
            'currencies' => MarketplaceCurrency::query()->ordered()->get()->map(fn (MarketplaceCurrency $currency): array => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'decimals' => $currency->decimals,
                'is_default' => $currency->is_default,
                'sort' => $currency->sort,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($validated): void {
            if ($validated['is_default'] ?? false) {
                MarketplaceCurrency::query()->update(['is_default' => false]);
            }

            MarketplaceCurrency::query()->create($validated);
        });

        return to_route('backoffice.marketplace.currencies.index')
            ->with('success', 'La moneda fue creada.');
    }

    public function update(Request $request, MarketplaceCurrency $currency): RedirectResponse
    {
        $validated = $this->validatePayload($request, $currency);

        DB::transaction(function () use ($validated, $currency): void {
            if ($validated['is_default'] ?? false) {
                MarketplaceCurrency::query()->whereKeyNot($currency->id)->update(['is_default' => false]);
            }

            $currency->update($validated);
        });

        return to_route('backoffice.marketplace.currencies.index')
            ->with('success', 'La moneda fue actualizada.');
    }

    public function destroy(MarketplaceCurrency $currency): RedirectResponse
    {
        if ($currency->is_default) {
            return to_route('backoffice.marketplace.currencies.index')
                ->with('error', 'No puedes eliminar la moneda predeterminada.');
        }

        $currency->delete();

        return to_route('backoffice.marketplace.currencies.index')
            ->with('success', 'La moneda fue eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?MarketplaceCurrency $currency = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'size:3',
                Rule::unique('marketplace_currencies', 'code')->ignore($currency?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:16'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'is_default' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
