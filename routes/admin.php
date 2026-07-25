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

    // Order matters: permission authorization must run before the
    // throttle, so an unauthorized attempt is always rejected as 403
    // rather than ever counting against - or being blocked by - the rate
    // limit. Verified from Laravel's own middleware-priority source: this
    // pair isn't reordered relative to each other because 'auth' (which
    // the throttle's own priority position already sits behind) is the
    // only priority-listed middleware ahead of it here.
    Route::post('/staff-access', [StaffAccessController::class, 'store'])
        ->middleware(['permission:staff.manage', 'throttle:staff-role-changes'])
        ->name('staff-access.store');
});
