<?php

use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\StaffAccessController;
use Illuminate\Support\Facades\Route;

// Every route below requires a verified, authenticated session plus its
// own specific permission - there is no shared "is staff" gate. A normal
// authenticated user reaches the permission middleware and gets a 403;
// they never reach a controller.
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminOverviewController::class, 'show'])
        ->middleware('permission:admin.overview.view')
        ->name('overview');

    Route::get('/staff-access', [StaffAccessController::class, 'show'])
        ->middleware('permission:staff.view')
        ->name('staff-access.show');

    Route::post('/staff-access', [StaffAccessController::class, 'store'])
        ->middleware('permission:staff.manage')
        ->name('staff-access.store');
});
