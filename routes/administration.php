<?php

use App\Http\Controllers\Administration\NotificationController;
use App\Http\Controllers\Administration\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/administration/users', [UserController::class, 'index'])->name('administration.users');
    Route::post('/administration/users', [UserController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/administration/users/{user}/access', [UserController::class, 'access'])->middleware('throttle:30,1');
    Route::post('/administration/users/{user}/sessions', [UserController::class, 'revokeSessions'])->middleware('throttle:30,1');
    Route::post('/administration/roles', [UserController::class, 'role'])->middleware('throttle:30,1');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('throttle:60,1');
});
