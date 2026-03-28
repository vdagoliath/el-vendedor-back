<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class AuthenticatedUserController extends Controller
{
    /**
     * Display the currently authenticated user.
     */
    public function show(Request $request): UserResource
    {
        $user = $request->user();

        $user->ensureCurrentBusiness();
        $user->load(['businesses', 'currentBusiness']);

        return UserResource::make($user);
    }
}
