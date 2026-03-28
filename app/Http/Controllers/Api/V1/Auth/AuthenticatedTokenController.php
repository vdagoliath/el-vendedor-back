<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticatedTokenController extends Controller
{
    /**
     * Create a new personal access token for the user.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        $deviceName = $request->string('device_name')->toString();

        $user->tokens()->where('name', $deviceName)->delete();
        $user->ensureCurrentBusiness();
        $user->load(['businesses', 'currentBusiness']);

        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Create a sync token only for business owners.
     */
    public function issueSyncToken(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        $user->ensureCurrentBusiness();
        $user->load(['businesses', 'currentBusiness']);

        /** @var Business|null $business */
        $business = $user->currentBusiness;

        if (! $business || ! $user->ownsBusiness($business)) {
            throw ValidationException::withMessages([
                'email' => ['Solo el dueño del negocio puede obtener el token de sincronización.'],
            ]);
        }

        $deviceName = $request->string('device_name')->toString();

        $user->tokens()->where('name', $deviceName)->delete();
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Revoke the current personal access token.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Sesion cerrada correctamente.',
        ]);
    }
}
