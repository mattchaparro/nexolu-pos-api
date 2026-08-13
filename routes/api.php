<?php

use App\Http\Controllers\Api\AiToolCatalogController;
use App\Http\Controllers\Api\AiToolInvokeController;
use App\Http\Controllers\Api\NexoluCommsWebhookController;
use App\Http\Controllers\Api\NotificationSnoozeController;
use App\Http\Controllers\Api\PaymentsCoreWebhookController;
use App\Http\Controllers\Api\PublicReceiptController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AiChannelLinkController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AiDraftController;
use App\Http\Controllers\Api\V1\AiInsightController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BulkStockUpdateController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessServiceWorkflowController;
use App\Http\Controllers\Api\V1\BusinessTableController;
use App\Http\Controllers\Api\V1\CashClosingController;
use App\Http\Controllers\Api\V1\CashShiftController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\FixedExpenseTemplateController;
use App\Http\Controllers\Api\V1\IngredientBulkStockUpdateController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\IngredientStockMovementController;
use App\Http\Controllers\Api\V1\KitchenBoardController;
use App\Http\Controllers\Api\V1\LayawayController;
use App\Http\Controllers\Api\V1\OpenTabController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReceivableController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockMovementReasonController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Services\ReceiptPdfService;
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

// Publico, sin auth: Meta llama a este endpoint directamente (GET para
// verificar el webhook al configurarlo, POST para entregar mensajes/eventos).
Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'handle'])->name('webhooks.whatsapp.handle');

// Publico, sin auth: lo llama Nexolu Payments Core (repo Python aparte), no
// un usuario. Se autentica con la firma HMAC de X-Nexolu-Signature, no con
// Sanctum - ver PaymentsCoreWebhookController.
Route::post('/webhooks/payments-core', [PaymentsCoreWebhookController::class, 'handle'])->name('webhooks.payments-core.handle');

// Publico, sin auth: lo llama Nexolu Communications (repo Python aparte,
// reenvio del webhook de WhatsApp), no un usuario. Se autentica con la
// firma HMAC de X-Nexolu-Signature, no con Sanctum - ver
// NexoluCommsWebhookController. Convive con /webhooks/whatsapp mientras
// services.comms_core.driver siga en whatsapp_direct.
Route::post('/webhooks/nexolu-comms/whatsapp', [NexoluCommsWebhookController::class, 'handle'])->name('webhooks.nexolu-comms.whatsapp');

// Publico, sin auth: se abre directo desde el enlace del correo de alerta de
// inventario bajo. La firma de la URL (middleware `signed`) es la unica
// autenticacion - ver App\Http\Controllers\Api\NotificationSnoozeController.
Route::get('/notifications/low-stock/{business}/snooze', [NotificationSnoozeController::class, 'snooze'])
    ->middleware('signed')
    ->name('notifications.low-stock.snooze');

// Publico, sin auth: lo descarga el proveedor de WhatsApp al enviar un
// comprobante (ver App\Jobs\SendReceiptJob), no el usuario del negocio - la
// firma de la URL (middleware `signed`, vence a las 24h) es la unica
// autenticacion, mismo patron que notifications.low-stock.snooze arriba.
Route::get('/public/receipts/{type}/{id}', [PublicReceiptController::class, 'show'])
    ->where('type', implode('|', ReceiptPdfService::TYPES))
    ->whereNumber('id')
    ->middleware('signed')
    ->name('receipts.public.show');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::post('/ai/chat', [AiChatController::class, 'send'])->name('ai.chat');
        Route::post('/ai/drafts/{draftId}/confirm', [AiDraftController::class, 'confirm'])->name('ai.drafts.confirm');
        Route::post('/ai/drafts/{draftId}/discard', [AiDraftController::class, 'discard'])->name('ai.drafts.discard');
        Route::get('/insights', [AiInsightController::class, 'index'])->name('insights.index');
        Route::post('/insights/{type}/refresh', [AiInsightController::class, 'refresh'])->name('insights.refresh');

        Route::middleware('permission:ai_chat.use')->prefix('ai/channels/whatsapp')->name('ai.channels.whatsapp.')->group(function () {
            Route::post('/start', [AiChannelLinkController::class, 'start'])->middleware('throttle:5,1')->name('start');
            Route::post('/confirm', [AiChannelLinkController::class, 'confirm'])->middleware('throttle:10,1')->name('confirm');
            Route::delete('/', [AiChannelLinkController::class, 'unlink'])->name('unlink');
        });

        Route::get('/business', [BusinessController::class, 'show'])->name('business.show');
        Route::put('/business', [BusinessController::class, 'update'])->name('business.update');

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

        Route::get('/subscription/status', [SubscriptionController::class, 'status'])->name('subscription.status');
        Route::post('/subscription/checkout', [SubscriptionController::class, 'initiate'])->name('subscription.checkout');
        Route::get('/subscription/checkout/{reference}', [SubscriptionController::class, 'checkoutStatus'])->name('subscription.checkout.status');

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

        // inventory.view para leer el catalogo, inventory.add para tocarlo -
        // ver PermissionCatalog. apiResource se parte en dos registros con
        // ->only() distinto en vez de uno solo, para poder colgar cada mitad
        // de un middleware de permiso diferente.
        Route::middleware('permission:inventory.view')->group(function () {
            Route::apiResource('product-categories', ProductCategoryController::class)->only(['index', 'show']);
            // Antes del apiResource: /products/summary no debe caer en la
            // ruta show (/products/{product}) del resource de abajo.
            Route::get('/products/summary', [ProductController::class, 'summary'])->name('products.summary');
            Route::middleware('feature:services')->group(function () {
                Route::get('/products/services-summary', [ProductController::class, 'servicesSummary'])->name('products.services-summary');
            });
            Route::apiResource('products', ProductController::class)->only(['index', 'show']);
            // Motivos de movimiento de stock (entrada/salida/ajuste) - lectura
            // compartida por el formulario de "Ajustar stock" de productos e
            // insumos, no atada a la feature "ingredients" (los productos
            // ajustan stock sin necesitarla).
            Route::get('/stock-movement-reasons', [StockMovementReasonController::class, 'index'])->name('stock-movement-reasons.index');
        });
        Route::middleware('permission:inventory.add')->group(function () {
            Route::apiResource('product-categories', ProductCategoryController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy']);
        });
        Route::middleware('permission:inventory.adjust')->group(function () {
            Route::post('/products/bulk-update', [BulkStockUpdateController::class, 'store'])->name('products.bulk-update');
        });

        Route::middleware(['feature:clients', 'permission:clients.manage'])->group(function () {
            Route::get('/clients/search', [ClientController::class, 'search'])->name('clients.search');
            Route::apiResource('clients', ClientController::class);
        });

        Route::middleware('feature:ingredients')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                // Antes del apiResource, mismo motivo que /products/summary.
                Route::get('/ingredients/summary', [IngredientController::class, 'summary'])->name('ingredients.summary');
                Route::apiResource('ingredients', IngredientController::class)->only(['index', 'show']);
                Route::get('/ingredient-stock-movements', [IngredientStockMovementController::class, 'index'])->name('ingredient-stock-movements.index');
            });
            Route::middleware('permission:inventory.add')->group(function () {
                Route::apiResource('ingredients', IngredientController::class)->only(['store', 'update', 'destroy']);
            });
            Route::middleware('permission:inventory.adjust')->group(function () {
                Route::post('/ingredient-stock-movements', [IngredientStockMovementController::class, 'store'])->name('ingredient-stock-movements.store');
                Route::post('/ingredients/bulk-update', [IngredientBulkStockUpdateController::class, 'store'])->name('ingredients.bulk-update');
            });
        });

        Route::middleware('can-access-purchases')->group(function () {
            Route::middleware('permission:purchases.manage')->group(function () {
                Route::post('/suppliers/{supplier}/remind-visit', [SupplierController::class, 'remindVisit'])->name('suppliers.remind-visit');
                Route::apiResource('suppliers', SupplierController::class);

                Route::post('/purchases/{purchase}/pay', [PurchaseController::class, 'pay'])->name('purchases.pay');
                Route::apiResource('purchases', PurchaseController::class)->only(['index', 'show', 'store']);
            });

            Route::middleware('permission:inventory.adjust')->group(function () {
                Route::apiResource('stock-movements', StockMovementController::class)->only(['index', 'store']);
            });
        });

        Route::middleware('feature:expenses')->group(function () {
            // expenses.create alcanza para crear y para ver (necesita ver el
            // listado para no duplicar un gasto); expenses.manage es lo unico
            // que habilita editar/eliminar cualquiera, no solo el propio.
            Route::middleware('permission:expenses.create,expenses.manage')->group(function () {
                Route::get('/expense-types', [ExpenseTypeController::class, 'index'])->name('expense-types.index');
                Route::apiResource('expenses', ExpenseController::class)->only(['index', 'show']);
            });
            Route::middleware('permission:expenses.create')->group(function () {
                Route::apiResource('expenses', ExpenseController::class)->only(['store']);
            });
            Route::middleware('permission:expenses.manage')->group(function () {
                Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expense-types.store');
                Route::apiResource('expenses', ExpenseController::class)->only(['update', 'destroy']);

                // Configuracion de gastos recurrentes (arriendo, nomina...) -
                // administrativo, no algo que un empleado con solo
                // expenses.create deba tocar.
                Route::post('/fixed-expense-templates/{fixedExpenseTemplate}/register-now', [FixedExpenseTemplateController::class, 'registerNow'])->name('fixed-expense-templates.register-now');
                Route::post('/fixed-expense-templates/{fixedExpenseTemplate}/toggle-reminder', [FixedExpenseTemplateController::class, 'toggleReminder'])->name('fixed-expense-templates.toggle-reminder');
                // parameters(): el nombre que apiResource() deriva de
                // 'fixed-expense-templates' no coincide con el
                // FixedExpenseTemplate $fixedExpenseTemplate de los metodos
                // del controller - mismo bug ya encontrado en
                // BusinessTableController/OpenTabController/CashShiftController.
                Route::apiResource('fixed-expense-templates', FixedExpenseTemplateController::class)
                    ->only(['index', 'store', 'show', 'update', 'destroy'])
                    ->parameters(['fixed-expense-templates' => 'fixedExpenseTemplate']);
            });
        });

        Route::middleware(['feature:discounts', 'permission:discounts.manage'])->group(function () {
            Route::apiResource('discounts', DiscountController::class);
        });

        Route::post('/sales/{sale}/reverse', [SaleController::class, 'reverse'])->name('sales.reverse');
        Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
        Route::get('/sales/{sale}/receipt/print', [SaleController::class, 'printReceipt'])->name('sales.receipt.print');
        Route::post('/sales/{sale}/receipt/send', [SaleController::class, 'sendReceipt'])->name('sales.receipt.send');
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

        // Sin permission: middleware a proposito, igual que legacy (comandera
        // accesible por igual a admin y a cualquier empleado con el feature
        // habilitado - no es una accion administrativa sensible).
        Route::middleware('feature:kitchen_board')->prefix('kitchen')->name('kitchen.')->group(function () {
            Route::get('/tickets', [KitchenBoardController::class, 'index'])->name('tickets.index');
            Route::post('/tickets/{sale}/status', [KitchenBoardController::class, 'updateStatus'])->name('tickets.status.update');
        });

        Route::middleware(['feature:receivables', 'permission:receivables.manage'])->group(function () {
            Route::post('/receivables/{receivable}/collect', [ReceivableController::class, 'collect'])->name('receivables.collect');
            Route::apiResource('receivables', ReceivableController::class)->only(['index', 'show']);
        });

        Route::middleware(['feature:reminders', 'permission:reminders.manage'])->group(function () {
            Route::post('/reminders/{reminder}/complete', [ReminderController::class, 'complete'])->name('reminders.complete');
            Route::post('/reminders/{reminder}/postpone', [ReminderController::class, 'postpone'])->name('reminders.postpone');
            Route::apiResource('reminders', ReminderController::class)->only(['index', 'store', 'destroy']);
        });

        Route::middleware(['feature:layaway', 'permission:layaways.manage'])->group(function () {
            Route::post('/layaways/{layaway}/payments', [LayawayController::class, 'storePayment'])->name('layaways.payments.store');
            Route::put('/layaways/{layaway}/items', [LayawayController::class, 'updateItems'])->name('layaways.items.update');
            Route::post('/layaways/{layaway}/complete', [LayawayController::class, 'complete'])->name('layaways.complete');
            Route::post('/layaways/{layaway}/cancel', [LayawayController::class, 'cancel'])->name('layaways.cancel');
            Route::get('/layaways/{layaway}/receipt', [LayawayController::class, 'receipt'])->name('layaways.receipt');
            Route::get('/layaways/{layaway}/receipt/print', [LayawayController::class, 'printReceipt'])->name('layaways.receipt.print');
            Route::post('/layaways/{layaway}/receipt/send', [LayawayController::class, 'sendReceipt'])->name('layaways.receipt.send');
            Route::apiResource('layaways', LayawayController::class)->only(['index', 'show', 'store']);
        });

        // 'services' (productos de servicio + ordenes de servicio, sin
        // calendario) y 'scheduling' (Agenda/citas) son features
        // independientes en el negocio - un tecnico a domicilio puede tener
        // el primero sin el segundo, y viceversa un salon con agenda pero
        // sin catalogo de servicios. Antes compartian un solo flag
        // ('services') para ambos, asi que un negocio sin agenda igual veia
        // el modulo habilitado.
        Route::middleware(['feature:services', 'permission:appointments.manage'])->group(function () {
            Route::get('/service-workflow', [BusinessServiceWorkflowController::class, 'show'])->name('service-workflow.show');

            Route::post('/service-orders/{serviceOrder}/pay', [ServiceOrderController::class, 'pay'])->name('service-orders.pay');
            Route::post('/service-orders/{serviceOrder}/cancel', [ServiceOrderController::class, 'cancel'])->name('service-orders.cancel');
            Route::patch('/service-orders/{serviceOrder}/stage', [ServiceOrderController::class, 'setStage'])->name('service-orders.stage.update');
            Route::get('/service-orders/{serviceOrder}/receipt', [ServiceOrderController::class, 'receipt'])->name('service-orders.receipt');
            Route::get('/service-orders/{serviceOrder}/receipt/print', [ServiceOrderController::class, 'printReceipt'])->name('service-orders.receipt.print');
            Route::post('/service-orders/{serviceOrder}/receipt/send', [ServiceOrderController::class, 'sendReceipt'])->name('service-orders.receipt.send');
            // parameters(): el nombre que apiResource() deriva de 'service-orders'
            // (service_order) no coincide con el ServiceOrder $serviceOrder de los
            // metodos del controller - mismo bug ya encontrado en
            // BusinessTableController/OpenTabController.
            Route::apiResource('service-orders', ServiceOrderController::class)
                ->only(['index', 'show', 'store', 'update'])
                ->parameters(['service-orders' => 'serviceOrder']);
        });

        Route::middleware(['feature:scheduling', 'permission:appointments.manage'])->group(function () {
            Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
            Route::put('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status.update');
            Route::apiResource('appointments', AppointmentController::class)->only(['index', 'show', 'store', 'update']);
        });

        Route::prefix('superadmin')->name('superadmin.')->middleware('superadmin')->group(function () {
            require __DIR__.'/superadmin.php';
        });

        Route::middleware(['feature:managerial_accounting', 'permission:accounting.manage'])->prefix('accounting')->name('accounting.')->group(function () {
            Route::get('/monthly', [AccountingController::class, 'monthly'])->name('monthly');
            Route::get('/monthly/export', [AccountingController::class, 'exportMonthly'])->name('monthly.export');
            Route::get('/annual', [AccountingController::class, 'annual'])->name('annual');
            Route::get('/closings', [AccountingController::class, 'closings'])->name('closings');
            Route::post('/close-month', [AccountingController::class, 'closeMonth'])->name('close-month');
        });

        Route::middleware('feature:cash_closing')->group(function () {
            Route::middleware('permission:cash_shift.manage')->group(function () {
                Route::get('/cash-shifts/current', [CashShiftController::class, 'current'])->name('cash-shifts.current');
                Route::post('/cash-shifts/{cashShift}/close', [CashShiftController::class, 'close'])->name('cash-shifts.close');
                // parameters(): mismo bug de siempre - 'cash-shifts' derivaria el
                // parametro 'cash_shift', que no coincide con CashShift $cashShift.
                Route::apiResource('cash-shifts', CashShiftController::class)
                    ->only(['index', 'store', 'update', 'destroy'])
                    ->parameters(['cash-shifts' => 'cashShift']);
            });

            Route::middleware('permission:cash_closing.manage')->group(function () {
                Route::get('/cash-closings/preview', [CashClosingController::class, 'preview'])->name('cash-closings.preview');
                Route::apiResource('cash-closings', CashClosingController::class)
                    ->only(['index', 'show', 'store', 'update'])
                    ->parameters(['cash-closings' => 'cashClosing']);
            });

            Route::middleware('permission:cash_closing.undo')->group(function () {
                Route::post('/cash-closings/{cashClosing}/undo', [CashClosingController::class, 'undo'])->name('cash-closings.undo');
            });
        });
    });
});
