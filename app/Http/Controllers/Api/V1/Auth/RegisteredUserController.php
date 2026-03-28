<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

class RegisteredUserController extends Controller
{
    /**
     * Register a new user and issue a personal access token.
     */
    public function store(RegisterRequest $request, CreateNewUser $createNewUser): JsonResponse
    {
        $user = $createNewUser->create($request->all());
        $user->ensureCurrentBusiness();
        $user->load(['businesses', 'currentBusiness']);

        event(new Registered($user));

        $token = $user->createToken($request->string('device_name')->toString())->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
        ], 201);
    }
}
