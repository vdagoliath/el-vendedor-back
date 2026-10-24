<?php

use App\Http\Controllers\Backoffice\DashboardController;
use App\Support\Auth\AuthenticatedRedirectPath;
use Illuminate\Support\Facades\Route;

Route::get('/', function (AuthenticatedRedirectPath $redirectPath) {
    $user = request()->user();

    if ($user) {
        return redirect()->to($redirectPath->for($user));
    }

    return redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified', 'backoffice.access', 'current.business'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/backoffice.php';
