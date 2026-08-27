<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\Expense;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePayment;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        $servicePaymentsTotal = (float) $servicePaymentsToday->sum('amount');
        $layawayPaymentsTotal = (float) $layawayPaymentsToday->sum('amount');

        $totalSales = (float) $revenueSales->sum('total')
            + (float) $paidReceivables->sum('amount')
            + $servicePaymentsTotal
            + $layawayPaymentsTotal;

        $paymentBreakdown = RevenueByPaymentMethod::combined(
            $business,
            $revenueSales,
            $paidReceivables,
            $servicePaymentsToday,
            $layawayPaymentsToday,
        );

        $channels = RevenueByPaymentMethod::perChannel(
            $business,
            $revenueSales,
            $paidReceivables,
            $servicePaymentsToday,
            $layawayPaymentsToday,
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
                'client_name' => $o->client?->name,
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
            ->where('scope', 'operacional')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('value');

        return [
            'date' => $from->toDateString(),
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_sales' => $totalSales,
            'total_expenses' => $totalExpenses,
            'net' => round($totalSales - $totalExpenses, 2),
            'closed_sales_revenue' => (float) $revenueSales->sum('total'),
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
        ];
    }

    /**
     * Historial de ventas paginado con filtros (máx. 90 días de rango).
     *
     * @param  array<string, mixed>  $filters
     */
    public function salesHistory(Business $business, string $dateFrom, string $dateTo, int $perPage, array $filters): LengthAwarePaginator
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 90);
        $sameDay = $from->toDateString() === $to->toDateString();

        $filters = $this->normalizeFilters($filters, $business);

        $query = Sale::where('business_id', $business->id)
            ->with(['items.product:id,name', 'user:id,name', 'table:id,name', 'paymentSplits']);

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
                $query->where(function ($q) use ($pm) {
                    $q->where('payment_method', $pm)
                        ->orWhere(fn ($q2) => $q2
                            ->where('payment_method', 'mixed')
                            ->whereHas('paymentSplits', fn ($s) => $s->where('payment_method', $pm)));
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

        return $query->orderByRaw('COALESCE(closed_at, created_at) DESC')
            ->paginate($perPage)
            ->through(function ($s) use ($sameDay) {
                $relevant = $s->closed_at ?? $s->created_at;

                return [
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
                    'created_at' => $sameDay ? $relevant->format('H:i') : $relevant->format('d/m · H:i'),
                    'items_preview' => $this->briefItemsLabel($s),
                    'items' => $s->items->map(fn ($i) => [
                        'name' => $i->product?->name ?? 'Eliminado',
                        'is_deleted' => ! $i->product,
                        'quantity' => (float) $i->quantity,
                        'subtotal' => (float) $i->subtotal,
                    ])->all(),
                ];
            });
    }

    /**
     * Colección de ventas para exportar CSV (mismo filtro que salesHistory, tope 5000).
     *
     * @param  array<string, mixed>  $filters
     */
    public function salesHistoryForExport(Business $business, string $dateFrom, string $dateTo, array $filters): Collection
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 90);
        $sameDay = $from->toDateString() === $to->toDateString();

        $filters = $this->normalizeFilters($filters, $business);

        $query = Sale::where('business_id', $business->id)
            ->with(['items.product:id,name', 'user:id,name', 'table:id,name', 'paymentSplits']);

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
                $query->where(fn ($q) => $q->where('payment_method', $pm)
                    ->orWhere(fn ($q2) => $q2->where('payment_method', 'mixed')
                        ->whereHas('paymentSplits', fn ($s) => $s->where('payment_method', $pm))));
            }
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(fn ($q) => $q
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")
                ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$search}%")));
        }

        return $query->orderByRaw('COALESCE(closed_at, created_at) DESC')
            ->limit(5000)
            ->get()
            ->map(function ($s) use ($sameDay) {
                $relevant = $s->closed_at ?? $s->created_at;

                return [
                    'id' => $s->id,
                    'invoice_number' => $s->invoice_number,
                    'total' => (float) $s->total,
                    'payment_method' => $s->payment_method,
                    'status' => $s->status,
                    'is_non_revenue' => (bool) $s->is_non_revenue,
                    'is_credit' => (bool) $s->is_credit,
                    'customer_name' => $s->customer_name,
                    'customer_phone' => $s->customer_phone,
                    'user_name' => $s->user?->name,
                    'created_at' => $sameDay ? $relevant->format('H:i') : $relevant->format('d/m/Y H:i'),
                    'items_preview' => $this->briefItemsLabel($s),
                ];
            });
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

        $methodLabels = collect($this->paymentMethodOptions($business))
            ->pluck('label', 'id');

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
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function paymentMethodOptions(Business $business): array
    {
        $opts = [];
        foreach ($business->paymentMethods() as $m) {
            $id = strtolower(trim((string) ($m['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $opts[] = [
                'id' => $id,
                'label' => (string) ($m['label'] ?? ucfirst(str_replace('_', ' ', $id))),
            ];
        }
        if (! collect($opts)->contains(fn ($o) => ($o['id'] ?? '') === 'mixed')) {
            $opts[] = ['id' => 'mixed', 'label' => 'Varios medios'];
        }

        return $opts;
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
        $allowedPm = collect($this->paymentMethodOptions($business))->pluck('id')->map(fn ($id) => strtolower((string) $id))->all();
        if (! empty($filters['payment_method'])) {
            $pm = strtolower(trim((string) $filters['payment_method']));
            if (in_array($pm, $allowedPm, true)) {
                $out['payment_method'] = $pm;
            }
        }
        if (array_key_exists('search', $filters)) {
            $out['search'] = $filters['search'];
        }

        return $out;
    }
}
