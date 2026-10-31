<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [ApiController::class, 'health'])->name('api.health');
Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/me', [ApiController::class, 'me'])->name('api.me');
    Route::get('/state', [ApiController::class, 'state'])->name('api.state');
    Route::post('/quote', [ApiController::class, 'quote'])->name('api.quote');
    Route::post('/inventory', [ApiController::class, 'inventory'])->name('api.inventory');
    Route::post('/orders', [ApiController::class, 'createOrder'])->name('api.orders.create');
    Route::patch('/orders/{order}', [ApiController::class, 'status'])->whereNumber('order')->name('api.orders.status');
    Route::post('/orders/{order}/payments', [ApiController::class, 'payment'])->whereNumber('order')->name('api.orders.payment');
    Route::post('/expenses', [ApiController::class, 'createExpense'])->name('api.expenses.create');
    Route::middleware('admin')->group(function (): void {
        Route::post('/products', [ApiController::class, 'createProduct'])->name('api.products.create');
        Route::put('/products/{product}', [ApiController::class, 'updateProduct'])->name('api.products.update');
        Route::put('/settings', [ApiController::class, 'settings'])->name('api.settings');
        Route::post('/users', [ApiController::class, 'createUser'])->name('api.users.create');
        Route::patch('/users/{user}', [ApiController::class, 'updateUser'])->whereNumber('user')->name('api.users.update');
        Route::delete('/expenses/{expense}', [ApiController::class, 'deleteExpense'])->whereNumber('expense')->name('api.expenses.delete');
    });
});
