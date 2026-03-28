<?php

namespace App\Http\Responses\Fortify;

use App\Support\Auth\AuthenticatedRedirectPath;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

class VerifyEmailResponse implements VerifyEmailResponseContract
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

        return redirect()->intended($path.'?verified=1');
    }
}
