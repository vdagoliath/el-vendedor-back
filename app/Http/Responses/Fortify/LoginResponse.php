<?php

namespace App\Http\Responses\Fortify;

use App\Support\Auth\AuthenticatedRedirectPath;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create a new response instance.
     */
    public function __construct(
        private readonly AuthenticatedRedirectPath $redirectPath
    ) {}

    public function toResponse($request)
    {
        $path = $this->redirectPath->for($request->user());

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended($path);
    }
}
