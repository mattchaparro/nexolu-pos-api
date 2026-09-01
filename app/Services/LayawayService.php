<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawayPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\PaymentRefunder;
use App\Support\ProductAvailability;
use App\Support\SaleLineUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apartados: el cliente reserva productos y los paga de a poco antes de
 * llevarselos. A diferencia de una venta, no genera un Sale - la deuda vive
 * enteramente en LayawayPayment, y el stock se reserva (sale del disponible)
 * desde que se crea el apartado, no hasta que se completa el pago.
 */
class LayawayService
{
    public function __construct(private StockService $stockService) {}

    public function create(User $user, array $data): Layaway
    {
        return DB::transaction(function () use ($user, $data) {
            $business = $user->business;

            $layaway = Layaway::create([
                'business_id' => $business->id,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            $this->applyItems($user, $layaway, $business, $data['items']);

            if (! empty($data['initial_payment']) && (float) $data['initial_payment'] > 0) {
                $this->addPayment($user, $layaway, [
                    'amount' => $data['initial_payment'],
                    'payment_method' => $data['initial_payment_method'] ?? $business->resolveCashPaymentMethodId(),
                    'notes' => null,
                ]);
            }

            return $layaway->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'payments');
        });
    }

    /**
     * Crea los LayawayItem de $items sobre $layaway y reserva stock, bajo
     * lockForUpdate (mismo cuidado que SaleService::applyItems - el legacy no
     * validaba stock disponible al crear un apartado). Para un producto con
     * receta, reserva cada ingrediente en vez de products.stock - mismo
     * retrofit que SaleService::applyItems/OpenTabService::syncItems.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price?: float|int|string|null}>  $items
     */
    private function applyItems(User $user, Layaway $layaway, Business $business, array $items): void
    {
        $ingredientsEnabled = $business->hasFeature('ingredients');
        $variantsEnabled = $business->hasFeature('variants');

        $productIds = collect($items)->pluck('product_id')->unique()->values()->all();
        $products = Product::where('business_id', $business->id)
            ->whereIn('id', $productIds)
            ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients'))
            ->when($variantsEnabled, fn ($query) => $query->with('variants'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variantIds = collect($items)->pluck('product_variant_id')->filter()->unique()->values()->all();
        $variants = $variantsEnabled && $variantIds !== []
            ? ProductVariant::where('business_id', $business->id)->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id')
            : collect();

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $quantity = (int) $item['quantity'];

            $variantId = $item['product_variant_id'] ?? null;
            $variant = $variantId ? $variants->get($variantId) : null;

            // Re-verificacion bajo lock, mismo criterio que SaleService::applyItems().
            if ($variantsEnabled && $product->hasVariants() && ! $variant) {
                throw ValidationException::withMessages([
                    'items' => 'Selecciona una variante para «'.$product->name.'».',
                ]);
            }
            if ($variant && ((int) $variant->product_id !== $product->id || ! $variant->is_active)) {
                throw ValidationException::withMessages([
                    'items' => 'Variante inválida para «'.$product->name.'».',
                ]);
            }

            $availableStock = $variant
                ? ProductAvailability::effectiveVariantStock($variant)
                : ProductAvailability::effectiveStock($product, $ingredientsEnabled, $variantsEnabled);
            $trackStock = $variant ? true : $product->track_stock;

            if ($trackStock && $quantity > $availableStock) {
                throw ValidationException::withMessages([
                    'items' => 'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $availableStock.').',
                ]);
            }

            LayawayItem::create([
                'layaway_id' => $layaway->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'unit_price' => SaleLineUnitPrice::resolve($product, $item, $variant),
            ]);

            if ($variant) {
                $this->stockService->reserveVariantForLayaway($user, $variant, $quantity, $layaway);

                // Ajuste en memoria, mismo motivo que el resto de esta clase.
                $variant->stock -= $quantity;
            } elseif ($product->track_stock) {
                $this->stockService->reserveForLayaway($user, $product, $quantity, $layaway);
                $this->stockService->reserveIngredientsForLayaway($user, $product, $quantity, $layaway);

                // Ajuste en memoria (el movimiento ya persistio el cambio real en
                // BD): ver la nota equivalente en SaleService::applyItems.
                if ($product->isStockManagedByIngredientsRecipe()) {
                    foreach ($product->ingredients as $ingredient) {
                        $ingredient->stock -= (float) $ingredient->pivot->quantity * $quantity;
                    }
                } else {
                    $product->stock -= $quantity;
                }
            }
        }
    }

    public function addPayment(User $user, Layaway $layaway, array $paymentData): LayawayPayment
    {
        return DB::transaction(function () use ($user, $layaway, $paymentData) {
            $layaway->loadMissing('items', 'payments');

            if ($layaway->status !== 'open') {
                throw ValidationException::withMessages([
                    'layaway' => 'Solo se pueden registrar abonos en apartados abiertos.',
                ]);
            }

            $method = strtolower(trim((string) ($paymentData['payment_method'] ?? $user->business->resolveCashPaymentMethodId())));
            // No tiene sentido "pagar" un apartado con fiado - moveria la
            // deuda de un lado a otro en vez de cobrarla.
            $user->business->assertValidPaymentMethod($method, forbidCredit: true);

            // Topar al saldo real: sin esto, abonar mas de lo que se debe deja
            // balance negativo y completa el apartado igual (mismo bug que
            // encontramos ya corregido en el legacy - auditoria #08).
            $amount = min((float) $paymentData['amount'], (float) $layaway->balance);
            if ($amount <= 0.009) {
                throw ValidationException::withMessages([
                    'amount' => 'El apartado ya está saldado.',
                ]);
            }

            $payment = LayawayPayment::create([
                'layaway_id' => $layaway->id,
                'business_id' => $layaway->business_id,
                'amount' => $amount,
                'payment_method' => $method,
                'notes' => $paymentData['notes'] ?? null,
                'recorded_by_user_id' => $user->id,
            ]);

            $layaway->load('items', 'payments');
            if ($layaway->balance <= 0.009) {
                $this->complete($layaway);
            }

            return $payment;
        });
    }

    public function cancel(User $user, Layaway $layaway): void
    {
        DB::transaction(function () use ($user, $layaway) {
            if ($layaway->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'layaway' => 'El apartado ya esta cancelado.',
                ]);
            }

            $ingredientsEnabled = $layaway->business->hasFeature('ingredients');
            $layaway->loadMissing([
                $ingredientsEnabled ? 'items.product.ingredients' : 'items.product',
                'items.productVariant',
            ]);

            foreach ($layaway->items as $item) {
                if ($item->productVariant) {
                    $this->stockService->releaseVariantLayawayReservation(
                        $user, $item->productVariant, (int) $item->quantity, $layaway, 'Cancelacion del apartado'
                    );

                    continue;
                }

                $product = $item->product;
                if ($product) {
                    $this->stockService->releaseLayawayReservation(
                        $user, $product, (int) $item->quantity, $layaway, 'Cancelacion del apartado'
                    );
                    $this->stockService->releaseIngredientsLayawayReservation(
                        $user, $product, (int) $item->quantity, $layaway, 'Cancelacion del apartado'
                    );
                }
            }

            $this->refundPayments($user, $layaway, 'Reembolso por cancelación del apartado');

            $layaway->update([
                'status' => 'cancelled',
                'cancelled_by_user_id' => $user->id,
                'cancelled_at' => now(),
            ]);
        });
    }

    /**
     * Registra un reembolso fechado HOY por cada método de pago con el que se
     * abonó, sin borrar ni tocar los abonos originales - ver PaymentRefunder
     * para el porque (mismo patron que ServiceOrder).
     */
    private function refundPayments(User $user, Layaway $layaway, string $reason): void
    {
        PaymentRefunder::refundGroupedByMethod($layaway->payments(), fn (string $method, float $total) => [
            'layaway_id' => $layaway->id,
            'business_id' => $layaway->business_id,
            'amount' => -$total,
            'payment_method' => $method,
            'notes' => $reason,
            'recorded_by_user_id' => $user->id,
        ]);
    }

    public function complete(Layaway $layaway): void
    {
        if ($layaway->status !== 'open') {
            throw ValidationException::withMessages([
                'layaway' => 'Solo se pueden completar apartados abiertos.',
            ]);
        }

        $layaway->update(['status' => 'completed']);
    }

    /**
     * Reemplaza el carrito completo de un apartado. Ajusta el stock por la
     * DIFERENCIA (delta) entre lo que habia y lo que se pide - mismo patron
     * que OpenTabService::syncItems, en vez del reverso-total-y-recrear que
     * hacia el legacy (movia stock de mas incluso si una sola linea no
     * cambiaba). Permitido en 'open' o 'completed' (no en 'cancelled') -
     * editar un apartado ya completado puede reabrirlo si el nuevo total deja
     * saldo pendiente, igual que en el legacy.
     */
    public function updateItems(User $user, Layaway $layaway, array $items): Layaway
    {
        if ($layaway->status === 'cancelled') {
            throw ValidationException::withMessages([
                'layaway' => 'No se pueden editar apartados cancelados.',
            ]);
        }

        return DB::transaction(function () use ($user, $layaway, $items) {
            $business = $layaway->business;
            $ingredientsEnabled = $business->hasFeature('ingredients');
            $variantsEnabled = $business->hasFeature('variants');
            $layaway->loadMissing('items');

            // Clave compuesta product_id:product_variant_id - ver el mismo
            // criterio en OpenTabService::syncItems().
            $lineKey = fn (int $productId, ?int $variantId) => $productId.':'.($variantId ?? 0);

            $currentQtyByLine = $layaway->items
                ->groupBy(fn (LayawayItem $item) => $lineKey((int) $item->product_id, $item->product_variant_id))
                ->map(fn ($group) => (int) $group->sum('quantity'));

            $desiredQtyByLine = collect($items)
                ->filter(fn ($item) => (int) ($item['product_id'] ?? 0) > 0)
                ->groupBy(fn ($item) => $lineKey((int) $item['product_id'], ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null))
                ->map(fn ($group) => (int) $group->sum(fn ($row) => (int) ($row['quantity'] ?? 0)))
                ->filter(fn ($qty) => $qty > 0);

            if ($desiredQtyByLine->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'El apartado debe tener al menos un producto.',
                ]);
            }

            $lineKeys = $currentQtyByLine->keys()->merge($desiredQtyByLine->keys())->unique()->values();
            $productIds = $lineKeys->map(fn (string $key) => (int) explode(':', $key)[0])->unique()->values();
            $variantIds = $lineKeys->map(fn (string $key) => (int) explode(':', $key)[1])->filter()->unique()->values();

            $products = Product::where('business_id', $business->id)
                ->whereIn('id', $productIds)
                ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variants = $variantsEnabled && $variantIds->isNotEmpty()
                ? ProductVariant::where('business_id', $business->id)->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id')
                : collect();

            foreach ($lineKeys as $key) {
                [$productId, $variantId] = array_map('intval', explode(':', $key));
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }

                $variant = $variantId ? $variants->get($variantId) : null;
                $delta = (int) ($desiredQtyByLine[$key] ?? 0) - (int) ($currentQtyByLine[$key] ?? 0);
                $trackStock = $variant ? true : $product->track_stock;
                if ($delta === 0 || ! $trackStock) {
                    continue;
                }

                if ($delta > 0) {
                    $availableStock = $variant
                        ? ProductAvailability::effectiveVariantStock($variant)
                        : ProductAvailability::effectiveStock($product, $ingredientsEnabled, $variantsEnabled);
                    if ($delta > $availableStock) {
                        throw ValidationException::withMessages([
                            'items' => 'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $availableStock.').',
                        ]);
                    }
                    if ($variant) {
                        $this->stockService->reserveVariantForLayaway($user, $variant, $delta, $layaway);
                    } else {
                        $this->stockService->reserveForLayaway($user, $product, $delta, $layaway);
                        $this->stockService->reserveIngredientsForLayaway($user, $product, $delta, $layaway);
                    }
                } else {
                    if ($variant) {
                        $this->stockService->releaseVariantLayawayReservation(
                            $user, $variant, abs($delta), $layaway, 'Reduccion de cantidad al editar apartado'
                        );
                    } else {
                        $this->stockService->releaseLayawayReservation(
                            $user, $product, abs($delta), $layaway, 'Reduccion de cantidad al editar apartado'
                        );
                        $this->stockService->releaseIngredientsLayawayReservation(
                            $user, $product, abs($delta), $layaway, 'Reduccion de cantidad al editar apartado'
                        );
                    }
                }
            }

            $layaway->items()->delete();

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
                $variant = $variantId ? $variants->get($variantId) : null;

                LayawayItem::create([
                    'layaway_id' => $layaway->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $quantity,
                    'unit_price' => SaleLineUnitPrice::resolve($products->get($productId), $item, $variant),
                ]);
            }

            $layaway->load('items', 'payments');
            $layaway->update(['status' => $layaway->balance <= 0.009 ? 'completed' : 'open']);

            return $layaway->fresh()->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'payments');
        });
    }
}
