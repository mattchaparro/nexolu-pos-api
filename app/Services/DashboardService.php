<?php

namespace App\Services;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Expense;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Models\User;
use App\Services\WhatsApp\ChannelLinkService;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function __construct(private ChannelLinkService $channelLinks) {}

    /**
     * Resumen del dia para el home de la app - portado 1:1 de la logica de
     * Admin/DashboardController del legacy (ver CONTEXT.md del legacy repo).
     *
     * `today_sales` es lo efectivamente recibido hoy, no solo ventas: suma
     * ventas cerradas (sin fiados ni no-revenue) + fiados cobrados hoy +
     * pagos de servicios de hoy + abonos a apartados de hoy (las "3+1
     * fuentes de ingreso", igual que CashClosingService/SalesReportService -
     * antes esta funcion se quedaba en 3, el mismo BUG que el legacy nunca
     * corrigio en su DashboardController). `open_tabs_total` son las cuentas
     * ABIERTAS HOY (no todas las que siguen abiertas), y `pending_receivables`
     * es el saldo pendiente historico completo, no solo el de hoy.
     *
     * `today_cash`/`today_transfer` reparten ese mismo ingreso por medio de
     * pago (solo efectivo/transferencia, igual que legacy - el resto de
     * metodos no se desglosan) usando RevenueByPaymentMethod::combined()
     * (fuente unica compartida con cierre de caja/turnos y el resumen del
     * dia) + Business::resolveCashPaymentMethodId()/resolveTransferPaymentMethodId()
     * para resolver el id real configurado en vez de asumir literalmente
     * 'cash'/'transfer' (otro BUG heredado del legacy: negocios con
     * id='efectivo' no se contaban en ningun lado).
     *
     * @return array{
     *     today_sales: float,
     *     today_count: int,
     *     today_cash: float,
     *     today_transfer: float,
     *     open_tabs_total: float,
     *     receivables_enabled: bool,
     *     pending_receivables: float,
     *     expenses_enabled: bool,
     *     today_expenses: float,
     * }
     */
    public function todaySummary(Business $business): array
    {
        $today = Carbon::today();

        $revenueSales = Sale::query()
            ->where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereDate('closed_at', $today)
            ->with('paymentSplits')
            ->get();

        $paidReceivablesToday = Receivable::query()
            ->where('business_id', $business->id)
            ->whereDate('paid_at', $today)
            ->get();

        $servicePaymentsToday = ServicePayment::query()
            ->where('business_id', $business->id)
            ->whereDate('created_at', $today)
            ->get();
        $servicePaymentsTotal = (float) $servicePaymentsToday->sum('amount');

        $layawayPaymentsToday = LayawayPayment::query()
            ->where('business_id', $business->id)
            ->whereDate('created_at', $today)
            ->get();
        $layawayPaymentsTotal = (float) $layawayPaymentsToday->sum('amount');

        $breakdown = RevenueByPaymentMethod::combined($business, $revenueSales, $paidReceivablesToday, $servicePaymentsToday, $layawayPaymentsToday);
        $cashId = $business->resolveCashPaymentMethodId();
        $transferId = $business->resolveTransferPaymentMethodId();
        $todayCash = (float) ($breakdown->get($cashId)['total'] ?? 0);
        $todayTransfer = (float) ($breakdown->get($transferId)['total'] ?? 0);

        $openTabsTotal = Sale::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->whereDate('created_at', $today)
            ->sum('total');

        $receivablesEnabled = $business->hasFeature('receivables');
        $pendingReceivables = $receivablesEnabled
            ? Receivable::query()->where('business_id', $business->id)->where('status', 'pending')->sum('balance')
            : 0;

        $expensesEnabled = $business->hasFeature('expenses');
        $todayExpenses = $expensesEnabled
            ? Expense::query()
                ->where('business_id', $business->id)
                ->whereDate('date', $today)
                ->where('scope', 'operacional')
                ->sum('value')
            : 0;

        return [
            'today_sales' => (float) $revenueSales->sum('total') + (float) $paidReceivablesToday->sum('amount') + $servicePaymentsTotal + $layawayPaymentsTotal,
            'today_count' => $revenueSales->count(),
            'today_cash' => $todayCash,
            'today_transfer' => $todayTransfer,
            'open_tabs_total' => (float) $openTabsTotal,
            'receivables_enabled' => $receivablesEnabled,
            'pending_receivables' => (float) $pendingReceivables,
            'expenses_enabled' => $expensesEnabled,
            'today_expenses' => (float) $todayExpenses,
        ];
    }

    /**
     * Card de "vincula tu WhatsApp" del dashboard - null significa que el
     * card no se muestra (el usuario ya lo cerro, no tiene acceso al
     * Asistente, o el negocio lo tiene bloqueado). Puerto reducido de
     * DashboardController::whatsappOnboarding() del legacy: se deja afuera
     * el checklist de "alertas activas" (`notification_preferences`) porque
     * esta API todavia no tiene una pantalla de Ajustes donde configurarlas
     * (ver docs/MIGRATION_BACKLOG.md) - mostrar un check que nadie puede
     * prender confundiria mas de lo que ayuda. El link a Settings tampoco
     * se porta: en su lugar el card ofrece el flujo de vinculo (telefono +
     * codigo) inline, reusando POST /ai/channels/whatsapp/start|confirm que
     * ya existen.
     *
     * @return array{linked: bool}|null
     */
    public function whatsappOnboarding(User $user): ?array
    {
        $business = $user->business;

        if (! $business
            || $user->whatsapp_onboarding_dismissed_at !== null
            || $business->ai_chat_blocked
            || ! $user->can('ai_chat.use')) {
            return null;
        }

        return [
            'linked' => $this->channelLinks->identityFor($user, AiChannelIdentity::CHANNEL_WHATSAPP) !== null,
        ];
    }
}
