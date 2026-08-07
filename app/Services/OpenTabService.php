<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePartialPayment;
use App\Models\SalePaymentSplit;
use App\Models\User;
use App\Support\ProductAvailability;
use App\Support\SaleLineUnitPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una cuenta abierta (mesa/tab): abrir, agregar/sincronizar
 * items, abonar de a poco, cerrar (medio unico, dividido, o completada por
 * abonos) y cancelar. Complementa a SaleService, que solo cubre la venta
 * directa - ver la nota en app/Models/Sale.php.
 */
class OpenTabService
{
    public function __construct(private SaleService $saleService, private StockService $stockService) {}

    public function openTab(User $user, array $data): Sale
    {
        return DB::transaction(function () use ($user, $data) {
            $business = $user->business;
            $isDelivery = (bool) $business->delivery_enabled && (bool) ($data['is_delivery'] ?? false);
            $deliveryFee = $isDelivery ? (float) $business->delivery_fee : 0.0;

            $sale = Sale::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'payment_method' => null,
                'total' => 0,
                'status' => 'open',
                'table_id' => $data['table_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_identification' => $data['customer_identification'] ?? null,
                'is_delivery' => $isDelivery,
                'delivery_fee' => $deliveryFee,
                'kitchen_status' => 'pending',
                'kitchen_updated_at' => now(),
            ]);

            $total = $this->saleService->applyItems($user, $sale, $business, $data['items']);

            [$cartDiscountId, $cartDiscountAmount, $total] = $this->saleService->applyCartDiscount(
                $business, $total, $data['cart_discount_id'] ?? null
            );

            $sale->update([
                'total' => $total + $deliveryFee,
                'cart_discount_id' => $cartDiscountId,
                'cart_discount_amount' => $cartDiscountAmount,
            ]);

            // load(), no fresh(): fresh() trae una instancia nueva de la BD y
            // pierde wasRecentlyCreated, con lo que el controller devolveria
            // 200 en vez de 201 al crear (JsonResource infiere el status del
            // modelo). Mismo cuidado que ya tuvimos en SaleService::createSale.
            return $sale->load('items.product', 'table');
        });
    }

    /**
     * Agrega items a una cuenta abierta sin tocar los que ya tenia.
     */
    public function addItems(User $user, Sale $sale, array $items): Sale
    {
        $this->assertOpen($sale);

        return DB::transaction(function () use ($user, $sale, $items) {
            $addedTotal = $this->saleService->applyItems($user, $sale, $sale->business, $items);

            $sale->increment('total', $addedTotal);
            $sale->update(['kitchen_status' => 'pending', 'kitchen_updated_at' => now()]);

            return $sale->fresh()->load('items.product');
        });
    }

    /**
     * Reemplaza el carrito completo de una cuenta abierta. Ajusta el stock por
     * la DIFERENCIA (delta) entre lo que habia y lo que se pide, no
     * descontando todo de nuevo - evita mover stock de mas si un producto ya
     * estaba en la cuenta con otra cantidad.
     */
    public function syncItems(User $user, Sale $sale, array $items): Sale
    {
        $this->assertOpen($sale);

        return DB::transaction(function () use ($user, $sale, $items) {
            $business = $sale->business;
            $ingredientsEnabled = $business->hasFeature('ingredients');
            $sale->loadMissing('items');

            $currentQtyByProduct = $sale->items
                ->groupBy('product_id')
                ->map(fn ($group) => (int) $group->sum('quantity'));

            $desiredQtyByProduct = collect($items)
                ->filter(fn ($item) => (int) ($item['product_id'] ?? 0) > 0)
                ->groupBy(fn ($item) => (int) $item['product_id'])
                ->map(fn ($group) => (int) $group->sum(fn ($row) => (int) ($row['quantity'] ?? 0)))
                ->filter(fn ($qty) => $qty > 0);

            if ($desiredQtyByProduct->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'La cuenta debe tener al menos un producto.',
                ]);
            }

            $productIds = $currentQtyByProduct->keys()->merge($desiredQtyByProduct->keys())->unique()->values();
            $products = Product::where('business_id', $business->id)
                ->whereIn('id', $productIds)
                ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }

                $delta = (int) ($desiredQtyByProduct[$productId] ?? 0) - (int) ($currentQtyByProduct[$productId] ?? 0);
                if ($delta === 0 || ! $product->track_stock) {
                    continue;
                }

                if ($delta > 0) {
                    $availableStock = ProductAvailability::effectiveStock($product, $ingredientsEnabled);
                    if ($delta > $availableStock) {
                        throw ValidationException::withMessages([
                            'items' => 'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $availableStock.').',
                        ]);
                    }
                    $this->stockService->registerSale($user, $product, $delta, $sale);
                    $this->stockService->registerIngredientsConsumption($user, $product, $delta, $sale);
                } else {
                    $this->stockService->registerSaleReversal(
                        $user, $product, abs($delta), $sale, 'Reduccion de cantidad al sincronizar cuenta abierta'
                    );
                    $this->stockService->restoreIngredientsConsumption(
                        $user, $product, abs($delta), $sale, 'Reduccion de cantidad al sincronizar cuenta abierta'
                    );
                }
            }

            $sale->items()->delete();

            $discountsEnabled = $business->hasFeature('discounts');
            $recalculatedTotal = 0.0;

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $product = $products->get($productId);
                $unitPrice = SaleLineUnitPrice::resolve($product, $item);
                $subtotal = $unitPrice * $quantity;

                [$discountId, $discountAmount] = $discountsEnabled
                    ? Discount::resolveActive($business->id, 'item', $item['discount_id'] ?? null, $subtotal)
                    : [null, 0.0];

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost_at_sale' => (float) $product->cost_price,
                    'subtotal' => $subtotal,
                    'discount_id' => $discountId,
                    'discount_amount' => $discountAmount,
                    'kitchen_status' => 'pending',
                    'kitchen_updated_at' => now(),
                ]);

                $recalculatedTotal += $subtotal - $discountAmount;
            }

            $sale->update([
                'total' => $recalculatedTotal + (float) $sale->delivery_fee,
                'kitchen_status' => 'pending',
                'kitchen_updated_at' => now(),
            ]);

            return $sale->fresh()->load('items.product');
        });
    }

    /**
     * Registra un abono sobre una cuenta abierta. Si el abono completa el
     * total, cierra la cuenta usando los abonos acumulados como el pago.
     */
    public function recordPartialPayment(User $user, Sale $sale, float $amount, string $paymentMethod, ?string $payerLabel = null): Sale
    {
        return DB::transaction(function () use ($user, $sale, $amount, $paymentMethod, $payerLabel) {
            $sale->refresh();
            $this->assertOpen($sale);

            $business = $sale->business;
            $method = strtolower(trim($paymentMethod));
            $this->assertValidPaymentMethod($business, $method, forbidCredit: true);

            $sum = (float) SalePartialPayment::where('sale_id', $sale->id)->sum('amount');
            $remaining = round((float) $sale->total - $sum, 2);
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor a cero.']);
            }
            if ($amount > $remaining + 0.02) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto supera el saldo pendiente ('.number_format($remaining, 0, ',', '.').').',
                ]);
            }

            $label = $payerLabel !== null && $payerLabel !== '' ? mb_substr(trim($payerLabel), 0, 120) : null;

            SalePartialPayment::create([
                'sale_id' => $sale->id,
                'amount' => $amount,
                'payment_method' => $method,
                'payer_label' => $label,
                'user_id' => $user->id,
            ]);

            $newRemaining = round((float) $sale->total - round($sum + $amount, 2), 2);
            if ($newRemaining > 0.02) {
                return $sale->fresh(['partialPayments']);
            }

            // Los abonos ya cubren el total: cerrar automaticamente usandolos como el pago.
            return $this->close($user, $sale, []);
        });
    }

    /**
     * Cierra una cuenta abierta. $data puede traer: payment_method,
     * payment_splits (array de {method, amount, label}), is_non_revenue,
     * non_revenue_reason, customer_name/phone/identification,
     * apply_service_charge, apply_ipoconsumo. Los montos de los cargos los
     * calcula el servidor (ver SaleService::resolveCharges), nunca el cliente.
     */
    public function close(User $user, Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($user, $sale, $data) {
            $sale->refresh();
            $this->assertOpen($sale);

            $business = $sale->business;

            // Base sin el domicilio, igual que SaleService::createSale y el
            // legacy (closeChargeBase en OpenTabs.vue) - $sale->total de una
            // cuenta abierta ya incluye el delivery_fee desde que se abrio
            // (ver openTab()/syncItems() mas abajo), asi que hay que
            // restarlo antes de calcular el cargo o se cobra servicio/
            // ipoconsumo tambien sobre el domicilio.
            $chargeBase = max(0.0, (float) $sale->total - (float) $sale->delivery_fee);
            [$serviceChargeAmount, $ipoconsumoAmount] = $this->saleService->resolveCharges(
                $business, $chargeBase, $data
            );
            $totalWithCharges = round((float) $sale->total + $serviceChargeAmount + $ipoconsumoAmount, 2);

            $partials = SalePartialPayment::where('sale_id', $sale->id)->orderBy('id')->get();
            $partialLines = $this->mapPartialPaymentsToSplitRows($partials);
            $partialSum = round(collect($partialLines)->sum('amount'), 2);
            $remainder = round($totalWithCharges - $partialSum, 2);

            $isNonRevenue = (bool) ($data['is_non_revenue'] ?? false);
            if ($isNonRevenue && $partials->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'is_non_revenue' => 'No puedes cerrar como cortesia si ya hay pagos parciales registrados.',
                ]);
            }

            SalePaymentSplit::where('sale_id', $sale->id)->delete();

            $resolvedPaymentMethod = null;
            $resolvedReason = null;
            $isCredit = false;

            if ($isNonRevenue) {
                $resolvedReason = $data['non_revenue_reason'] ?? 'Cortesia';
            } elseif ($partials->isNotEmpty()) {
                $mixedRows = $remainder > 0.02
                    ? array_merge($partialLines, $this->resolveRemainderPaymentRows(
                        $business, $data['payment_method'] ?? null, $data['payment_splits'] ?? null, $remainder
                    ))
                    : $partialLines;

                $lineCount = count(array_filter($mixedRows, fn ($r) => $r['amount'] > 0.009));
                if ($lineCount >= 2) {
                    $this->saleService->assertValidPaymentSplits($mixedRows, $totalWithCharges);
                    $resolvedPaymentMethod = $this->saleService->applyMixedPaymentRows($business, $sale, $mixedRows);
                } else {
                    $this->assertSingleLineMatchesTotal($mixedRows, $totalWithCharges);
                    $resolvedPaymentMethod = $mixedRows[0]['method'];
                }
                $isCredit = $business->isCreditPaymentMethod($resolvedPaymentMethod);
            } elseif (is_array($data['payment_splits'] ?? null) && count($data['payment_splits']) >= 2) {
                $normalized = $this->saleService->normalizePaymentSplitInput($data['payment_splits']);
                $this->saleService->assertValidPaymentSplits($normalized, $totalWithCharges);
                $resolvedPaymentMethod = $this->saleService->applyMixedPaymentRows($business, $sale, $normalized);
            } elseif (! empty($data['payment_method'])) {
                $method = strtolower(trim((string) $data['payment_method']));
                $this->assertValidPaymentMethod($business, $method);
                $resolvedPaymentMethod = $method;
                $isCredit = $business->isCreditPaymentMethod($method);
            } else {
                throw ValidationException::withMessages([
                    'payment_method' => 'Selecciona un metodo de pago o usa pago dividido con dos o mas medios.',
                ]);
            }

            SalePartialPayment::where('sale_id', $sale->id)->delete();

            $name = trim((string) ($data['customer_name'] ?? ''));
            $phone = trim((string) ($data['customer_phone'] ?? ''));
            $identification = trim((string) ($data['customer_identification'] ?? ''));

            $sale->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $user->id,
                'payment_method' => $resolvedPaymentMethod,
                'is_non_revenue' => $isNonRevenue,
                'non_revenue_reason' => $resolvedReason,
                'is_credit' => $isCredit,
                'customer_name' => $name !== '' ? $name : $sale->customer_name,
                'customer_phone' => $phone !== '' ? $phone : $sale->customer_phone,
                'customer_identification' => $identification !== '' ? $identification : $sale->customer_identification,
                'service_charge_amount' => $serviceChargeAmount,
                'ipoconsumo_amount' => $ipoconsumoAmount,
                'total' => $totalWithCharges,
            ]);

            $fresh = $sale->fresh();
            $this->saleService->ensureInvoiceNumber($fresh);
            $this->saleService->syncReceivable($fresh->fresh());

            return $fresh->fresh()->load('items.product', 'paymentSplits');
        });
    }

    /**
     * Elimina una cuenta abierta: restaura stock y borra la venta. Bloqueada
     * si ya hay abonos registrados (cierrala con el saldo pendiente en su
     * lugar, o contacta al administrador).
     */
    public function cancelOpenTab(User $user, Sale $sale): void
    {
        DB::transaction(function () use ($user, $sale) {
            $sale->refresh();
            $this->assertOpen($sale);

            if ($sale->partialPayments()->exists()) {
                throw ValidationException::withMessages([
                    'sale' => 'No se puede eliminar la cuenta: hay pagos parciales registrados. Cierra la cuenta con el saldo pendiente o contacta al administrador.',
                ]);
            }

            $ingredientsEnabled = $sale->business->hasFeature('ingredients');
            $sale->loadMissing($ingredientsEnabled ? 'items.product.ingredients' : 'items.product');
            foreach ($sale->items as $item) {
                $product = $item->product;
                if ($product && $item->quantity > 0) {
                    $this->stockService->registerSaleReversal(
                        $user, $product, (int) $item->quantity, $sale, 'Cancelacion de cuenta abierta'
                    );
                    $this->stockService->restoreIngredientsConsumption(
                        $user, $product, (int) $item->quantity, $sale, 'Cancelacion de cuenta abierta'
                    );
                }
            }

            $sale->delete();
        });
    }

    private function assertOpen(Sale $sale): void
    {
        if (! $sale->isOpen()) {
            throw ValidationException::withMessages([
                'sale' => 'Esta cuenta ya esta cerrada.',
            ]);
        }
    }

    private function assertValidPaymentMethod(Business $business, string $method, bool $forbidCredit = false): void
    {
        $business->assertValidPaymentMethod($method, $forbidCredit);
    }

    /**
     * @param  Collection<int, SalePartialPayment>  $partials
     * @return array<int, array{method: string, amount: float, label: ?string}>
     */
    private function mapPartialPaymentsToSplitRows($partials): array
    {
        return $partials
            ->map(fn ($p) => [
                'method' => strtolower(trim((string) $p->payment_method)),
                'amount' => round((float) $p->amount, 2),
                'label' => $p->payer_label !== null && $p->payer_label !== '' ? trim((string) $p->payer_label) : null,
            ])
            ->filter(fn ($r) => $r['amount'] > 0.009)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{method: string, amount: float, label: ?string}>
     */
    private function resolveRemainderPaymentRows(Business $business, ?string $paymentMethod, ?array $paymentSplits, float $remainderAmount): array
    {
        if (is_array($paymentSplits) && count($paymentSplits) >= 2) {
            $normalized = $this->saleService->normalizePaymentSplitInput($paymentSplits);
            $this->saleService->assertValidPaymentSplits($normalized, $remainderAmount);

            return $normalized;
        }

        if ($paymentMethod !== null && $paymentMethod !== '') {
            $method = strtolower(trim($paymentMethod));
            $this->assertValidPaymentMethod($business, $method, forbidCredit: true);

            return [['method' => $method, 'amount' => round($remainderAmount, 2), 'label' => null]];
        }

        throw ValidationException::withMessages([
            'payment_method' => 'Indica como se cubre el saldo pendiente o usa pago dividido para el restante.',
        ]);
    }

    /**
     * @param  array<int, array{method: string, amount: float, label?: ?string}>  $rows
     */
    private function assertSingleLineMatchesTotal(array $rows, float $expectedTotal): void
    {
        $filtered = array_values(array_filter($rows, fn ($r) => $r['amount'] > 0.009));
        if (count($filtered) !== 1) {
            throw ValidationException::withMessages([
                'payment_splits' => 'Indica al menos dos lineas de pago con montos mayores a cero.',
            ]);
        }

        if (abs(round($filtered[0]['amount'], 2) - round($expectedTotal, 2)) > 0.02) {
            throw ValidationException::withMessages([
                'payment_splits' => 'El monto debe coincidir con el total de la cuenta.',
            ]);
        }
    }
}
