<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceConsumerController extends Controller
{
    /**
     * Consumidores del marketplace: usuarios con una sesion `consumer` activa.
     */
    public function index(): Response
    {
        $consumers = User::query()
            ->whereHas('tokens', fn ($query) => $query->where('name', 'consumer'))
            ->withCount(['tokens as consumer_tokens_count' => fn ($query) => $query->where('name', 'consumer')])
            ->withMax(['tokens as last_used_at' => fn ($query) => $query->where('name', 'consumer')], 'last_used_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'sessions' => (int) $user->consumer_tokens_count,
                'last_used_at' => $user->last_used_at,
            ]);

        return Inertia::render('backoffice/MarketplaceConsumers', [
            'consumers' => $consumers,
        ]);
    }

    /**
     * Revoca las sesiones de consumidor de un usuario.
     */
    public function revoke(User $user): RedirectResponse
    {
        $user->tokens()->where('name', 'consumer')->delete();

        return to_route('backoffice.marketplace.consumers.index')
            ->with('success', 'Las sesiones del consumidor fueron revocadas.');
    }
}
