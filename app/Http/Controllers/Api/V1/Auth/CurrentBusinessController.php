<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SwitchBusinessRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;

class CurrentBusinessController extends Controller
{
    /**
     * Update the current business for the authenticated user.
     */
    public function update(SwitchBusinessRequest $request): JsonResponse
    {
        $user = $request->user();
        $business = Business::query()->findOrFail($request->integer('business_id'));

        abort_unless($user->canAccessBusiness($business), 403);

        $user->switchCurrentBusiness($business);
        $user->load(['businesses', 'currentBusiness']);

        return response()->json([
            'message' => 'Negocio actual actualizado correctamente.',
            'user' => UserResource::make($user),
        ]);
    }
}
