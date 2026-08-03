<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
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
                'payment_method' => $flags['payment_method'],
                'total' => 0,
                'status' => 'closed',
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_identification' => $data['customer_identification'] ?? null,
                'is_delivery' => $flags['is_delivery'],
                'delivery_fee' => $flags['delivery_fee'],
                'is_non_revenue' => $flags['is_non_revenue'],
                'non_revenue_reason' => $flags['is_non_revenue'] ? ($data['non_revenue_reason'] ?? 'Cortesia') : null,
                'is_credit' => $flags['is_credit'],
            ]);

            $total = $this->applyItems($user, $sale, $business, $data['items']);

            [$cartDiscountId, $cartDiscountAmount, $total] = $this->applyCartDiscount(
                $business, $total, $data['cart_discount_id'] ?? null
            );

            [$serviceChargeAmount, $ipoconsumoAmount] = $this->resolveCharges($business, $total, $data);

            $sale->update([
                'total' => $total + $flags['delivery_fee'] + $serviceChargeAmount + $ipoconsumoAmount,
                'cart_discount_id' => $cartDiscountId,
                'cart_discount_amount' => $cartDiscountAmount,
                'service_charge_amount' => $serviceChargeAmount,
                'ipoconsumo_amount' => $ipoconsumoAmount,
            ]);

            $this->ensureInvoiceNumber($sale);
            $this->syncReceivable($sale->fresh());

            return $sale->load('items.product', 'cartDiscount');
        });
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
            $sale->loadMissing('items.product');

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
     * @return array{is_non_revenue: bool, payment_method: ?string, is_credit: bool, is_delivery: bool, delivery_fee: float}
     */
    private function resolveSaleFlags(Business $business, array $data): array
    {
        $isDelivery = (bool) $business->delivery_enabled && (bool) ($data['is_delivery'] ?? false);
        $deliveryFee = $isDelivery ? (float) $business->delivery_fee : 0.0;

        $isNonRevenue = (bool) ($data['is_non_revenue'] ?? false);
        $paymentMethod = (! $isNonRevenue && isset($data['payment_method']) && $data['payment_method'] !== null)
            ? strtolower(trim((string) $data['payment_method']))
            : null;

        return [
            'is_non_revenue' => $isNonRevenue,
            'payment_method' => $paymentMethod,
            'is_credit' => ! $isNonRevenue && $business->isCreditPaymentMethod($paymentMethod),
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
        $total = 0.0;

        $productIds = collect($items)->pluck('product_id')->unique()->values()->all();
        $products = Product::where('business_id', $business->id)
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];

            if ($product->track_stock && $quantity > $product->stock) {
                throw ValidationException::withMessages([
                    'items' => 'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $product->stock.').',
                ]);
            }

            $unitPrice = SaleLineUnitPrice::resolve($product, $item);
            $lineSubtotal = $unitPrice * $quantity;

            [$discountId, $discountAmount] = $discountsEnabled
                ? Discount::resolveActive($business->id, 'item', $item['discount_id'] ?? null, $lineSubtotal)
                : [null, 0.0];

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

            if ($product->track_stock) {
                $this->stockService->registerSale($user, $product, $quantity, $sale);
                // Ajuste en memoria (el movimiento ya persistio el cambio real en
                // BD): si $items trae dos lineas del mismo producto, la siguiente
                // vuelta del loop debe ver el stock ya descontado, no el que tenia
                // $product al cargarlo bajo lockForUpdate().
                $product->stock -= $quantity;
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
