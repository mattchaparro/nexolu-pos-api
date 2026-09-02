<?php

use App\Http\Controllers\Api\V1\SuperAdmin\AiUsageController;
use App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController;
use App\Http\Controllers\Api\V1\SuperAdmin\AuditLogController;
use App\Http\Controllers\Api\V1\SuperAdmin\BusinessDataController;
use App\Http\Controllers\Api\V1\SuperAdmin\BusinessesController;
use App\Http\Controllers\Api\V1\SuperAdmin\CommunicationController;
use App\Http\Controllers\Api\V1\SuperAdmin\CronJobController;
use App\Http\Controllers\Api\V1\SuperAdmin\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\EmailController;
use App\Http\Controllers\Api\V1\SuperAdmin\FeatureCatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\FinanceController;
use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Http\Controllers\Api\V1\SuperAdmin\PosPaymentMethodController;
use App\Http\Controllers\Api\V1\SuperAdmin\ServiceWorkflowController;
use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionTransactionController;
use App\Http\Controllers\Api\V1\SuperAdmin\SupportGuideController;
use App\Http\Controllers\Api\V1\SuperAdmin\SupportTicketController;
use App\Http\Controllers\Api\V1\SuperAdmin\UserController;
use App\Http\Controllers\Api\V1\SuperAdmin\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de SuperAdmin
|--------------------------------------------------------------------------
|
| Incluidas desde routes/api.php dentro de un grupo auth:sanctum+superadmin
| ya montado en /api/v1/superadmin. Archivo aparte (igual que el legacy)
| porque el volumen de rutas de este panel ensuciaria api.php.
*/

Route::post('/businesses/{business}/activate', [BusinessesController::class, 'activate'])->name('businesses.activate');
Route::patch('/businesses/{business}/extend-trial', [BusinessesController::class, 'extendTrial'])->name('businesses.extend-trial');
Route::patch('/businesses/{business}/custom-price', [BusinessesController::class, 'setCustomPrice'])->name('businesses.custom-price');
Route::patch('/businesses/{business}/plan', [BusinessesController::class, 'changePlan'])->name('businesses.plan');
Route::patch('/businesses/{business}/config', [BusinessesController::class, 'updateConfig'])->name('businesses.config.update');
Route::patch('/businesses/{business}/toggle', [BusinessesController::class, 'toggle'])->name('businesses.toggle');
Route::patch('/businesses/{business}/ai-chat-block', [BusinessesController::class, 'toggleAiChatBlock'])->name('businesses.ai-chat-block.toggle');
Route::get('/businesses/{business}/subscription-payments', [BusinessesController::class, 'subscriptionPayments'])->name('businesses.subscription-payments.index');
Route::post('/businesses/{business}/subscription-payments', [BusinessesController::class, 'storeSubscriptionPayment'])->name('businesses.subscription-payments.store');
Route::delete('/businesses/{business}/subscription-payments/{payment}', [BusinessesController::class, 'destroySubscriptionPayment'])->name('businesses.subscription-payments.destroy');
Route::apiResource('businesses', BusinessesController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

// Datos operativos de un negocio puntual (soporte cruzando tenants a proposito).
Route::get('/businesses/{business}/products', [BusinessDataController::class, 'products'])->name('businesses.products.index');
Route::post('/businesses/{business}/products', [BusinessDataController::class, 'storeProduct'])->name('businesses.products.store');
Route::put('/businesses/{business}/products/{product}', [BusinessDataController::class, 'updateProduct'])->name('businesses.products.update');
Route::delete('/businesses/{business}/products/{product}', [BusinessDataController::class, 'destroyProduct'])->name('businesses.products.destroy');
Route::get('/businesses/{business}/categories', [BusinessDataController::class, 'categories'])->name('businesses.categories.index');
Route::post('/businesses/{business}/categories', [BusinessDataController::class, 'storeCategory'])->name('businesses.categories.store');
Route::delete('/businesses/{business}/categories/{category}', [BusinessDataController::class, 'destroyCategory'])->name('businesses.categories.destroy');

// Usuarios de cualquier negocio - filtrable por business_id para servir
// tambien la pestana "Usuarios" de un negocio puntual (sin duplicar rutas).
Route::patch('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
Route::apiResource('users', UserController::class)->only(['index', 'store']);

Route::post('/impersonate/{user}', [ImpersonateController::class, 'start'])->name('impersonate.start');

Route::apiResource('announcements', AnnouncementController::class)->only(['index', 'store', 'update', 'destroy']);

// Sin destroy - ver la nota en PosPaymentMethodController.
Route::apiResource('pos-payment-methods', PosPaymentMethodController::class)
    ->only(['index', 'store', 'update'])
    ->parameters(['pos-payment-methods' => 'posPaymentMethod']);

// parameters(): mismo bug de siempre - 'service-workflows' derivaria
// 'service_workflow', que no coincide con ServiceWorkflow $serviceWorkflow.
Route::post('/service-workflows/{serviceWorkflow}/stages', [ServiceWorkflowController::class, 'storeStage'])->name('service-workflows.stages.store');
Route::patch('/service-workflows/{serviceWorkflow}/stages/reorder', [ServiceWorkflowController::class, 'reorderStages'])->name('service-workflows.stages.reorder');
Route::put('/service-workflows/{serviceWorkflow}/stages/{stage}', [ServiceWorkflowController::class, 'updateStage'])->name('service-workflows.stages.update');
Route::delete('/service-workflows/{serviceWorkflow}/stages/{stage}', [ServiceWorkflowController::class, 'destroyStage'])->name('service-workflows.stages.destroy');
Route::post('/service-workflows/{serviceWorkflow}/businesses/{business}', [ServiceWorkflowController::class, 'assignBusiness'])->name('service-workflows.businesses.assign');
Route::delete('/service-workflows/{serviceWorkflow}/businesses/{business}', [ServiceWorkflowController::class, 'unassignBusiness'])->name('service-workflows.businesses.unassign');
Route::apiResource('service-workflows', ServiceWorkflowController::class)
    ->only(['index', 'show', 'store', 'update', 'destroy'])
    ->parameters(['service-workflows' => 'serviceWorkflow']);

Route::get('/subscription-transactions', [SubscriptionTransactionController::class, 'index'])->name('subscription-transactions.index');

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');

Route::get('/communications', [CommunicationController::class, 'index'])->name('communications.index');
Route::post('/businesses/{business}/communications', [CommunicationController::class, 'send'])->name('businesses.communications.send');

Route::get('/whatsapp-templates', [WhatsAppTemplateController::class, 'index'])->name('whatsapp-templates.index');

Route::get('/feature-catalog', [FeatureCatalogController::class, 'index'])->name('feature-catalog.index');

// Asistente de IA: uso, costo y preguntas que no supo responder.
Route::get('/ai/usage', [AiUsageController::class, 'index'])->name('ai.usage.index');
Route::patch('/ai/unanswered/{question}/reviewed', [AiUsageController::class, 'markQuestionReviewed'])->name('ai.unanswered.reviewed');

Route::get('/emails/logs', [EmailController::class, 'logs'])->name('emails.logs');
Route::get('/emails/templates', [EmailController::class, 'templates'])->name('emails.templates.index');
Route::put('/emails/templates/{type}', [EmailController::class, 'updateTemplate'])->name('emails.templates.update');

Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

Route::get('/support-tickets', [SupportTicketController::class, 'index'])->name('support-tickets.index');
Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support-tickets.show');
Route::patch('/support-tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus'])->name('support-tickets.status.update');

Route::get('/cron-jobs', [CronJobController::class, 'index'])->name('cron-jobs.index');
Route::patch('/cron-jobs/{key}/toggle', [CronJobController::class, 'toggle'])->name('cron-jobs.toggle');
Route::post('/cron-jobs/{key}/run-now', [CronJobController::class, 'runNow'])->name('cron-jobs.run-now');

Route::get('/support-guides', [SupportGuideController::class, 'index'])->name('support-guides.index');
Route::post('/support-guides/categories', [SupportGuideController::class, 'storeCategory'])->name('support-guides.categories.store');
Route::put('/support-guides/categories/{category}', [SupportGuideController::class, 'updateCategory'])->name('support-guides.categories.update');
Route::delete('/support-guides/categories/{category}', [SupportGuideController::class, 'destroyCategory'])->name('support-guides.categories.destroy');
Route::post('/support-guides/articles', [SupportGuideController::class, 'storeArticle'])->name('support-guides.articles.store');
Route::put('/support-guides/articles/{article}', [SupportGuideController::class, 'updateArticle'])->name('support-guides.articles.update');
Route::delete('/support-guides/articles/{article}', [SupportGuideController::class, 'destroyArticle'])->name('support-guides.articles.destroy');
