<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessTableController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\LayawayController;
use App\Http\Controllers\Api\V1\OpenTabController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReceivableController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\StockMovementController;
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
            Route::apiResource('stock-movements', StockMovementController::class)->only(['index', 'store']);
        });

        Route::middleware('feature:expenses')->group(function () {
            Route::get('/expense-types', [ExpenseTypeController::class, 'index'])->name('expense-types.index');
            Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expense-types.store');
            Route::apiResource('expenses', ExpenseController::class);
        });

        Route::middleware('feature:discounts')->group(function () {
            Route::apiResource('discounts', DiscountController::class);
        });

        Route::post('/sales/{sale}/reverse', [SaleController::class, 'reverse'])->name('sales.reverse');
        Route::apiResource('sales', SaleController::class)->only(['index', 'show', 'store']);

        Route::middleware('feature:open_tabs')->group(function () {
            Route::apiResource('tables', BusinessTableController::class);

            // parameters(): fuerza el binding a {sale} en vez del {open_tab}
            // que generaria el nombre del recurso - el resto de metodos del
            // controller (addItems, close, etc.) ya reciben Sale $sale, y un
            // nombre de parametro distinto rompe el binding implicito (el
            // mismo bug que ya encontramos y arreglamos en BusinessTableController).
            Route::apiResource('open-tabs', OpenTabController::class)
                ->only(['index', 'show', 'store', 'destroy'])
                ->parameters(['open-tabs' => 'sale']);
            Route::post('/open-tabs/{sale}/items', [OpenTabController::class, 'addItems'])->name('open-tabs.items.add');
            Route::put('/open-tabs/{sale}/items', [OpenTabController::class, 'syncItems'])->name('open-tabs.items.sync');
            Route::post('/open-tabs/{sale}/partial-payments', [OpenTabController::class, 'recordPartialPayment'])->name('open-tabs.partial-payments.store');
            Route::post('/open-tabs/{sale}/close', [OpenTabController::class, 'close'])->name('open-tabs.close');
        });

        Route::middleware('feature:receivables')->group(function () {
            Route::post('/receivables/{receivable}/collect', [ReceivableController::class, 'collect'])->name('receivables.collect');
            Route::apiResource('receivables', ReceivableController::class)->only(['index', 'show']);
        });

        Route::middleware('feature:layaway')->group(function () {
            Route::post('/layaways/{layaway}/payments', [LayawayController::class, 'storePayment'])->name('layaways.payments.store');
            Route::put('/layaways/{layaway}/items', [LayawayController::class, 'updateItems'])->name('layaways.items.update');
            Route::post('/layaways/{layaway}/complete', [LayawayController::class, 'complete'])->name('layaways.complete');
            Route::post('/layaways/{layaway}/cancel', [LayawayController::class, 'cancel'])->name('layaways.cancel');
            Route::apiResource('layaways', LayawayController::class)->only(['index', 'show', 'store']);
        });
    });
});
