<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\Expense;
use App\Models\FinancingCredit;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\ServicePayment;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SalesReportService
{
    /**
     * Resumen financiero detallado de un día (o de un rango, para el modulo
     * "Resumen del dia" - por defecto un solo dia si no se pasa $dateTo).
     * Porto de SaleService::getDailySummary() del legacy, adaptado al patrón
     * de esta API (sin dependencia de la request, recibe objetos de dominio).
     *
     * Incluye `channels`: el ingreso desglosado por canal (ventas, servicios,
     * apartados, fiados cobrados) x medio de pago - no existia ningun reporte
     * asi en el legacy (solo el desglose plano `payment_breakdown`, que ya
     * mezcla los 4 canales).
     *
     * @return array<string, mixed>
     */
    public function dailySummary(Business $business, string $date, ?string $dateTo = null): array
    {
        $businessId = $business->id;
        [$from, $to] = $this->normalizeDateRange($date, $dateTo ?? $date, 92);
        $sameDay = $from->toDateString() === $to->toDateString();

        $eagerLoads = [
            'items.product:id,name',
            'items.options',
            'user:id,name',
            'table:id,name',
            'paymentSplits',
        ];

        $closedSales = Sale::where('business_id', $businessId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$from, $to])
            ->with($eagerLoads)
            ->latest('closed_at')
            ->get();

        $openSales = Sale::where('business_id', $businessId)
            ->where('status', 'open')
            ->whereBetween('created_at', [$from, $to])
            ->with($eagerLoads)
            ->latest()
            ->get();

        $revenueSales = $closedSales
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->values();

        $paidReceivables = Receivable::where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->get();

        $servicePaymentsToday = ServicePayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->with('order:id,service_name,client_id,total,amount_paid,status,created_at', 'order.client:id,name')
            ->get();

        $layawayPaymentsToday = LayawayPayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->with('layaway:id,customer_name,customer_phone,status')
            ->get();

        $financingPayouts = FinancingCredit::where('business_id', $businessId)
            ->where('status', FinancingCredit::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->with('provider:id,name')
            ->latest('paid_at')
            ->get();

        $servicePaymentsTotal = (float) $servicePaymentsToday->sum('amount');
        $layawayPaymentsTotal = (float) $layawayPaymentsToday->sum('amount');
        $financingPayoutsTotal = (float) $financingPayouts->sum('amount');

        $totalSales = RevenueByPaymentMethod::salesIncome($revenueSales)
            + (float) $paidReceivables->sum('amount')
            + $servicePaymentsTotal
            + $layawayPaymentsTotal
            + $financingPayoutsTotal;

        $paymentBreakdown = RevenueByPaymentMethod::combined(
            $business,
            $revenueSales,
            $paidReceivables,
            $servicePaymentsToday,
            $layawayPaymentsToday,
            $financingPayouts,
        );

        $channels = RevenueByPaymentMethod::perChannel(
            $business,
            $revenueSales,
            $paidReceivables,
            $servicePaymentsToday,
            $layawayPaymentsToday,
            $financingPayouts,
        );

        $cashId = $business->resolveCashPaymentMethodId();
        $totalCash = (float) ($paymentBreakdown->firstWhere('id', $cashId)['total'] ?? 0);
        $totalTransfer = (float) $paymentBreakdown
            ->filter(fn ($m) => $m['id'] !== $cashId && $m['id'] !== 'credit')
            ->sum('total');

        $topProducts = SaleItem::whereHas('sale', fn ($q) => $q
            ->where('business_id', $businessId)
            ->whereBetween('closed_at', [$from, $to])
            ->where('status', 'closed')
        )
            // is_single_sale: sin rotacion real que reportar - mismo criterio
            // que BusinessOverviewService::productRotation().
            ->whereHas('product', fn ($q) => $q->where('is_single_sale', false))
            ->selectRaw('product_id, SUM(quantity) as total_quantity, SUM(subtotal - COALESCE(discount_amount, 0)) as total_revenue')
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->with('product:id,name')
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->product?->name ?? 'Producto eliminado',
                'total_quantity' => (float) $r->total_quantity,
                'total_revenue' => round((float) $r->total_revenue, 2),
            ]);

        $mapSale = fn ($s) => [
            'id' => $s->id,
            'invoice_number' => $s->invoice_number,
            'total' => (float) $s->total,
            'payment_method' => $s->payment_method,
            'payment_splits' => $s->paymentSplits->map(fn ($p) => [
                'payment_method' => $p->payment_method,
                'amount' => (float) $p->amount,
            ])->values()->all(),
            'status' => $s->status,
            'is_non_revenue' => (bool) $s->is_non_revenue,
            'is_credit' => (bool) $s->is_credit,
            'table_name' => $s->table?->name,
            'customer_name' => $s->customer_name,
            'customer_phone' => $s->customer_phone,
            'user_name' => $s->user?->name,
            'created_at' => ($s->closed_at ?? $s->created_at)->format($sameDay ? 'H:i' : 'd/m · H:i'),
            'items_preview' => $this->briefItemsLabel($s),
            'items' => $s->items->map(fn ($i) => [
                'name' => $i->product?->name ?? 'Eliminado',
                'is_deleted' => ! $i->product,
                'options_label' => SaleItemOption::label($i->options),
                'quantity' => (float) $i->quantity,
                'subtotal' => (float) $i->subtotal,
            ])->all(),
        ];

        $recentLayaways = $layawayPaymentsToday
            ->groupBy('layaway_id')
            ->map(fn ($payments, $layawayId) => [
                'id' => $payments->first()->layaway?->id,
                'customer_name' => $payments->first()->layaway?->customer_name,
                'customer_phone' => $payments->first()->layaway?->customer_phone,
                'amount_paid_today' => (float) $payments->sum('amount'),
                'payment_methods_today' => $payments->pluck('payment_method')->filter()->unique()->values()->all(),
                'status' => $payments->first()->layaway?->status,
            ])
            ->filter(fn ($l) => $l['id'] !== null)
            ->take(5)
            ->values();

        $serviceOrdersByOrder = $servicePaymentsToday->groupBy('service_order_id');
        $recentServiceOrders = $servicePaymentsToday
            ->pluck('order')->filter()->unique('id')->take(5)
            ->map(fn ($o) => [
                'id' => $o->id,
                'service_name' => $o->service_name,
                'client_name' => $o->client_name ?? $o->client?->name,
                'total' => (float) $o->total,
                'amount_paid' => (float) $o->amount_paid,
                'amount_paid_today' => (float) $serviceOrdersByOrder->get($o->id, collect())->sum('amount'),
                'balance' => max(0, (float) $o->total - (float) $o->amount_paid),
                'status' => $o->status,
                'payment_methods_today' => $serviceOrdersByOrder->get($o->id, collect())->pluck('payment_method')->filter()->unique()->values()->all(),
            ])->values();

        $recentReceivables = $paidReceivables
            ->sortByDesc(fn ($r) => $r->paid_at ?? $r->created_at)
            ->take(10)
            ->map(fn ($r) => [
                'id' => $r->id,
                'customer_name' => $r->customer_name,
                'customer_phone' => $r->customer_phone,
                'amount' => (float) $r->amount,
                'payment_method' => $r->payment_method,
                'paid_at' => ($r->paid_at ?? $r->created_at)->format($sameDay ? 'H:i' : 'd/m · H:i'),
            ])
            ->values();

        $courtesyTotal = (float) $closedSales->where('is_non_revenue', true)->sum('total');
        $totalExpenses = (float) Expense::where('business_id', $businessId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('value');

        return [
            'date' => $from->toDateString(),
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_sales' => $totalSales,
            'total_expenses' => $totalExpenses,
            'net' => round($totalSales - $totalExpenses, 2),
            'closed_sales_revenue' => RevenueByPaymentMethod::salesIncome($revenueSales),
            'financing_payouts_total' => $financingPayoutsTotal,
            'collected_receivables_total' => (float) $paidReceivables->sum('amount'),
            'service_payments_total' => $servicePaymentsTotal,
            'service_payments_count' => $servicePaymentsToday->count(),
            'layaway_payments_total' => $layawayPaymentsTotal,
            'layaway_payments_count' => $layawayPaymentsToday->count(),
            'total_cash' => $totalCash,
            'total_transfer' => $totalTransfer,
            'payment_breakdown' => $paymentBreakdown->values()->all(),
            'channels' => $channels,
            'channels_enabled' => [
                'sales' => true,
                'services' => $business->hasFeature('services'),
                'layaways' => $business->hasFeature('layaway'),
                'receivables' => $business->hasFeature('receivables'),
                'financing' => $business->hasFeature('financing'),
            ],
            'sales_count' => $revenueSales->count(),
            'open_count' => $openSales->count(),
            'open_total' => (float) $openSales->sum('total'),
            'total_all' => $closedSales->count() + $openSales->count(),
            'courtesy_total' => $courtesyTotal,
            'total_discounts_day' => (float) $closedSales->sum(
                fn ($s) => $s->items->sum('discount_amount') + $s->cart_discount_amount
            ),
            'top_products' => $topProducts,
            'recent_sales' => $closedSales->merge($openSales)
                ->sortByDesc(fn ($s) => $s->closed_at ?? $s->created_at)
                ->take(10)->map($mapSale)->values(),
            'open_sales' => $openSales->map($mapSale)->values(),
            'recent_service_orders' => $recentServiceOrders,
            'recent_layaways' => $recentLayaways,
            'recent_receivables' => $recentReceivables,
            'recent_financing_payouts' => $financingPayouts->map(fn (FinancingCredit $credit) => [
                'id' => $credit->id,
                'provider_name' => $credit->provider?->name,
                'customer_name' => $credit->customer_name,
                'approval_number' => $credit->approval_number,
                'amount' => (float) $credit->amount,
                'payout_method' => $credit->payout_method ?: $business->resolveTransferPaymentMethodId(),
                'paid_at' => $credit->paid_at?->format('H:i'),
            ])->values(),
        ];
    }

    /**
     * Fuentes de ingreso del historial. Las ventas no son la unica plata que
     * entra: un apartado se paga por abonos, un servicio se paga aparte, un
     * fiado se cobra despues y una financiadora gira despues. Antes el
     * historial solo mostraba ventas y el dueño no veia esa plata.
     */
    public const INCOME_SOURCES = [
        'sale' => 'Venta',
        'layaway' => 'Abono a apartado',
        'service' => 'Pago de servicio',
        'receivable' => 'Cobro de fiado',
        'financing' => 'Pago de financiera',
    ];

    /**
     * Historial de ingresos paginado con filtros (máx. 90 días de rango):
     * ventas mas abonos de apartados, pagos de servicios, fiados cobrados y
     * giros de financieras, cada fila con su `source`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function salesHistory(Business $business, string $dateFrom, string $dateTo, int $perPage, array $filters): LengthAwarePaginatorContract
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 90);
        $sameDay = $from->toDateString() === $to->toDateString();
        $filters = $this->normalizeFilters($filters, $business);

        $entries = $this->incomeEntries($business, $from, $to, $filters);
        $page = Paginator::resolveCurrentPage();
        $pageEntries = $entries->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $this->hydrateIncomeRows($business, $pageEntries, $sameDay, 'd/m · H:i')->all(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * Mismo historial para exportar CSV (tope 5000 filas).
     *
     * @param  array<string, mixed>  $filters
     */
    public function salesHistoryForExport(Business $business, string $dateFrom, string $dateTo, array $filters): Collection
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 90);
        $sameDay = $from->toDateString() === $to->toDateString();
        $filters = $this->normalizeFilters($filters, $business);

        $entries = $this->incomeEntries($business, $from, $to, $filters)->take(5000)->values();

        return $this->hydrateIncomeRows($business, $entries, $sameDay, 'd/m/Y H:i');
    }

    /**
     * Lista liviana (tipo, id, fecha, monto) de todo lo que entro en el rango,
     * ya filtrada y ordenada. Se arma liviana a proposito: con 90 dias de un
     * negocio con mucho movimiento son miles de filas, y solo se cargan
     * completas las de la pagina que se va a mostrar.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{type: string, id: int, at: Carbon, total: float}>
     */
    private function incomeEntries(Business $business, Carbon $from, Carbon $to, array $filters): Collection
    {
        $source = $filters['source'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $pm = $filters['payment_method'] ?? null;
        $pmVariants = $pm && $pm !== 'mixed' ? Business::paymentMethodIdWithAliases($pm) : [];
        $wants = fn (string $type) => $source === null || $source === $type;
        $entries = collect();

        if ($wants('sale')) {
            $entries = $entries->concat(
                $this->historySalesQuery($business, $from, $to, $filters)
                    ->get(['id', 'status', 'closed_at', 'created_at', 'total'])
                    ->map(fn (Sale $sale) => [
                        'type' => 'sale',
                        'id' => $sale->id,
                        'at' => $sale->closed_at ?? $sale->created_at,
                        'total' => (float) $sale->total,
                    ])
            );
        }

        // Los abonos y cobros ya entraron: no tienen estado "abierta", y uno
        // con varios medios no existe (cada abono es un solo medio).
        $includePayments = ($filters['status'] ?? null) !== 'open' && $pm !== 'mixed';

        if ($includePayments && $wants('layaway')) {
            $entries = $entries->concat(
                LayawayPayment::where('business_id', $business->id)
                    ->whereBetween('created_at', [$from, $to])
                    ->when($pmVariants, fn ($q) => $q->whereIn('payment_method', $pmVariants))
                    ->when($search !== '', fn ($q) => $q->whereHas('layaway', fn ($l) => $l
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")))
                    ->get(['id', 'created_at', 'amount'])
                    ->map(fn ($p) => ['type' => 'layaway', 'id' => $p->id, 'at' => $p->created_at, 'total' => (float) $p->amount])
            );
        }

        if ($includePayments && $wants('service')) {
            $entries = $entries->concat(
                ServicePayment::where('business_id', $business->id)
                    ->whereBetween('created_at', [$from, $to])
                    ->when($pmVariants, fn ($q) => $q->whereIn('payment_method', $pmVariants))
                    ->when($search !== '', fn ($q) => $q->whereHas('order', fn ($o) => $o
                        ->where('service_name', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
                    ->get(['id', 'created_at', 'amount'])
                    ->map(fn ($p) => ['type' => 'service', 'id' => $p->id, 'at' => $p->created_at, 'total' => (float) $p->amount])
            );
        }

        if ($includePayments && $wants('receivable')) {
            $entries = $entries->concat(
                Receivable::where('business_id', $business->id)
                    ->where('status', 'paid')
                    ->whereBetween('paid_at', [$from, $to])
                    ->when($pmVariants, fn ($q) => $q->whereIn('payment_method', $pmVariants))
                    ->when($search !== '', fn ($q) => $q->where(fn ($r) => $r
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")))
                    ->get(['id', 'paid_at', 'amount'])
                    ->map(fn ($r) => ['type' => 'receivable', 'id' => $r->id, 'at' => $r->paid_at, 'total' => (float) $r->amount])
            );
        }

        if ($includePayments && $wants('financing')) {
            $entries = $entries->concat(
                FinancingCredit::where('business_id', $business->id)
                    ->where('status', FinancingCredit::STATUS_PAID)
                    ->whereBetween('paid_at', [$from, $to])
                    ->when($pmVariants, fn ($q) => $q->whereIn('payout_method', $pmVariants))
                    ->when($search !== '', fn ($q) => $q->where(fn ($f) => $f
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('approval_number', 'like', "%{$search}%")))
                    ->get(['id', 'paid_at', 'amount'])
                    ->map(fn ($f) => ['type' => 'financing', 'id' => $f->id, 'at' => $f->paid_at, 'total' => (float) $f->amount])
            );
        }

        $sort = $filters['sort'] ?? null;
        $descending = strtolower((string) ($filters['direction'] ?? 'desc')) !== 'asc';

        if ($sort === 'total') {
            $sorted = $entries->sortBy(fn ($e) => $e['total'], SORT_REGULAR, $descending);
        } else {
            $sorted = $entries->sortBy(fn ($e) => $e['at']->getTimestamp(), SORT_REGULAR, $sort === 'date' ? $descending : true);
        }

        return $sorted->values();
    }

    /**
     * Ventas del rango con los filtros del historial (sin ordenar ni cargar
     * relaciones: eso lo hace quien lo use).
     *
     * @param  array<string, mixed>  $filters
     */
    private function historySalesQuery(Business $business, Carbon $from, Carbon $to, array $filters): Builder
    {
        $query = Sale::where('business_id', $business->id);

        $status = $filters['status'] ?? null;
        if ($status === 'open') {
            $query->where('status', 'open')->whereBetween('created_at', [$from, $to]);
        } elseif ($status === 'closed') {
            $query->where('status', 'closed')->whereBetween('closed_at', [$from, $to]);
        } else {
            $query->where(function ($q) use ($from, $to) {
                $q->where(fn ($q2) => $q2->where('status', 'closed')->whereBetween('closed_at', [$from, $to]))
                    ->orWhere(fn ($q2) => $q2->where('status', 'open')->whereBetween('created_at', [$from, $to]));
            });
        }

        if (! empty($filters['payment_method'])) {
            $pm = $filters['payment_method'];
            if ($pm === 'mixed') {
                $query->where('payment_method', 'mixed');
            } else {
                // whereIn con los alias (no solo $pm): sales/sale_payment_splits
                // guardan a veces el vocabulario en espanol del legacy
                // (`efectivo`) y a veces el normalizado (`cash`) segun que app
                // escribio la fila - una comparacion exacta perdia el 100% de
                // las ventas de negocios con datos legacy (ver docs/CUTOVER_TODO.md #1).
                $pmVariants = Business::paymentMethodIdWithAliases($pm);
                $query->where(function ($q) use ($pmVariants) {
                    $q->whereIn('payment_method', $pmVariants)
                        ->orWhere(fn ($q2) => $q2
                            ->where('payment_method', 'mixed')
                            ->whereHas('paymentSplits', fn ($s) => $s->whereIn('payment_method', $pmVariants)));
                });
            }
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    /**
     * Carga completas solo las filas pedidas y las arma en el formato del
     * historial, en el mismo orden.
     *
     * @param  Collection<int, array{type: string, id: int, at: Carbon, total: float}>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function hydrateIncomeRows(Business $business, Collection $entries, bool $sameDay, string $dateFormat): Collection
    {
        $idsOf = fn (string $type) => $entries->where('type', $type)->pluck('id')->all();

        $sales = Sale::whereIn('id', $idsOf('sale'))
            ->with(['items.product:id,name', 'items.options', 'user:id,name', 'table:id,name', 'paymentSplits'])
            ->get()->keyBy('id');
        $layaways = LayawayPayment::whereIn('id', $idsOf('layaway'))
            ->with(['layaway:id,customer_name,customer_phone', 'recordedBy:id,name'])->get()->keyBy('id');
        $services = ServicePayment::whereIn('id', $idsOf('service'))
            ->with(['order:id,service_name,client_id', 'order.client:id,name,phone', 'recordedBy:id,name'])->get()->keyBy('id');
        $receivables = Receivable::whereIn('id', $idsOf('receivable'))
            ->with(['collectedByUser:id,name', 'sale:id,invoice_number'])->get()->keyBy('id');
        $financing = FinancingCredit::whereIn('id', $idsOf('financing'))
            ->with(['provider:id,name', 'sale:id,invoice_number'])->get()->keyBy('id');

        $date = fn (Carbon $at) => $sameDay ? $at->format('H:i') : $at->format($dateFormat);

        $payment = fn (string $type, int $id, float $total, string $method, ?string $customer, ?string $phone, ?string $userName, string $description, ?string $reference, Carbon $at) => [
            'row_key' => "{$type}-{$id}",
            'id' => null,
            'source' => $type,
            'source_label' => self::INCOME_SOURCES[$type],
            'invoice_number' => $reference,
            'total' => $total,
            'payment_method' => $business->normalizePaymentMethodId($method),
            'payment_splits' => [],
            'status' => 'closed',
            'is_non_revenue' => false,
            'is_credit' => false,
            'table_name' => null,
            'customer_name' => $customer,
            'customer_phone' => $phone,
            'user_name' => $userName,
            'created_at' => $date($at),
            'items_preview' => $description,
            'items' => [],
        ];

        return $entries->map(function (array $entry) use ($business, $sales, $layaways, $services, $receivables, $financing, $date, $payment) {
            switch ($entry['type']) {
                case 'layaway':
                    $p = $layaways->get($entry['id']);

                    return $p ? $payment('layaway', $p->id, (float) $p->amount, (string) $p->payment_method,
                        $p->layaway?->customer_name, $p->layaway?->customer_phone, $p->recordedBy?->name,
                        'Abono al apartado #'.$p->layaway_id, 'Apartado #'.$p->layaway_id, $entry['at']) : null;
                case 'service':
                    $p = $services->get($entry['id']);

                    return $p ? $payment('service', $p->id, (float) $p->amount, (string) $p->payment_method,
                        $p->order?->client?->name, $p->order?->client?->phone, $p->recordedBy?->name,
                        'Pago de '.($p->order?->service_name ?: 'servicio'), 'Orden #'.$p->service_order_id, $entry['at']) : null;
                case 'receivable':
                    $r = $receivables->get($entry['id']);

                    return $r ? $payment('receivable', $r->id, (float) $r->amount, (string) $r->payment_method,
                        $r->customer_name, $r->customer_phone, $r->collectedByUser?->name,
                        'Cobro del fiado'.($r->sale?->invoice_number ? ' de la venta '.$r->sale->invoice_number : ''),
                        $r->sale?->invoice_number, $entry['at']) : null;
                case 'financing':
                    $f = $financing->get($entry['id']);

                    return $f ? $payment('financing', $f->id, (float) $f->amount,
                        (string) ($f->payout_method ?: $business->resolveTransferPaymentMethodId()),
                        $f->customer_name, $f->customer_phone, null,
                        'Giro de '.($f->provider?->name ?? 'la financiera').($f->approval_number ? ' · aprobación '.$f->approval_number : ''),
                        $f->sale?->invoice_number, $entry['at']) : null;
            }

            $s = $sales->get($entry['id']);
            if (! $s) {
                return null;
            }

            return [
                'row_key' => "sale-{$s->id}",
                'id' => $s->id,
                'source' => 'sale',
                // Un fiado es una venta, pero la plata entra cuando se cobra
                // (fila «Cobro de fiado»): se rotula distinto para no leerla
                // como ingreso del dia.
                'source_label' => $s->is_credit ? 'Venta a crédito' : self::INCOME_SOURCES['sale'],
                'invoice_number' => $s->invoice_number,
                'total' => (float) $s->total,
                // Normalizado al vocabulario configurado del negocio (ver
                // Business::normalizePaymentMethodId()).
                'payment_method' => $business->normalizePaymentMethodId($s->payment_method),
                'payment_splits' => $s->paymentSplits->map(fn ($p) => [
                    'payment_method' => $business->normalizePaymentMethodId($p->payment_method),
                    'amount' => (float) $p->amount,
                ])->values()->all(),
                'status' => $s->status,
                'is_non_revenue' => (bool) $s->is_non_revenue,
                'is_credit' => (bool) $s->is_credit,
                'table_name' => $s->table?->name,
                'customer_name' => $s->customer_name,
                'customer_phone' => $s->customer_phone,
                'user_name' => $s->user?->name,
                'created_at' => $date($entry['at']),
                'items_preview' => $this->briefItemsLabel($s),
                'items' => $s->items->map(fn ($i) => [
                    'name' => $i->product?->name ?? 'Eliminado',
                    'is_deleted' => ! $i->product,
                    'options_label' => SaleItemOption::label($i->options),
                    'quantity' => (float) $i->quantity,
                    'subtotal' => (float) $i->subtotal,
                ])->all(),
            ];
        })->filter()->values();
    }

    /**
     * Resumen de ventas agrupado por vendedor (cajero que cerró la venta).
     *
     * @return array<string, mixed>
     */
    public function salesBySeller(Business $business, string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 90);

        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->whereBetween('closed_at', [$from, $to])
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->with([
                'closedByUser:id,name',
                'items:id,sale_id,quantity',
                'paymentSplits:id,sale_id,payment_method,amount',
            ])
            ->get(['id', 'closed_by_user_id', 'total', 'payment_method', 'closed_at']);

        $methodLabels = $this->paymentMethodLabels($business);

        $bySeller = [];
        foreach ($sales as $sale) {
            $key = $sale->closed_by_user_id ?: 0;
            if (! isset($bySeller[$key])) {
                $bySeller[$key] = [
                    'user_id' => $sale->closed_by_user_id,
                    'user_name' => $sale->closedByUser?->name ?? 'Usuario eliminado',
                    'sales_count' => 0,
                    'gross_total' => 0.0,
                    'items_sold' => 0.0,
                    'last_sale_at' => null,
                    'methods_map' => [],
                ];
            }

            $bySeller[$key]['sales_count']++;
            $bySeller[$key]['gross_total'] += (float) $sale->total;
            $bySeller[$key]['items_sold'] += (float) $sale->items->sum('quantity');

            $current = $bySeller[$key]['last_sale_at'];
            if ($current === null || $sale->closed_at->gt($current)) {
                $bySeller[$key]['last_sale_at'] = $sale->closed_at;
            }

            foreach ($sale->allocatedRevenueByPaymentMethod() as $methodId => $amount) {
                $id = (string) $methodId;
                $bySeller[$key]['methods_map'][$id] = ($bySeller[$key]['methods_map'][$id] ?? 0) + (float) $amount;
            }
        }

        $sellers = collect($bySeller)->map(function (array $row) use ($methodLabels) {
            $count = (int) $row['sales_count'];
            $total = round((float) $row['gross_total'], 2);
            $methods = collect($row['methods_map'])
                ->map(fn ($methodTotal, $methodId) => [
                    'id' => (string) $methodId,
                    'label' => (string) ($methodLabels[$methodId] ?? ucfirst(str_replace('_', ' ', (string) $methodId))),
                    'total' => round((float) $methodTotal, 2),
                ])
                ->sortByDesc('total')
                ->values()
                ->all();

            return [
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'],
                'sales_count' => $count,
                'gross_total' => $total,
                'avg_ticket' => $count > 0 ? round($total / $count, 2) : 0.0,
                'items_sold' => (float) $row['items_sold'],
                'last_sale_at' => $row['last_sale_at']?->format('d/m/Y H:i'),
                'methods' => $methods,
            ];
        })
            ->sortByDesc('gross_total')
            ->values()
            ->all();

        $totalCount = $sales->count();
        $totalGross = round((float) $sales->sum('total'), 2);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'sales_count' => $totalCount,
                'gross_total' => $totalGross,
                'avg_ticket' => $totalCount > 0 ? round($totalGross / $totalCount, 2) : 0.0,
                'sellers_count' => count($bySeller),
            ],
            'sellers' => $sellers,
        ];
    }

    /**
     * Cierres de caja del negocio filtrados por rango de fechas o mes.
     *
     * @return array<string, mixed>
     */
    public function cashClosings(Business $business, string $dateFrom, string $dateTo): array
    {
        $closings = CashClosing::where('business_id', $business->id)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->with('closedByUser:id,name')
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'date' => $c->date instanceof Carbon ? $c->date->format('Y-m-d') : (string) $c->date,
                'opening_cash' => (float) $c->opening_cash,
                'total_sales' => (float) $c->total_sales,
                'total_cash' => (float) $c->total_cash,
                'total_other' => (float) $c->total_other_methods,
                'payment_breakdown' => $c->payment_breakdown ?? [],
                'total_expenses' => (float) $c->total_expenses,
                'expected_cash' => (float) $c->expected_cash,
                'actual_cash' => (float) $c->actual_cash,
                'base_for_next_day' => (float) $c->base_for_next_day,
                'difference' => (float) $c->difference,
                'closed_by_name' => $c->closedByUser?->name ?? '—',
            ])
            ->values()
            ->all();

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'closings' => $closings,
        ];
    }

    /**
     * Opciones de métodos de pago para filtros de reporte (incluye "Varios").
     * Solo los HABILITADOS del negocio - uno que ya desactivó no debería
     * seguir ofreciéndose en el dropdown de filtro. Para resolver el label
     * de una venta histórica que pudo usar un medio ya desactivado, ver
     * paymentMethodLabels() (incluye todos, habilitados o no).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function paymentMethodOptions(Business $business): array
    {
        $opts = [];
        foreach ($business->enabledPaymentMethods() as $m) {
            $id = strtolower(trim($m['id']));
            if ($id === '') {
                continue;
            }
            $opts[] = ['id' => $id, 'label' => $m['label']];
        }
        if (! collect($opts)->contains(fn ($o) => ($o['id'] ?? '') === 'mixed')) {
            $opts[] = ['id' => 'mixed', 'label' => 'Varios medios'];
        }

        return $opts;
    }

    /**
     * id => label de TODOS los métodos configurados (habilitados o no) - a
     * diferencia de paymentMethodOptions(), no filtra por 'enabled'. Uso
     * interno para resolver labels de datos ya existentes (ventas
     * históricas con un medio que el negocio pudo haber desactivado
     * después) y para validar el filtro entrante, que sigue debiendo poder
     * apuntar a un medio desactivado para consultar ventas viejas - solo el
     * dropdown visible se restringe a los habilitados.
     *
     * @return Collection<string, string>
     */
    public function paymentMethodLabels(Business $business): Collection
    {
        $labels = collect($business->paymentMethodLabelsMap())
            ->mapWithKeys(fn (string $label, string $id) => [strtolower(trim($id)) => $label])
            ->filter(fn ($label, $id) => $id !== '');

        if (! $labels->has('mixed')) {
            $labels->put('mixed', 'Varios medios');
        }

        return $labels;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function normalizeDateRange(string $from, string $to, int $maxDays = 90): array
    {
        try {
            $fromDate = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        } catch (\Throwable) {
            $fromDate = Carbon::today()->startOfDay();
        }
        try {
            $toDate = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();
        } catch (\Throwable) {
            $toDate = $fromDate->copy()->endOfDay();
        }
        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }
        if ($fromDate->diffInDays($toDate) > $maxDays) {
            $toDate = $fromDate->copy()->addDays($maxDays)->endOfDay();
        }

        return [$fromDate, $toDate];
    }

    /**
     * Resolución de rango de mes (para cierres de caja).
     *
     * @return array{0: string, 1: string}
     */
    public function resolveCashClosingDates(string $month, string $dateFrom, string $dateTo): array
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $parsed = Carbon::createFromFormat('Y-m', $month);

            return [$parsed->startOfMonth()->toDateString(), $parsed->copy()->endOfMonth()->toDateString()];
        }

        try {
            $from = Carbon::createFromFormat('Y-m-d', $dateFrom)->toDateString();
        } catch (\Throwable) {
            $from = Carbon::now()->startOfMonth()->toDateString();
        }
        try {
            $to = Carbon::createFromFormat('Y-m-d', $dateTo)->toDateString();
        } catch (\Throwable) {
            $to = Carbon::now()->toDateString();
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    // ─── privados ──────────────────────────────────────────────────────────────

    private function briefItemsLabel(Sale $sale, int $max = 4): string
    {
        $parts = [];
        foreach ($sale->items->take($max) as $item) {
            $parts[] = (int) $item->quantity.'× '.($item->product?->name ?? 'Eliminado');
        }
        $remaining = $sale->items->count() - $max;
        if ($remaining > 0) {
            $parts[] = "+{$remaining} más";
        }

        return $parts === [] ? 'Sin productos' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters, Business $business): array
    {
        $out = [];
        if (isset($filters['status']) && in_array($filters['status'], ['open', 'closed'], true)) {
            $out['status'] = $filters['status'];
        }
        // Contra TODOS los ids conocidos (habilitados o no), no solo las
        // opciones visibles del dropdown - filtrar ventas historicas por un
        // medio que el negocio ya desactivo sigue siendo un caso valido.
        $allowedPm = $this->paymentMethodLabels($business)->keys()->all();
        if (! empty($filters['payment_method'])) {
            $pm = strtolower(trim((string) $filters['payment_method']));
            if (in_array($pm, $allowedPm, true)) {
                $out['payment_method'] = $pm;
            }
        }
        if (! empty($filters['source']) && array_key_exists($filters['source'], self::INCOME_SOURCES)) {
            $out['source'] = $filters['source'];
        }
        if (array_key_exists('search', $filters)) {
            $out['search'] = $filters['search'];
        }
        if (array_key_exists('sort', $filters)) {
            $out['sort'] = $filters['sort'];
        }
        if (array_key_exists('direction', $filters)) {
            $out['direction'] = $filters['direction'];
        }

        return $out;
    }
}
