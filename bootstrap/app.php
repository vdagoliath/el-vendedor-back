<?php

use App\Http\Middleware\EnsureBackofficeAccess;
use App\Http\Middleware\EnsureBackofficeSuperAdmin;
use App\Http\Middleware\EnsureCurrentBusiness;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleSyncRequest;
use App\Http\Middleware\SetLocaleFromUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->alias([
            'backoffice.access' => EnsureBackofficeAccess::class,
            'backoffice.super-admin' => EnsureBackofficeSuperAdmin::class,
            'current.business' => EnsureCurrentBusiness::class,
            'sync.request' => HandleSyncRequest::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocaleFromUser::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
