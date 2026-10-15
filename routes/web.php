<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect('/app/overview'))->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/stocknest.php';
require __DIR__.'/administration.php';
