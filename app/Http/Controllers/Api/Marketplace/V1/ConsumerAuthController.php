<?php

namespace App\Http\Controllers\Api\Marketplace\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Marketplace\V1\ConsumerLoginRequest;
use App\Http\Requests\Api\Marketplace\V1\ConsumerRegisterRequest;
use App\Http\Resources\Api\Marketplace\V1\ConsumerUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ConsumerAuthController extends Controller
{
    /**
     * Registro de consumidor: reutiliza `users` (v1) y emite un token
     * Sanctum con ability `consumer`.
     */
    public function register(ConsumerRegisterRequest $request, CreateNewUser $createNewUser): JsonResponse
    {
        $user = $createNewUser->create($request->all());

        $token = $user->createToken('consumer', ['consumer'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => ConsumerUserResource::make($user),
        ], 201);
    }

    /**
     * Login de consumidor: credenciales de `users` y token `consumer`.
     */
    public function login(ConsumerLoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        // Una sesion de consumidor activa por cuenta (como el auth v1 por device).
        $user->tokens()->where('name', 'consumer')->delete();

        $token = $user->createToken('consumer', ['consumer'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => ConsumerUserResource::make($user),
        ]);
    }

    /**
     * Perfil del consumidor autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(ConsumerUserResource::make($request->user()));
    }

    /**
     * Cierre de sesion del consumidor.
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoca la sesion de consumidor completa (tokens con ability consumer).
        $request->user()->tokens()
            ->where('name', 'consumer')
            ->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
