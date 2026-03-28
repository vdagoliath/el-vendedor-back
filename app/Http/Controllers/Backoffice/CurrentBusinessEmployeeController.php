<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CurrentBusinessEmployeeController extends Controller
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:40'],
        ]);

        $entityId = 'employee_'.now()->format('YmdHisv').'_'.Str::lower(Str::random(6));

        $this->syncStore->appendServerEvent(
            $business,
            $request->user(),
            'employees',
            $entityId,
            'upsert',
            [
                'name' => trim($validated['name']),
                'mobile' => trim($validated['mobile']),
            ]
        );

        return to_route('backoffice.team.index')->with('success', 'El empleado fue creado y se sincronizara con los dispositivos.');
    }

    public function update(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $employee = $this->syncStore->latestPayloadMap($business, 'employees')->get($entityId);

        if (! is_array($employee)) {
            return to_route('backoffice.team.index')->with('error', 'El empleado no existe o ya no esta disponible.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:40'],
        ]);

        $employee['name'] = trim($validated['name']);
        $employee['mobile'] = trim($validated['mobile']);

        $this->syncStore->appendServerEvent($business, $request->user(), 'employees', $entityId, 'upsert', $employee);

        return to_route('backoffice.team.index')->with('success', 'El empleado fue actualizado.');
    }

    public function destroy(Request $request, string $entityId): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $employee = $this->syncStore->latestPayloadMap($business, 'employees')->get($entityId);

        if (! is_array($employee)) {
            return to_route('backoffice.team.index')->with('error', 'El empleado no existe o ya no esta disponible.');
        }

        $this->syncStore->appendServerEvent($business, $request->user(), 'employees', $entityId, 'delete', null);

        return to_route('backoffice.team.index')->with('success', 'El empleado fue eliminado y se sincronizara con los dispositivos.');
    }
}
