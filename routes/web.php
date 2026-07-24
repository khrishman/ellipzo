<?php

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

Route::get('/dashboard', function () {
    return Inertia::render('dashboard/index');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/auth.php';
