<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Web admin. Every route sits behind a live session, an active account with `admin.access`,
// and (inside routes/admin.php) its own `permission:` guard.
Route::prefix('admin')
    ->as('admin.')
    ->middleware(['auth', 'admin.access'])
    ->group(base_path('routes/admin.php'));
