<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePaymentSplit;
use App\Models\User;
use App\Support\ProductAvailability;
use App\Support\SaleLineUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cubre el flujo de venta directa (mostrador) y las piezas que OpenTabService
 * reutiliza al cerrar una cuenta abierta (applyItems, applyCartDiscount,
 * resolveCharges, syncReceivable, ensureInvoiceNumber). La comandera (kitchen
 * board) es un modulo aparte que todavia no existe en esta API.
 */
class SaleService
{
    public function __construct(private StockService $stockService) {}

    public function createSale(User $user, array $data): Sale
    {
        return DB::transaction(function () use ($user, $data) {
            $business = $user->business;
            $flags = $this->resolveSaleFlags($business, $data);

            $sale = Sale::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'payment_method' => null,
                'total' => 0,
                'status' => 'closed',
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_identification' => $data['customer_identification'] ?? null,
                'is_delivery' => $flags['is_delivery'],
                'delivery_fee' => $flags['delivery_fee'],
                'is_non_revenue' => $flags['is_non_revenue'],
                'non_revenue_reason' => $flags['is_non_revenue'] ? ($data['non_revenue_reason'] ?? 'Cortesia') : null,
                'is_credit' => false,
            ]);

            $total = $this->applyItems($user, $sale, $business, $data['items']);

            [$cartDiscountId, $cartDiscountAmount, $total] = $this->applyCartDiscount(
                $business, $total, $data['cart_discount_id'] ?? null
            );

            [$serviceChargeAmount, $ipoconsumoAmount] = $this->resolveCharges($business, $total, $data);

            $grandTotal = round($total + $flags['delivery_fee'] + $serviceChargeAmount + $ipoconsumoAmount, 2);

            [$resolvedPaymentMethod, $isCredit] = $flags['is_non_revenue']
                ? [null, false]
                : $this->resolvePaymentMethodOrSplits($business, $sale, $data, $grandTotal);

            $sale->update([
                'total' => $grandTotal,
                'payment_method' => $resolvedPaymentMethod,
                'is_credit' => $isCredit,
                'cart_discount_id' => $cartDiscountId,
                'cart_discount_amount' => $cartDiscountAmount,
                'service_charge_amount' => $serviceChargeAmount,
                'ipoconsumo_amount' => $ipoconsumoAmount,
            ]);

            $this->ensureInvoiceNumber($sale);
            $this->syncReceivable($sale->fresh());

            return $sale->load('items.product', 'cartDiscount', 'paymentSplits');
        });
    }

    /**
     * Resuelve el metodo de pago de una venta directa: un solo medio, o
     * pago dividido (2+ medios) si $data trae payment_splits - misma logica
     * de pago dividido que OpenTabService::close(), reutilizada via los
     * metodos publicos de abajo para no duplicarla.
     *
     * @return array{0: ?string, 1: bool} [payment_method resuelto, is_credit]
     */
    private function resolvePaymentMethodOrSplits(Business $business, Sale $sale, array $data, float $grandTotal): array
    {
        if (is_array($data['payment_splits'] ?? null) && count($data['payment_splits']) >= 2) {
            $normalized = $this->normalizePaymentSplitInput($data['payment_splits']);
            $this->assertValidPaymentSplits($normalized, $grandTotal);
            $method = $this->applyMixedPaymentRows($business, $sale, $normalized);

            return [$method, $business->isCreditPaymentMethod($method)];
        }

        if (! empty($data['payment_method'])) {
            $method = strtolower(trim((string) $data['payment_method']));
            $business->assertValidPaymentMethod($method);

            return [$method, $business->isCreditPaymentMethod($method)];
        }

        throw ValidationException::withMessages([
            'payment_method' => 'Selecciona un metodo de pago o usa pago dividido con dos o mas medios.',
        ]);
    }

    /**
     * Normaliza filas de pago dividido {method, amount, label} desde input
     * crudo del cliente. Publico y compartido con OpenTabService::close() -
     * unica logica de pago dividido en toda la app.
     *
     * @param  array<int, array{method?: string, amount?: float|int|string, label?: string, payer_label?: string}>  $paymentSplits
     * @return array<int, array{method: string, amount: float, label: ?string}>
     */
    public function normalizePaymentSplitInput(array $paymentSplits): array
    {
        return collect($paymentSplits)
            ->map(function ($row) {
                $rawLabel = $row['label'] ?? $row['payer_label'] ?? null;
                $label = $rawLabel !== null && $rawLabel !== '' ? mb_substr(trim((string) $rawLabel), 0, 120) : null;

                return [
                    'method' => strtolower(trim((string) ($row['method'] ?? ''))),
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'label' => $label,
                ];
            })
            ->filter(fn ($r) => $r['amount'] > 0.009)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{method: string, amount: float, label?: ?string}>  $rows
     */
    public function assertValidPaymentSplits(array $rows, float $expectedTotal): void
    {
        $filtered = collect($rows)->filter(fn ($r) => $r['amount'] > 0.009)->values();

        if ($filtered->count() < 2) {
            throw ValidationException::withMessages([
                'payment_splits' => 'Indica al menos dos lineas de pago con montos mayores a cero.',
            ]);
        }

        if (abs(round($filtered->sum('amount'), 2) - round($expectedTotal, 2)) > 0.02) {
            throw ValidationException::withMessages([
                'payment_splits' => 'La suma de los pagos debe coincidir con el total de la cuenta.',
            ]);
        }
    }

    /**
     * Crea las filas SalePaymentSplit para $rows y devuelve el payment_method
     * a guardar en la venta: el metodo unico si solo hay una linea valida, o
     * 'mixed' si hay dos o mas.
     *
     * @param  array<int, array{method: string, amount: float, label?: ?string}>  $rows
     */
    public function applyMixedPaymentRows(Business $business, Sale $sale, array $rows): string
    {
        $filtered = array_values(array_filter($rows, fn ($r) => $r['amount'] > 0.009));

        if (count($filtered) === 1) {
            $business->assertValidPaymentMethod($filtered[0]['method']);

            return $filtered[0]['method'];
        }

        foreach ($filtered as $row) {
            $business->assertValidPaymentMethod($row['method'], forbidCredit: true);
            SalePaymentSplit::create([
                'sale_id' => $sale->id,
                'payment_method' => $row['method'],
                'amount' => round($row['amount'], 2),
                'payer_label' => $row['label'] ?? null,
            ]);
        }

        return 'mixed';
    }

    /**
     * Reversa (anula) una venta cerrada: restaura el stock de cada item y
     * elimina la venta. Las ventas no tienen soft deletes - es un borrado
     * definitivo, igual que en el legacy.
     *
     * @throws ValidationException Si la venta genero un fiado que ya fue cobrado.
     */
    public function reverseSale(User $user, Sale $sale): void
    {
        DB::transaction(function () use ($user, $sale) {
            $ingredientsEnabled = $sale->business->hasFeature('ingredients');
            $sale->loadMissing($ingredientsEnabled ? 'items.product.ingredients' : 'items.product');

            $receivable = Receivable::where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->first();

            if ($receivable && $receivable->status === 'paid') {
                throw ValidationException::withMessages([
                    'sale' => 'No se puede reversar: esta venta fiada ya fue cobrada.',
                ]);
            }

            foreach ($sale->items as $item) {
                $product = $item->product;
                if ($product && $item->quantity > 0) {
                    $this->stockService->registerSaleReversal(
                        $user, $product, (int) $item->quantity, $sale, 'Reverso de venta'
                    );
                    $this->stockService->restoreIngredientsConsumption(
                        $user, $product, (int) $item->quantity, $sale, 'Reverso de venta'
                    );
                }
            }

            $receivable?->delete();
            $sale->delete();
        });
    }

    /**
     * Crea/fusiona (si is_credit) o limpia (si no) el fiado pendiente asociado a
     * $sale. Reutilizado por OpenTabService al cerrar una cuenta como fiado.
     * Publico a proposito, igual que applyItems/applyCartDiscount/resolveCharges:
     * es la unica logica de fiados en toda la app, no se reimplementa por flujo.
     */
    public function syncReceivable(Sale $sale): void
    {
        if (! $sale->is_credit) {
            Receivable::where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->where('status', 'pending')
                ->delete();

            return;
        }

        $customerKey = $this->buildReceivableCustomerKey(
            $sale->customer_phone, $sale->customer_identification, $sale->id
        );

        $existingPending = Receivable::where('business_id', $sale->business_id)
            ->where('status', 'pending')
            ->where('customer_key', $customerKey)
            ->first();

        if ($existingPending) {
            $existingPending->update([
                'sale_id' => $sale->id,
                'customer_name' => $sale->customer_name ?: $existingPending->customer_name,
                'customer_phone' => $sale->customer_phone ?: $existingPending->customer_phone,
                'customer_identification' => $sale->customer_identification ?: $existingPending->customer_identification,
                'amount' => (float) $existingPending->amount + (float) $sale->total,
                'balance' => (float) $existingPending->balance + (float) $sale->total,
            ]);

            return;
        }

        Receivable::create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'customer_identification' => $sale->customer_identification,
            'customer_key' => $customerKey,
            'amount' => $sale->total,
            'balance' => $sale->total,
            'status' => 'pending',
        ]);
    }

    /**
     * El nombre NUNCA participa como clave de fusion: dos clientes distintos con
     * el mismo nombre (comun sin telefono/cedula) no deben sumarse en un solo
     * fiado. Solo un identificador fuerte (telefono o cedula) confirma que es la
     * misma persona; sin uno, cada venta es un fiado independiente.
     */
    private function buildReceivableCustomerKey(?string $customerPhone, ?string $customerIdentification, int $saleId): string
    {
        $normalizedPhone = preg_replace('/\D+/', '', (string) ($customerPhone ?? ''));
        if ($normalizedPhone !== '') {
            return "phone:{$normalizedPhone}";
        }

        $normalizedIdentification = strtolower(trim((string) ($customerIdentification ?? '')));
        if ($normalizedIdentification !== '') {
            return "id:{$normalizedIdentification}";
        }

        return "sale:{$saleId}";
    }

    /**
     * Cargo por servicio e IPOCONSUMO calculados en el SERVIDOR desde las tasas
     * configuradas del negocio (tasa % x base), no desde montos que manda el
     * cliente - el legacy confiaba en lo que llegaba del front. La base es el
     * subtotal ya con descuentos, antes del domicilio (que es un costo de
     * traslado, no consumo gravable). El cliente solo puede renunciar a un cargo
     * habilitado (apply_service_charge / apply_ipoconsumo false), no fijar su
     * monto.
     *
     * @return array{0: float, 1: float}
     */
    public function resolveCharges(Business $business, float $base, array $data): array
    {
        if (! $business->hasFeature('charges')) {
            return [0.0, 0.0];
        }

        $config = $business->chargesConfig();
        $wantsService = ! array_key_exists('apply_service_charge', $data)
            || filter_var($data['apply_service_charge'], FILTER_VALIDATE_BOOLEAN);
        $wantsIpoconsumo = ! array_key_exists('apply_ipoconsumo', $data)
            || filter_var($data['apply_ipoconsumo'], FILTER_VALIDATE_BOOLEAN);

        $service = ($config['service_charge_enabled'] && $wantsService)
            ? round($base * $config['service_charge_rate'] / 100, 2)
            : 0.0;

        $ipoconsumo = ($config['ipoconsumo_enabled'] && $wantsIpoconsumo)
            ? round($base * $config['ipoconsumo_rate'] / 100, 2)
            : 0.0;

        return [$service, $ipoconsumo];
    }

    /**
     * @return array{is_non_revenue: bool, is_delivery: bool, delivery_fee: float}
     */
    private function resolveSaleFlags(Business $business, array $data): array
    {
        $isDelivery = (bool) $business->delivery_enabled && (bool) ($data['is_delivery'] ?? false);
        $deliveryFee = $isDelivery ? (float) $business->delivery_fee : 0.0;

        return [
            'is_non_revenue' => (bool) ($data['is_non_revenue'] ?? false),
            'is_delivery' => $isDelivery,
            'delivery_fee' => $deliveryFee,
        ];
    }

    /**
     * Crea los SaleItem de $items sobre $sale y descuenta stock, bajo
     * lockForUpdate (ver la nota en createSale sobre sobreventa). Publico y
     * reutilizado por OpenTabService al abrir/agregar items a una cuenta -
     * unico lugar donde vive esta logica, en vez de que cada flujo la
     * reimplemente a su manera.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price?: float|int|string|null, discount_id?: ?int}>  $items
     * @return float Total de los items (con descuento de item ya restado, sin cargos ni descuento de carrito).
     */
    public function applyItems(User $user, Sale $sale, Business $business, array $items): float
    {
        $discountsEnabled = $business->hasFeature('discounts');
        $ingredientsEnabled = $business->hasFeature('ingredients');
        $total = 0.0;

        $productIds = collect($items)->pluck('product_id')->unique()->values()->all();
        $products = Product::where('business_id', $business->id)
            ->whereIn('id', $productIds)
            ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];
            $availableStock = ProductAvailability::effectiveStock($product, $ingredientsEnabled);

            if ($product->track_stock && $quantity > $availableStock) {
                throw ValidationException::withMessages([
                    'items' => 'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $availableStock.').',
                ]);
            }

            $unitPrice = SaleLineUnitPrice::resolve($product, $item);
            $lineSubtotal = $unitPrice * $quantity;

            [$discountId, $discountAmount] = $discountsEnabled
                ? Discount::resolveActive($business->id, 'item', $item['discount_id'] ?? null, $lineSubtotal)
                : [null, 0.0];

            // Si la cuenta ya tiene una linea del mismo producto con el mismo
            // precio y descuento (tipico al "agregar mas" de algo que ya
            // estaba en la cuenta), se suma la cantidad ahi en vez de crear
            // una linea nueva - antes cada llamada a addItems generaba una
            // fila aparte aunque fuera literalmente el mismo producto.
            $existingLine = $sale->items()
                ->where('product_id', $product->id)
                ->where('unit_price', $unitPrice)
                ->where('discount_id', $discountId)
                ->first();

            if ($existingLine) {
                $existingLine->increment('quantity', $quantity);
                $existingLine->increment('subtotal', $lineSubtotal);
                $existingLine->increment('discount_amount', $discountAmount);
                if ($sale->isOpen()) {
                    $existingLine->update(['kitchen_status' => 'pending', 'kitchen_updated_at' => now()]);
                }
            } else {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost_at_sale' => (float) $product->cost_price,
                    'subtotal' => $lineSubtotal,
                    'discount_id' => $discountId,
                    'discount_amount' => $discountAmount,
                    'kitchen_status' => $sale->isOpen() ? 'pending' : null,
                    'kitchen_updated_at' => $sale->isOpen() ? now() : null,
                ]);
            }

            if ($product->track_stock) {
                $this->stockService->registerSale($user, $product, $quantity, $sale);
                $this->stockService->registerIngredientsConsumption($user, $product, $quantity, $sale);

                // Ajuste en memoria (el movimiento ya persistio el cambio real en
                // BD): si $items trae dos lineas del mismo producto, la siguiente
                // vuelta del loop debe ver el stock ya descontado, no el que tenia
                // $product al cargarlo bajo lockForUpdate(). Para un producto con
                // receta se descuenta cada ingrediente en vez de products.stock.
                if ($product->isStockManagedByIngredientsRecipe()) {
                    foreach ($product->ingredients as $ingredient) {
                        $ingredient->stock -= (float) $ingredient->pivot->quantity * $quantity;
                    }
                } else {
                    $product->stock -= $quantity;
                }
            }

            $total += $lineSubtotal - $discountAmount;
        }

        return $total;
    }

    /**
     * @return array{0: ?int, 1: float, 2: float} [cart_discount_id, monto, total resultante]
     */
    public function applyCartDiscount(Business $business, float $total, ?int $cartDiscountId): array
    {
        if (! $business->hasFeature('discounts') || ! $cartDiscountId) {
            return [null, 0.0, $total];
        }

        [$resolvedId, $amount] = Discount::resolveActive($business->id, 'cart', $cartDiscountId, $total);

        return [$resolvedId, $amount, $total - $amount];
    }

    public function ensureInvoiceNumber(Sale $sale): void
    {
        if ($sale->invoice_number) {
            return;
        }

        $last = Sale::where('business_id', $sale->business_id)
            ->whereNotNull('invoice_number')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $next = 1;
        if ($last && preg_match('/(\d+)$/', (string) $last->invoice_number, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        $prefix = strtoupper((string) (Business::whereKey($sale->business_id)->value('invoice_prefix') ?: 'FAC'));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?: 'FAC';

        $sale->update(['invoice_number' => sprintf('%s-%06d', $prefix, $next)]);
    }
}
