<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth', 'active'])->name('logout');
});

Route::get('/', fn () => response()->json(['name' => 'Mi vivero API', 'frontend' => 'Angular', 'health' => '/api/health']));
