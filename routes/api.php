<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::get('/business', [BusinessController::class, 'show'])->name('business.show');
        Route::put('/business', [BusinessController::class, 'update'])->name('business.update');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings/{setting}', [SettingController::class, 'update'])->name('settings.update');

        Route::apiResource('product-categories', ProductCategoryController::class);
        Route::apiResource('products', ProductController::class);

        Route::middleware('feature:clients')->group(function () {
            Route::get('/clients/search', [ClientController::class, 'search'])->name('clients.search');
            Route::apiResource('clients', ClientController::class);
        });

        Route::middleware('can-access-purchases')->group(function () {
            Route::apiResource('suppliers', SupplierController::class);
        });

        Route::middleware('feature:expenses')->group(function () {
            Route::get('/expense-types', [ExpenseTypeController::class, 'index'])->name('expense-types.index');
            Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expense-types.store');
            Route::apiResource('expenses', ExpenseController::class);
        });
    });
});
