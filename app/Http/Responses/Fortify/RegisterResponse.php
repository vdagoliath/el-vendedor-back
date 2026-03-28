<?php

namespace App\Http\Responses\Fortify;

use App\Support\Auth\AuthenticatedRedirectPath;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
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
            ? new JsonResponse('', 201)
            : redirect()->intended($path);
    }
}
