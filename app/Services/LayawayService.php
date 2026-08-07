<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawayPayment;
use App\Models\Product;
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

            return $layaway->load('items.product', 'payments');
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

            LayawayItem::create([
                'layaway_id' => $layaway->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => SaleLineUnitPrice::resolve($product, $item),
            ]);

            if ($product->track_stock) {
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
            $layaway->loadMissing($ingredientsEnabled ? 'items.product.ingredients' : 'items.product');

            foreach ($layaway->items as $item) {
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
            $layaway->loadMissing('items');

            $currentQtyByProduct = $layaway->items
                ->groupBy('product_id')
                ->map(fn ($group) => (int) $group->sum('quantity'));

            $desiredQtyByProduct = collect($items)
                ->filter(fn ($item) => (int) ($item['product_id'] ?? 0) > 0)
                ->groupBy(fn ($item) => (int) $item['product_id'])
                ->map(fn ($group) => (int) $group->sum(fn ($row) => (int) ($row['quantity'] ?? 0)))
                ->filter(fn ($qty) => $qty > 0);

            if ($desiredQtyByProduct->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'El apartado debe tener al menos un producto.',
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
                    $this->stockService->reserveForLayaway($user, $product, $delta, $layaway);
                    $this->stockService->reserveIngredientsForLayaway($user, $product, $delta, $layaway);
                } else {
                    $this->stockService->releaseLayawayReservation(
                        $user, $product, abs($delta), $layaway, 'Reduccion de cantidad al editar apartado'
                    );
                    $this->stockService->releaseIngredientsLayawayReservation(
                        $user, $product, abs($delta), $layaway, 'Reduccion de cantidad al editar apartado'
                    );
                }
            }

            $layaway->items()->delete();

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                LayawayItem::create([
                    'layaway_id' => $layaway->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => SaleLineUnitPrice::resolve($products->get($productId), $item),
                ]);
            }

            $layaway->load('items', 'payments');
            $layaway->update(['status' => $layaway->balance <= 0.009 ? 'completed' : 'open']);

            return $layaway->fresh()->load('items.product', 'payments');
        });
    }
}
