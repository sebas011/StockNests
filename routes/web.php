<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (config('stocknest.portable') && ! User::query()->exists()) {
        return redirect()->route('portable.setup');
    }

    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect('/app/overview'))->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/stocknest.php';
require __DIR__.'/administration.php';
require __DIR__.'/portable.php';
