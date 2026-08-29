<?php

use App\Http\Controllers\Api\AiToolCatalogController;
use App\Http\Controllers\Api\AiToolInvokeController;
use App\Http\Controllers\Api\BusinessMigrationPatchController;
use App\Http\Controllers\Api\NexoluCommsWebhookController;
use App\Http\Controllers\Api\NotificationSnoozeController;
use App\Http\Controllers\Api\PaymentsCoreWebhookController;
use App\Http\Controllers\Api\PublicReceiptController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AiChannelLinkController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AiDraftController;
use App\Http\Controllers\Api\V1\AiInsightController;
use App\Http\Controllers\Api\V1\AiMessagePackController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingProfileController;
use App\Http\Controllers\Api\V1\BulkStockUpdateController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessOverviewController;
use App\Http\Controllers\Api\V1\BusinessPaymentSourceController;
use App\Http\Controllers\Api\V1\BusinessServiceWorkflowController;
use App\Http\Controllers\Api\V1\BusinessStoreSettingsController;
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
use App\Http\Controllers\Api\V1\InventoryReportController;
use App\Http\Controllers\Api\V1\KitchenBoardController;
use App\Http\Controllers\Api\V1\LayawayController;
use App\Http\Controllers\Api\V1\OpenTabController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentMethodsController;
use App\Http\Controllers\Api\V1\PlanCatalogController;
use App\Http\Controllers\Api\V1\PosPaymentMethodController;
use App\Http\Controllers\Api\V1\ProductAttributeController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReceivableController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockMovementReasonController;
use App\Http\Controllers\Api\V1\Storefront\StorefrontCatalogController;
use App\Http\Controllers\Api\V1\Storefront\StorefrontOrderController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierReportController;
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

// Server-to-server: lo llama el superadmin de pos-saas (legacy) despues de
// migrar un negocio - ver BusinessMigrationPatchController. Resuelve por
// slug, no por id: BusinessDataExporter remapea los ids al exportar (nunca
// preserva el original, ver su docblock), asi que el id que pos-saas
// conoce del negocio no es el id de este mismo negocio aca - slug si viaja
// intacto (columna copiada tal cual, UNIQUE en ambos schemas).
Route::post('/admin/businesses/{business:slug}/run-migration-patches', [BusinessMigrationPatchController::class, 'run'])
    ->middleware('legacy.admin-key')
    ->name('admin.businesses.run-migration-patches');

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

// Catalogo publico de la tienda online. SIN auth: quien llega es un
// comprador anonimo. El aislamiento entre negocios lo garantiza
// ResolveStorefrontTenant + el TenantContext, no cada consulta por separado
// (ver StorefrontCatalogController). `throttle` porque es superficie abierta
// a internet: sin el, cualquiera puede barrer el catalogo de todos los
// negocios a la velocidad que quiera.
Route::prefix('v1/storefront/{business}')
    ->name('api.v1.storefront.')
    ->middleware(['storefront.tenant', 'throttle:120,1'])
    ->group(function () {
        Route::get('/', [StorefrontCatalogController::class, 'settings'])->name('settings');
        Route::get('/categories', [StorefrontCatalogController::class, 'categories'])->name('categories');
        Route::get('/products', [StorefrontCatalogController::class, 'products'])->name('products.index');
        // `{productId}` y no `{product}`: con ese nombre Laravel intentaria el
        // binding implicito del modelo Product, que ignoraria el filtro de
        // publicacion y devolveria productos que no estan en la tienda.
        // Checkout y seguimiento. `throttle` mas estricto que el catalogo:
        // crear pedidos es escritura desde internet abierto.
        Route::post('/orders', [StorefrontOrderController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('orders.store');
        Route::get('/orders/{token}', [StorefrontOrderController::class, 'show'])->name('orders.show');
        Route::get('/products/{productId}', [StorefrontCatalogController::class, 'product'])
            ->whereNumber('productId')
            ->name('products.show');
    });

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/plans', [PlanCatalogController::class, 'index'])->name('plans.index');
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    Route::middleware(['auth:sanctum', 'sentry.context'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');
        Route::put('/me/password', [AuthController::class, 'updatePassword'])->name('me.password.update');

        Route::post('/ai/chat', [AiChatController::class, 'send'])->name('ai.chat');
        Route::post('/ai/drafts/{draftId}/confirm', [AiDraftController::class, 'confirm'])->name('ai.drafts.confirm');
        Route::post('/ai/drafts/{draftId}/discard', [AiDraftController::class, 'discard'])->name('ai.drafts.discard');
        Route::get('/insights', [AiInsightController::class, 'index'])->name('insights.index');
        Route::post('/insights/{type}/refresh', [AiInsightController::class, 'refresh'])->name('insights.refresh');

        Route::middleware('permission:ai_chat.use')->prefix('ai/channels/whatsapp')->name('ai.channels.whatsapp.')->group(function () {
            Route::get('/status', [AiChannelLinkController::class, 'status'])->name('status');
            Route::post('/start', [AiChannelLinkController::class, 'start'])->middleware('throttle:5,1')->name('start');
            Route::post('/confirm', [AiChannelLinkController::class, 'confirm'])->middleware('throttle:10,1')->name('confirm');
            Route::delete('/', [AiChannelLinkController::class, 'unlink'])->name('unlink');
        });

        // business.show queda fuera de business-admin a proposito: cualquier
        // empleado autenticado lo necesita para resolver feature flags
        // (hasFeature()) en todo el frontend, no solo Ajustes.
        Route::get('/business', [BusinessController::class, 'show'])->name('business.show');

        // Configuracion del negocio, facturacion y medios de pago aceptados
        // no son delegables via el picker de permisos de un empleado (ver
        // EnsureBusinessAdmin) - un empleado con CUALQUIER combinacion de
        // permisos del catalogo nunca puede tocar esto, solo el admin.
        Route::middleware('business-admin')->group(function () {
            Route::put('/business', [BusinessController::class, 'update'])->name('business.update');
            Route::get('/business/billing-profile', [BillingProfileController::class, 'show'])->name('business.billing-profile.show');
            Route::put('/business/billing-profile', [BillingProfileController::class, 'update'])->name('business.billing-profile.update');
            Route::put('/business/notifications', [BusinessController::class, 'updateNotifications'])->name('business.notifications.update');
            Route::delete('/business/low-stock-snooze', [BusinessController::class, 'clearLowStockSnooze'])->name('business.low-stock-snooze.clear');
            Route::get('/business/payment-methods', [PosPaymentMethodController::class, 'index'])->name('business.payment-methods.index');
            Route::put('/business/payment-methods', [PosPaymentMethodController::class, 'update'])->name('business.payment-methods.update');
        });

        // A diferencia de ver/editar los medios de pago aceptados, pedir
        // soporte para uno que falta es solo un mensaje al equipo - no
        // cambia nada del negocio, cualquier empleado autenticado puede
        // mandarlo (ver test_any_business_user_can_request_support_for_a_
        // missing_payment_method).
        Route::post('/business/payment-methods/support-request', [PosPaymentMethodController::class, 'requestSupport'])
            ->middleware('throttle:5,1')
            ->name('business.payment-methods.support-request');

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
        Route::put('/dashboard/shortcuts', [DashboardController::class, 'updateShortcuts'])->name('dashboard.shortcuts.update');
        Route::get('/dashboard/whatsapp-onboarding', [DashboardController::class, 'whatsappOnboarding'])->name('dashboard.whatsapp-onboarding');
        Route::post('/dashboard/whatsapp-onboarding/dismiss', [DashboardController::class, 'dismissWhatsappOnboarding'])->name('dashboard.whatsapp-onboarding.dismiss');

        // Suscripcion/facturacion del negocio: mismo criterio de
        // business-admin que arriba - mover dinero o ver el estado del plan
        // no es algo que un empleado deba poder hacer.
        Route::middleware('business-admin')->group(function () {
            Route::get('/subscription/status', [SubscriptionController::class, 'status'])->name('subscription.status');
            Route::post('/subscription/checkout', [SubscriptionController::class, 'initiate'])->name('subscription.checkout');
            Route::get('/subscription/checkout/{reference}', [SubscriptionController::class, 'checkoutStatus'])->name('subscription.checkout.status');
            Route::post('/subscription/checkout/{reference}/charge', [SubscriptionController::class, 'charge'])->name('subscription.checkout.charge');
        });

        // Catalogo de metodos de pago (API directa via Payments Core) -
        // ver docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 5.4. No es
        // /business/payment-methods (eso es el catalogo de etiquetas
        // manuales del POS de venta en tienda, PosPaymentMethodController).
        Route::get('/payment-methods', [PaymentMethodsController::class, 'index'])->name('payment-methods.index');
        Route::get('/pse/financial-institutions', [PaymentMethodsController::class, 'pseFinancialInstitutions'])->name('pse.financial-institutions');

        // "Metodos de pago guardados" del negocio (tarjeta/Nequi tokenizados
        // para reuso, Fuentes de Pago) - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md
        // seccion 9. Distinto de /payment-methods (catalogo, sin estado).
        Route::get('/payment-sources', [BusinessPaymentSourceController::class, 'index'])->name('payment-sources.index');
        Route::post('/payment-sources', [BusinessPaymentSourceController::class, 'store'])->name('payment-sources.store');
        Route::delete('/payment-sources/{paymentSource}', [BusinessPaymentSourceController::class, 'destroy'])->name('payment-sources.destroy');

        Route::get('/ai/message-packs/state', [AiMessagePackController::class, 'state'])->name('ai.message-packs.state');
        Route::post('/ai/message-packs/checkout', [AiMessagePackController::class, 'initiate'])->name('ai.message-packs.checkout');
        Route::get('/ai/message-packs/checkout/{reference}', [AiMessagePackController::class, 'checkoutStatus'])->name('ai.message-packs.checkout.status');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings/{setting}', [SettingController::class, 'update'])->name('settings.update');

        Route::middleware(['feature:audit_logs', 'permission:audit_logs.view'])->prefix('audit-logs')->name('audit-logs.')->group(function () {
            Route::get('/', [AuditLogController::class, 'index'])->name('index');
            Route::get('/actions', [AuditLogController::class, 'actions'])->name('actions');
            Route::get('/export', [AuditLogController::class, 'export'])->name('export');
        });

        Route::apiResource('support-tickets', SupportTicketController::class)->only(['index', 'store']);

        // Empleados y admins del propio negocio - la autorizacion (rol admin
        // + business_id) vive en cada Request para que 'index' liste pero no
        // exponga acciones a un employee, en vez de bloquearlo todo con
        // middleware.
        Route::middleware('feature:permissions_management')->group(function () {
            Route::get('/employees/permission-catalog', [EmployeeController::class, 'catalog'])->name('employees.catalog');
            Route::put('/employees/{employee}/permissions', [EmployeeController::class, 'updatePermissions'])->name('employees.permissions.update');
        });
        Route::patch('/employees/{employee}/toggle', [EmployeeController::class, 'toggle'])->name('employees.toggle');
        Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);

        // Catalogo de productos/categorias para VENDER: sin permission, igual
        // que crear una venta (POST /sales) - cualquier empleado que puede
        // vender necesita poder ver que vender, sin que el admin tenga que
        // otorgarle "Ver existencias" (que es sobre reportes/stock, no sobre
        // el catalogo de venta). ProductResource oculta cost_price a quien
        // no tenga inventory.view, asi que abrir esto no filtra margenes.
        Route::apiResource('product-categories', ProductCategoryController::class)->only(['index', 'show']);
        Route::get('/products/sellable', [ProductController::class, 'sellable'])->name('products.sellable');

        // inventory.view para leer el catalogo/reportes de inventario,
        // inventory.add para tocarlo - ver PermissionCatalog. apiResource se
        // parte en dos registros con ->only() distinto en vez de uno solo,
        // para poder colgar cada mitad de un middleware de permiso diferente.
        Route::middleware('permission:inventory.view')->group(function () {
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
            Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
            Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy']);
        });

        // Configuracion de la tienda online, del lado del comerciante.
        // business-admin y no un permiso de inventario: abrir o cerrar la
        // tienda al publico es una decision del dueño, no del cajero.
        Route::middleware(['feature:online_store', 'business-admin'])->group(function () {
            Route::get('/store-settings', [BusinessStoreSettingsController::class, 'show'])->name('store-settings.show');
            Route::put('/store-settings', [BusinessStoreSettingsController::class, 'update'])->name('store-settings.update');
            // Ranuras fijas (logo, banner, hero, story), no una galeria: cada
            // imagen tiene su sitio en la tienda y reemplaza a la anterior.
            Route::post('/store-settings/images/{slot}', [BusinessStoreSettingsController::class, 'uploadImage'])
                ->whereIn('slot', ['logo', 'banner', 'hero', 'story'])
                ->name('store-settings.images.store');
            Route::delete('/store-settings/images/{slot}', [BusinessStoreSettingsController::class, 'deleteImage'])
                ->whereIn('slot', ['logo', 'banner', 'hero', 'story'])
                ->name('store-settings.images.destroy');
        });

        // Bandeja de pedidos online. Bajo el mismo modulo que la tienda: sin
        // tienda no hay pedidos que atender.
        Route::middleware('feature:online_store')->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/pending-count', [OrderController::class, 'pendingCount'])->name('orders.pending-count');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
            Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])
                ->whereNumber('order')
                ->name('orders.status');
        });

        // Fotos de producto. Atadas a la tienda online a proposito: son el
        // unico dato del catalogo que existe para publicarse hacia afuera, y
        // atarlas al modulo evita que un negocio que nunca va a vender por
        // internet consuma almacenamiento. Un flag apagado esconde el modulo
        // entero, igual que ingredients o variants.
        //
        // Se suben de a una (multipart) mientras se llena el formulario, no
        // anidadas en el payload del producto.
        Route::middleware('feature:online_store')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                Route::get('/products/{product}/images', [ProductImageController::class, 'index'])->name('products.images.index');
            });
            Route::middleware('permission:inventory.add')->group(function () {
                Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
                Route::put('/products/{product}/images/order', [ProductImageController::class, 'reorder'])->name('products.images.reorder');
                Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update'])->name('products.images.update');
                Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
            });
        });
        Route::middleware('permission:inventory.adjust')->group(function () {
            Route::post('/products/bulk-update', [BulkStockUpdateController::class, 'store'])->name('products.bulk-update');
        });

        Route::middleware('feature:clients')->group(function () {
            // Busqueda liviana + alta rapida: cualquiera que pueda vender,
            // agendar o apartar necesita poder buscar/crear un cliente en el
            // momento (ver ClientMatchField.vue en el front), sin el permiso
            // clients.manage - ese es solo para el directorio completo
            // (listar/editar/borrar), no para esto. Mismo criterio que
            // Kitchen Board: no es una accion administrativa sensible.
            Route::get('/clients/search', [ClientController::class, 'search'])->name('clients.search');
            Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');

            Route::middleware('permission:clients.manage')->group(function () {
                Route::apiResource('clients', ClientController::class)->except(['store']);
            });
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

        Route::middleware('feature:variants')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                Route::apiResource('product-attributes', ProductAttributeController::class)->only(['index', 'show']);
            });
            Route::middleware('permission:inventory.add')->group(function () {
                Route::apiResource('product-attributes', ProductAttributeController::class)->only(['store', 'update', 'destroy']);
                // Pausar/activar una variante suelta desde el listado del
                // catalogo, sin reenviar el producto entero - ver el docblock
                // de ProductVariantController.
                Route::patch('/products/{product}/variants/{variant}/toggle', [ProductVariantController::class, 'toggle'])
                    ->name('products.variants.toggle');
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

        Route::middleware('feature:discounts')->group(function () {
            // El POS (Vender) necesita listar los descuentos activos para el
            // selector de carrito/item - un empleado con discounts.apply
            // (aplicar descuentos en una venta) pero sin discounts.manage
            // (crear/editar descuentos) debe poder verlos igual, o nunca
            // podria usar el permiso que sí tiene.
            Route::middleware('permission:discounts.apply,discounts.manage')->group(function () {
                Route::apiResource('discounts', DiscountController::class)->only(['index', 'show']);
            });
            Route::middleware('permission:discounts.manage')->group(function () {
                Route::apiResource('discounts', DiscountController::class)->only(['store', 'update', 'destroy']);
            });
        });

        Route::middleware('permission:sales.reverse')->group(function () {
            Route::post('/sales/{sale}/reverse', [SaleController::class, 'reverse'])->name('sales.reverse');
        });
        Route::get('/sales/{sale}/receipt/print', [SaleController::class, 'printReceipt'])->name('sales.receipt.print');
        Route::middleware('feature:cash_receipts_pdf')->group(function () {
            Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
            Route::post('/sales/{sale}/receipt/send', [SaleController::class, 'sendReceipt'])->name('sales.receipt.send');
        });
        Route::apiResource('sales', SaleController::class)->only(['index', 'show']);
        Route::middleware('cash-shift.required-for-sales')->group(function () {
            Route::apiResource('sales', SaleController::class)->only(['store']);
        });

        Route::middleware('feature:open_tabs')->group(function () {
            Route::apiResource('tables', BusinessTableController::class);

            // parameters(): fuerza el binding a {sale} en vez del {open_tab}
            // que generaria el nombre del recurso - el resto de metodos del
            // controller (addItems, close, etc.) ya reciben Sale $sale, y un
            // nombre de parametro distinto rompe el binding implicito (el
            // mismo bug que ya encontramos y arreglamos en BusinessTableController).
            Route::apiResource('open-tabs', OpenTabController::class)
                ->only(['index', 'show', 'store'])
                ->parameters(['open-tabs' => 'sale']);
            Route::post('/open-tabs/{sale}/items', [OpenTabController::class, 'addItems'])->name('open-tabs.items.add');
            Route::put('/open-tabs/{sale}/items', [OpenTabController::class, 'syncItems'])->name('open-tabs.items.sync');
            Route::post('/open-tabs/{sale}/partial-payments', [OpenTabController::class, 'recordPartialPayment'])->name('open-tabs.partial-payments.store');
            Route::post('/open-tabs/{sale}/close', [OpenTabController::class, 'close'])->name('open-tabs.close');

            // Cancelar una cuenta abierta revierte stock igual que anular una
            // venta cerrada - misma gate que sales.reverse, no una nueva.
            Route::middleware('permission:sales.reverse')->group(function () {
                Route::apiResource('open-tabs', OpenTabController::class)
                    ->only(['destroy'])
                    ->parameters(['open-tabs' => 'sale']);
            });
        });

        // Sin permission: middleware a proposito, igual que legacy (comandera
        // accesible por igual a admin y a cualquier empleado con el feature
        // habilitado - no es una accion administrativa sensible).
        Route::middleware('feature:kitchen_board')->prefix('kitchen')->name('kitchen.')->group(function () {
            Route::get('/tickets', [KitchenBoardController::class, 'index'])->name('tickets.index');
            Route::post('/tickets/{sale}/status', [KitchenBoardController::class, 'updateStatus'])->name('tickets.status.update');
        });

        Route::middleware(['feature:receivables', 'permission:receivables.manage'])->group(function () {
            // Antes del apiResource, mismo motivo que /products/summary.
            Route::get('/receivables/summary', [ReceivableController::class, 'summary'])->name('receivables.summary');
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
            Route::get('/layaways/{layaway}/receipt/print', [LayawayController::class, 'printReceipt'])->name('layaways.receipt.print');
            Route::middleware('feature:cash_receipts_pdf')->group(function () {
                Route::get('/layaways/{layaway}/receipt', [LayawayController::class, 'receipt'])->name('layaways.receipt');
                Route::post('/layaways/{layaway}/receipt/send', [LayawayController::class, 'sendReceipt'])->name('layaways.receipt.send');
            });
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

            // Antes del apiResource, mismo motivo que /products/summary.
            Route::get('/service-orders/summary', [ServiceOrderController::class, 'summary'])->name('service-orders.summary');
            Route::post('/service-orders/{serviceOrder}/pay', [ServiceOrderController::class, 'pay'])->name('service-orders.pay');
            Route::post('/service-orders/{serviceOrder}/cancel', [ServiceOrderController::class, 'cancel'])->name('service-orders.cancel');
            Route::patch('/service-orders/{serviceOrder}/stage', [ServiceOrderController::class, 'setStage'])->name('service-orders.stage.update');
            Route::get('/service-orders/{serviceOrder}/receipt/print', [ServiceOrderController::class, 'printReceipt'])->name('service-orders.receipt.print');
            Route::middleware('feature:cash_receipts_pdf')->group(function () {
                Route::get('/service-orders/{serviceOrder}/receipt', [ServiceOrderController::class, 'receipt'])->name('service-orders.receipt');
                Route::post('/service-orders/{serviceOrder}/receipt/send', [ServiceOrderController::class, 'sendReceipt'])->name('service-orders.receipt.send');
            });
            // parameters(): el nombre que apiResource() deriva de 'service-orders'
            // (service_order) no coincide con el ServiceOrder $serviceOrder de los
            // metodos del controller - mismo bug ya encontrado en
            // BusinessTableController/OpenTabController.
            Route::apiResource('service-orders', ServiceOrderController::class)
                ->only(['index', 'show', 'store', 'update', 'destroy'])
                ->parameters(['service-orders' => 'serviceOrder']);
        });

        Route::middleware(['feature:scheduling', 'permission:appointments.manage'])->group(function () {
            Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
            Route::put('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status.update');
            Route::apiResource('appointments', AppointmentController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        });

        // Reportes de ventas - un permiso distinto por reporte (antes uno
        // solo, reports.sales, cubria los 4 - ver la nota en PermissionCatalog).
        // El reporte de cierres de caja ademas requiere feature:cash_closing +
        // permission:cash_closing.manage (mismo criterio que las rutas de
        // cash-closings de gestion).
        Route::middleware('permission:reports.daily_summary')->prefix('reports/sales')->name('reports.sales.')->group(function () {
            Route::get('/daily', [SalesReportController::class, 'daily'])->name('daily');
        });
        Route::middleware('permission:reports.business_overview')->prefix('reports/sales')->name('reports.sales.')->group(function () {
            Route::get('/business-overview', [BusinessOverviewController::class, 'index'])->name('business-overview');
        });
        Route::middleware('permission:reports.sales')->prefix('reports/sales')->name('reports.sales.')->group(function () {
            Route::get('/history', [SalesReportController::class, 'history'])->name('history');
            Route::get('/history/export', [SalesReportController::class, 'historyExport'])->name('history.export');
        });
        Route::middleware('permission:reports.sales_by_seller')->prefix('reports/sales')->name('reports.sales.')->group(function () {
            Route::get('/by-seller', [SalesReportController::class, 'bySeller'])->name('by-seller');
            Route::get('/by-seller/export', [SalesReportController::class, 'bySellerExport'])->name('by-seller.export');
        });
        Route::middleware(['feature:cash_closing', 'permission:cash_closing.manage'])->prefix('reports')->name('reports.')->group(function () {
            Route::get('/cash-closings', [SalesReportController::class, 'cashClosings'])->name('cash-closings');
            Route::get('/cash-closings/export', [SalesReportController::class, 'cashClosingsExport'])->name('cash-closings.export');
        });

        // Reportes de inventario - el gate OR (inventory_advanced || ingredients)
        // lo aplica el controlador porque el middleware solo soporta una feature.
        Route::middleware('permission:reports.inventory')->prefix('reports/inventory')->name('reports.inventory.')->group(function () {
            Route::get('/summary', [InventoryReportController::class, 'summary'])->name('summary');
            Route::get('/movements', [InventoryReportController::class, 'movements'])->name('movements');
            Route::get('/movements/export', [InventoryReportController::class, 'movementsExport'])->name('movements.export');
            Route::get('/margins', [InventoryReportController::class, 'margins'])->name('margins');
            Route::get('/margins/export', [InventoryReportController::class, 'marginsExport'])->name('margins.export');
        });

        // Reportes de proveedores - misma gate que la gestion de compras.
        Route::middleware(['can-access-purchases', 'permission:purchases.manage'])->prefix('reports')->name('reports.')->group(function () {
            Route::get('/suppliers', [SupplierReportController::class, 'index'])->name('suppliers');
            Route::get('/suppliers/export', [SupplierReportController::class, 'export'])->name('suppliers.export');
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
                    ->only(['index', 'store'])
                    ->parameters(['cash-shifts' => 'cashShift']);
            });

            // update() (correccion administrativa de un turno ya guardado,
            // incluso de otro empleado) y destroy() no son "abrir/cerrar mi
            // propio turno" - necesitan su propio permiso, no cash_shift.manage.
            Route::middleware('permission:cash_shift.correct')->group(function () {
                Route::apiResource('cash-shifts', CashShiftController::class)
                    ->only(['update', 'destroy'])
                    ->parameters(['cash-shifts' => 'cashShift']);
            });

            Route::middleware('permission:cash_closing.manage')->group(function () {
                Route::get('/cash-closings/preview', [CashClosingController::class, 'preview'])->name('cash-closings.preview');
                Route::get('/cash-closings/pending-dates', [CashClosingController::class, 'pendingDates'])->name('cash-closings.pending-dates');
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
