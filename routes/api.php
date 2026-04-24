<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedTokenController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\CurrentBusinessController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\Auth\SellerInvitationController;
use App\Http\Controllers\Api\V1\CashRegister\CashRegisterSessionController;
use App\Http\Controllers\Api\V1\Sync\ReprocessFailedEventsController;
use App\Http\Controllers\Api\V1\Sync\SyncBootstrapController;
use App\Http\Controllers\Api\V1\Sync\SyncConflictController;
use App\Http\Controllers\Api\V1\Sync\SyncPullController;
use App\Http\Controllers\Api\V1\Sync\SyncPushController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::prefix('auth')
            ->name('auth.')
            ->group(function (): void {
                Route::post('register', [RegisteredUserController::class, 'store'])
                    ->middleware('throttle:6,1')
                    ->name('register');

                Route::post('login', [AuthenticatedTokenController::class, 'store'])
                    ->middleware('throttle:login')
                    ->name('login');

                Route::post('sync-token', [AuthenticatedTokenController::class, 'issueSyncToken'])
                    ->middleware('throttle:login')
                    ->name('sync-token');

                Route::post('seller-token', [SellerInvitationController::class, 'claim'])
                    ->middleware('throttle:login')
                    ->name('seller-token');

                Route::middleware('auth:sanctum')->group(function (): void {
                    Route::get('me', [AuthenticatedUserController::class, 'show'])->name('me');
                    Route::put('current-business', [CurrentBusinessController::class, 'update'])->name('current-business.update');
                    Route::post('logout', [AuthenticatedTokenController::class, 'destroy'])->name('logout');

                    Route::middleware('ability:sync:owner')->group(function (): void {
                        Route::post('seller-invitations', [SellerInvitationController::class, 'store'])
                            ->name('seller-invitations.store');
                        Route::get('seller-invitations', [SellerInvitationController::class, 'index'])
                            ->name('seller-invitations.index');
                    });
                });
            });

        Route::middleware(['auth:sanctum', 'ability:sync:owner,sync:seller', 'current.business', 'sync.request'])
            ->prefix('sync')
            ->name('sync.')
            ->group(function (): void {
                Route::get('bootstrap', [SyncBootstrapController::class, 'show'])->name('bootstrap');
                Route::get('pull', [SyncPullController::class, 'index'])->name('pull');
                Route::post('push', [SyncPushController::class, 'store'])->name('push');
                Route::post('conflicts/{conflict}/resolve', [SyncConflictController::class, 'resolve'])
                    ->whereNumber('conflict')
                    ->name('conflicts.resolve');
            });

        // Endpoint administrativo: reprocesa eventos estancados en status `failed`.
        // Restringido al owner porque implica re-materializar datos en tablas
        // compartidas del negocio (products, sales, etc.).
        Route::middleware(['auth:sanctum', 'ability:sync:owner', 'current.business'])
            ->prefix('sync')
            ->name('sync.')
            ->group(function (): void {
                Route::get('failed-events', [ReprocessFailedEventsController::class, 'summary'])
                    ->name('failed-events.summary');
                Route::post('reprocess-failed-events', [ReprocessFailedEventsController::class, 'store'])
                    ->name('failed-events.reprocess');
            });

        Route::middleware(['auth:sanctum', 'ability:sync:owner,sync:seller', 'current.business'])
            ->prefix('cash-register')
            ->name('cash-register.')
            ->group(function (): void {
                Route::get('pos/{posExternalId}/active-session', [CashRegisterSessionController::class, 'active'])
                    ->name('pos.active-session');
                Route::post('pos/{posExternalId}/open-session', [CashRegisterSessionController::class, 'open'])
                    ->name('pos.open-session');
                Route::post('sessions/{externalId}/close', [CashRegisterSessionController::class, 'close'])
                    ->name('sessions.close');
                Route::get('sessions/{externalId}/summary', [CashRegisterSessionController::class, 'summary'])
                    ->name('sessions.summary');
            });
    });
