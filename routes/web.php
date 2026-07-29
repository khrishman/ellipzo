<?php

use App\Http\Controllers\AccountRestrictedController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Wallet\TransactionHistoryController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('public/welcome', [
        'laravelVersion' => app()->version(),
        'phpVersion' => PHP_VERSION,
    ]);
})->name('home');

Route::get('/how-it-works', function () {
    return Inertia::render('public/how-it-works');
})->name('how-it-works');

Route::get('/earn', function () {
    return Inertia::render('public/earn');
})->name('earn');

Route::get('/advertise', function () {
    return Inertia::render('public/advertise');
})->name('advertise');

Route::get('/help', function () {
    return Inertia::render('public/help');
})->name('help');

Route::get('/legal/{document}', [LegalController::class, 'show'])->name('legal.show');

// Order matters: auth -> account-status restriction -> email verification,
// so a suspended/closed user gets the restriction response even if they
// are also unverified - verified via reflection on the live middleware
// priority list (see bootstrap/app.php), not assumed from declaration
// order alone.
Route::middleware(['auth', 'account.protected', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

    Route::get('/transactions', [TransactionHistoryController::class, 'show'])->name('transactions.index');

    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('settings.profile.edit');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile.update');
});

// Deliberately outside the 'account.protected' middleware above - gating
// this page with the same middleware that redirects here would create a
// redirect loop. Restricted users must be able to reach it, so it's
// 'auth' only.
Route::get('/account/restricted', [AccountRestrictedController::class, 'show'])
    ->middleware('auth')
    ->name('account.restricted');

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
