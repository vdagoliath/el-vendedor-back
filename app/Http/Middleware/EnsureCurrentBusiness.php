<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentBusiness
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->user()?->ensureCurrentBusiness();

        if (! $business) {
            $message = 'Debes seleccionar un negocio actual para continuar.';

            if ($request->expectsJson() || $request->is('api/*')) {
                return new JsonResponse([
                    'message' => $message,
                ], 409);
            }

            return redirect()
                ->route('backoffice.businesses.index')
                ->with('error', $message);
        }

        $request->attributes->set('currentBusiness', $business);

        return $next($request);
    }
}
