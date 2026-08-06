<?php

use App\Http\Controllers\Api\AiToolCatalogController;
use App\Http\Controllers\Api\AiToolInvokeController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessTableController;
use App\Http\Controllers\Api\V1\CashClosingController;
use App\Http\Controllers\Api\V1\CashShiftController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\LayawayController;
use App\Http\Controllers\Api\V1\OpenTabController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReceivableController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use Illuminate\Support\Facades\Route;

// Fuera del prefijo v1 a proposito: el contrato con Nexolu IA Core (repo
// Python aparte) fija esta ruta exacta - ver core/tools/dispatch_client.py
// del lado del IA Core. No se agregan rutas nuevas por herramienta: todas
// pasan por este unico despachador (ver App\Capabilities\Registry).
Route::post('/ai/tools/invoke', [AiToolInvokeController::class, 'invoke'])
    ->middleware('ia-core.key')
    ->name('ai.tools.invoke');

Route::get('/ai/tools/catalog', [AiToolCatalogController::class, 'index'])
    ->middleware('ia-core.key')
    ->name('ai.tools.catalog');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::post('/ai/chat', [AiChatController::class, 'send'])->name('ai.chat');

        Route::get('/business', [BusinessController::class, 'show'])->name('business.show');
        Route::put('/business', [BusinessController::class, 'update'])->name('business.update');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings/{setting}', [SettingController::class, 'update'])->name('settings.update');

        Route::apiResource('support-tickets', SupportTicketController::class)->only(['index', 'store']);

        // Empleados y admins del propio negocio - la autorizacion (rol admin
        // + business_id) vive en cada Request para que 'index' liste pero no
        // exponga acciones a un employee, en vez de bloquearlo todo con
        // middleware.
        Route::get('/employees/permission-catalog', [EmployeeController::class, 'catalog'])->name('employees.catalog');
        Route::put('/employees/{employee}/permissions', [EmployeeController::class, 'updatePermissions'])->name('employees.permissions.update');
        Route::patch('/employees/{employee}/toggle', [EmployeeController::class, 'toggle'])->name('employees.toggle');
        Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('product-categories', ProductCategoryController::class);
        Route::apiResource('products', ProductController::class);

        Route::middleware('feature:clients')->group(function () {
            Route::get('/clients/search', [ClientController::class, 'search'])->name('clients.search');
            Route::apiResource('clients', ClientController::class);
        });

        Route::middleware('can-access-purchases')->group(function () {
            Route::apiResource('suppliers', SupplierController::class);
            Route::apiResource('stock-movements', StockMovementController::class)->only(['index', 'store']);

            Route::post('/purchases/{purchase}/pay', [PurchaseController::class, 'pay'])->name('purchases.pay');
            Route::apiResource('purchases', PurchaseController::class)->only(['index', 'show', 'store']);
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

        Route::middleware('feature:services')->group(function () {
            Route::post('/service-orders/{serviceOrder}/pay', [ServiceOrderController::class, 'pay'])->name('service-orders.pay');
            Route::post('/service-orders/{serviceOrder}/cancel', [ServiceOrderController::class, 'cancel'])->name('service-orders.cancel');
            // parameters(): el nombre que apiResource() deriva de 'service-orders'
            // (service_order) no coincide con el ServiceOrder $serviceOrder de los
            // metodos del controller - mismo bug ya encontrado en
            // BusinessTableController/OpenTabController.
            Route::apiResource('service-orders', ServiceOrderController::class)
                ->only(['index', 'show', 'store', 'update'])
                ->parameters(['service-orders' => 'serviceOrder']);

            Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
            Route::put('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status.update');
            Route::apiResource('appointments', AppointmentController::class)->only(['index', 'show', 'store', 'update']);
        });

        Route::prefix('superadmin')->name('superadmin.')->middleware('superadmin')->group(function () {
            require __DIR__.'/superadmin.php';
        });

        Route::middleware('feature:cash_closing')->group(function () {
            Route::get('/cash-shifts/current', [CashShiftController::class, 'current'])->name('cash-shifts.current');
            Route::post('/cash-shifts/{cashShift}/close', [CashShiftController::class, 'close'])->name('cash-shifts.close');
            // parameters(): mismo bug de siempre - 'cash-shifts' derivaria el
            // parametro 'cash_shift', que no coincide con CashShift $cashShift.
            Route::apiResource('cash-shifts', CashShiftController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->parameters(['cash-shifts' => 'cashShift']);

            Route::get('/cash-closings/preview', [CashClosingController::class, 'preview'])->name('cash-closings.preview');
            Route::post('/cash-closings/{cashClosing}/undo', [CashClosingController::class, 'undo'])->name('cash-closings.undo');
            Route::apiResource('cash-closings', CashClosingController::class)
                ->only(['index', 'show', 'store', 'update'])
                ->parameters(['cash-closings' => 'cashClosing']);
        });
    });
});
