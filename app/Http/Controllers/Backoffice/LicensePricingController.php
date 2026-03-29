<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\LicensePricingConfig;
use App\Models\LicensePricingRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LicensePricingController extends Controller
{
    public function updateConfig(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canPrepareBusinessesForSync(), 403);

        $validated = $request->validate([
            'active_pos_operation_window_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $config = LicensePricingConfig::query()->firstOrCreate([], [
            'active_pos_operation_window_days' => 30,
        ]);

        $config->forceFill([
            'active_pos_operation_window_days' => (int) $validated['active_pos_operation_window_days'],
        ])->save();

        return to_route('backoffice.businesses.index')->with('success', 'La ventana de actividad para puntos de venta se actualizó correctamente.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canPrepareBusinessesForSync(), 403);

        $validated = $this->validateRule($request);

        LicensePricingRule::query()->create($validated);

        return to_route('backoffice.businesses.index')->with('success', 'La regla de precio de licencia fue creada correctamente.');
    }

    public function update(Request $request, LicensePricingRule $licensePricingRule): RedirectResponse
    {
        abort_unless($request->user()?->canPrepareBusinessesForSync(), 403);

        $validated = $this->validateRule($request);

        $licensePricingRule->forceFill($validated)->save();

        return to_route('backoffice.businesses.index')->with('success', 'La regla de precio de licencia fue actualizada correctamente.');
    }

    public function destroy(Request $request, LicensePricingRule $licensePricingRule): RedirectResponse
    {
        abort_unless($request->user()?->canPrepareBusinessesForSync(), 403);

        $licensePricingRule->delete();

        return to_route('backoffice.businesses.index')->with('success', 'La regla de precio de licencia fue eliminada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'max:8'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'min_active_pos' => ['required', 'integer', 'min:0'],
            'max_active_pos' => ['nullable', 'integer', 'gte:min_active_pos'],
            'min_active_owners' => ['nullable', 'integer', 'min:0'],
            'max_active_owners' => ['nullable', 'integer'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (
            isset($validated['min_active_owners'], $validated['max_active_owners'])
            && $validated['max_active_owners'] < $validated['min_active_owners']
        ) {
            abort(422, 'El máximo de dueños activos no puede ser menor que el mínimo.');
        }

        return [
            'name' => trim((string) $validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'currency' => strtoupper(trim((string) $validated['currency'])),
            'monthly_price' => (float) $validated['monthly_price'],
            'min_active_pos' => (int) $validated['min_active_pos'],
            'max_active_pos' => isset($validated['max_active_pos']) ? (int) $validated['max_active_pos'] : null,
            'min_active_owners' => isset($validated['min_active_owners']) ? (int) $validated['min_active_owners'] : null,
            'max_active_owners' => isset($validated['max_active_owners']) ? (int) $validated['max_active_owners'] : null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }
}
