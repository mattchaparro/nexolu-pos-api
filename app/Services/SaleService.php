<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\SaleLineUnitPrice;
use Illuminate\Support\Facades\DB;

/**
 * Solo cubre el flujo de venta directa (mostrador). Cuentas abiertas, pagos
 * mixtos, fiados (Receivable) y comandera son modulos aparte que todavia no
 * existen en esta API - ver la nota en app/Models/Sale.php.
 */
class SaleService
{
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

            $discountsEnabled = $business->hasFeature('discounts');
            $total = 0.0;

            $productIds = collect($data['items'])->pluck('product_id')->unique()->values()->all();
            $products = Product::where('business_id', $business->id)->whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = SaleLineUnitPrice::resolve($product, $item);
                $lineSubtotal = $unitPrice * $quantity;

                [$discountId, $discountAmount] = $this->resolveItemDiscount(
                    $business, $discountsEnabled, $item['discount_id'] ?? null, $lineSubtotal
                );

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost_at_sale' => (float) $product->cost_price,
                    'subtotal' => $lineSubtotal,
                    'discount_id' => $discountId,
                    'discount_amount' => $discountAmount,
                ]);

                if ($product->track_stock) {
                    $product->decreaseStock($quantity);
                }

                $total += $lineSubtotal - $discountAmount;
            }

            $cartDiscountId = null;
            $cartDiscountAmount = 0.0;
            if ($discountsEnabled && ! empty($data['cart_discount_id'])) {
                $cartDiscount = Discount::where('business_id', $business->id)
                    ->where('scope', 'cart')
                    ->where('is_active', true)
                    ->find((int) $data['cart_discount_id']);
                if ($cartDiscount) {
                    $cartDiscountId = $cartDiscount->id;
                    $cartDiscountAmount = $cartDiscount->computeAmount($total);
                    $total -= $cartDiscountAmount;
                }
            }

            $chargesEnabled = $business->hasFeature('charges');
            $serviceChargeAmount = $chargesEnabled ? (float) ($data['service_charge_amount'] ?? 0) : 0.0;
            $ipoconsumoAmount = $chargesEnabled ? (float) ($data['ipoconsumo_amount'] ?? 0) : 0.0;

            $sale->update([
                'total' => $total + $flags['delivery_fee'] + $serviceChargeAmount + $ipoconsumoAmount,
                'cart_discount_id' => $cartDiscountId,
                'cart_discount_amount' => $cartDiscountAmount,
                'service_charge_amount' => $serviceChargeAmount,
                'ipoconsumo_amount' => $ipoconsumoAmount,
            ]);

            $this->ensureInvoiceNumber($sale);

            return $sale->load('items.product', 'cartDiscount');
        });
    }

    /**
     * Reversa (anula) una venta cerrada: restaura el stock de cada item y
     * elimina la venta. Las ventas no tienen soft deletes - es un borrado
     * definitivo, igual que en el legacy.
     */
    public function reverseSale(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $sale->loadMissing('items.product');

            foreach ($sale->items as $item) {
                $product = $item->product;
                if ($product && $item->quantity > 0 && $product->track_stock) {
                    $product->increaseStock((int) $item->quantity);
                }
            }

            $sale->delete();
        });
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
     * @return array{0: ?int, 1: float}
     */
    private function resolveItemDiscount(Business $business, bool $discountsEnabled, ?int $discountId, float $lineSubtotal): array
    {
        if (! $discountsEnabled || ! $discountId) {
            return [null, 0.0];
        }

        $discount = Discount::where('business_id', $business->id)
            ->where('scope', 'item')
            ->where('is_active', true)
            ->find($discountId);

        if (! $discount) {
            return [null, 0.0];
        }

        return [$discount->id, $discount->computeAmount($lineSubtotal)];
    }

    private function ensureInvoiceNumber(Sale $sale): void
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
