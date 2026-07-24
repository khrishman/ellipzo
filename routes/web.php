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

Route::get('/login', function () {
    return Inertia::render('auth/login');
})->name('login');

Route::get('/register', function () {
    return Inertia::render('auth/register');
})->name('register');
