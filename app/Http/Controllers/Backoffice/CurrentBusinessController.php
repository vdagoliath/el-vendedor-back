<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SwitchCurrentBusinessRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;

class CurrentBusinessController extends Controller
{
    /**
     * Update the current business for the authenticated user.
     */
    public function update(SwitchCurrentBusinessRequest $request): RedirectResponse
    {
        $business = Business::query()->findOrFail($request->integer('business_id'));

        abort_unless($request->user()->canAccessBusiness($business), 403);

        $request->user()->switchCurrentBusiness($business);

        return back()->with('success', 'Negocio actual actualizado correctamente.');
    }
}
