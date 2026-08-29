<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\Reminder;
use App\Models\User;
use App\Support\WeightedAverageCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de compras a proveedores: cada linea entra al inventario (via
 * StockService::registerPurchase/registerIngredientPurchase, con
 * purchase_line_id para trazabilidad) y actualiza el costo promedio
 * ponderado del producto o ingrediente. Cada linea es de producto O de
 * ingrediente, nunca ambos - mismas columnas mutuamente excluyentes que
 * StockMovement (ver StorePurchaseRequest para la validacion de esa regla).
 */
class PurchaseService
{
    public function __construct(private StockService $stockService) {}

    /**
     * @param  array{supplier_id?: ?int, purchased_at: string, invoice_number?: ?string, notes?: ?string, is_credit?: bool, create_expense?: bool, expense_payment_method?: ?string, lines: array<int, array{product_id?: ?int, ingredient_id?: ?int, quantity: float|int, line_total_cop: float|int, notes?: ?string}>}  $data
     */
    public function registerPurchase(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data) {
            $business = $user->business;
            $lines = $data['lines'];

            // Cuentas por pagar: registrar una compra significa que el
            // inventario YA entro - el caso normal es que YA esta pagada. El
            // credito es la EXCEPCION y se marca explicito con is_credit; no
            // se infiere de create_expense (esa es una decision de
            // contabilidad - "que esto aparezca en Gastos" - sin relacion con
            // si ya se pago o no. Bug ya corregido en el legacy, preservado
            // aqui: confundir las dos cosas dejaba compras "pagadas" en Gastos
            // sin estarlo de verdad, o viceversa).
            $isPaidUpfront = empty($data['is_credit']);

            $purchase = Purchase::create([
                'business_id' => $business->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchased_at' => $data['purchased_at'],
                'invoice_number' => $data['invoice_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $user->id,
                'payment_status' => $isPaidUpfront ? 'paid' : 'pending',
                'paid_at' => $isPaidUpfront ? $data['purchased_at'] : null,
            ]);

            $variantsEnabled = (bool) $business->hasFeature('variants');

            $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->all();
            $products = Product::where('business_id', $business->id)
                ->whereIn('id', $productIds)
                ->with('ingredients:id')
                ->when($variantsEnabled, fn ($q) => $q->with('variants'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $ingredientIds = collect($lines)->pluck('ingredient_id')->filter()->unique()->values()->all();
            $ingredients = Ingredient::where('business_id', $business->id)
                ->whereIn('id', $ingredientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantIds = collect($lines)->pluck('product_variant_id')->filter()->unique()->values()->all();
            $variants = $variantsEnabled && $variantIds !== []
                ? ProductVariant::where('business_id', $business->id)->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id')
                : collect();

            // Stock acumulado DENTRO de esta compra, por producto/variante/
            // ingrediente - aparte del atributo Eloquent para que una segunda
            // linea del mismo item vea el stock ya sumado por la primera al
            // calcular el costo promedio, sin arriesgarse a que ese valor
            // viaje "de colado" en un update() posterior y pise el
            // incremento real que el StockMovement ya aplico en la BD por
            // SQL directo.
            $productStockTracker = [];
            $variantStockTracker = [];
            $ingredientStockTracker = [];
            /** @var Collection<int, Ingredient> $ingredientsToSync */
            $ingredientsToSync = collect();

            foreach ($lines as $i => $row) {
                $lineTotal = round((float) $row['line_total_cop'], 2);

                if (! empty($row['product_id'])) {
                    $product = $products->get((int) $row['product_id']);
                    if (! $product) {
                        throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Producto no encontrado.']);
                    }

                    if ($variantsEnabled && $product->hasVariants()) {
                        $variantStockTracker = $this->applyVariantLine(
                            $user, $purchase, $product, $variants, $row, $lineTotal, $i, $variantStockTracker
                        );
                    } else {
                        $productStockTracker = $this->applyProductLine(
                            $user, $purchase, $products, (int) $row['product_id'], $row, $lineTotal, $i, $productStockTracker
                        );
                    }
                } else {
                    [$ingredientStockTracker, $ingredient] = $this->applyIngredientLine(
                        $user, $purchase, $ingredients, (int) ($row['ingredient_id'] ?? 0), $row, $lineTotal, $i, $ingredientStockTracker
                    );
                    $ingredientsToSync->put($ingredient->id, $ingredient);
                }
            }

            // Propagar el costo a los productos con receta DESPUES del loop,
            // una vez por ingrediente tocado - no por cada linea (evita
            // resincronizar el mismo producto N veces si la compra trae
            // varias lineas del mismo insumo).
            $ingredientsToSync->each(fn (Ingredient $ingredient) => $ingredient->syncLinkedProductCosts());

            if (! empty($data['create_expense'])) {
                $total = round(collect($lines)->sum(fn ($l) => (float) $l['line_total_cop']), 2);
                $this->createExpenseForPurchase(
                    $business->id, $purchase, $total,
                    $data['expense_payment_method'] ?? Expense::PAYMENT_METHODS[0],
                    (string) $data['purchased_at'],
                );
            }

            return $purchase->load('lines.product', 'lines.productVariant.attributeValues.productAttribute', 'lines.ingredient', 'supplier');
        });
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array{quantity: float|int, notes?: ?string}  $row
     * @return array<int, float>
     */
    private function applyProductLine(User $user, Purchase $purchase, Collection $products, int $productId, array $row, float $lineTotal, int $i, array $stockTracker): array
    {
        $product = $products->get($productId);
        if (! $product) {
            throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Producto no encontrado.']);
        }
        if ($product->is_single_sale) {
            throw ValidationException::withMessages(["lines.{$i}.product_id" => 'No se compra stock de artículos de venta única: «'.$product->name.'».']);
        }
        if ($product->is_service) {
            throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Un servicio no se compra como inventario: «'.$product->name.'».']);
        }
        if ($product->track_stock && ! $product->is_service && $product->ingredients->isNotEmpty()) {
            throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Este producto usa inventario por receta (insumos): compra los ingredientes, no «'.$product->name.'».']);
        }

        $quantity = (int) round((float) $row['quantity']);
        $unitCost = round($lineTotal / $quantity, 4);

        $line = PurchaseLine::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'line_total_cop' => $lineTotal,
            'unit_cost_cop' => $unitCost,
            'notes' => $row['notes'] ?? null,
        ]);

        $this->stockService->registerPurchase($user, $product, $quantity, $line, $unitCost);

        $prevStock = $stockTracker[$product->id] ?? (float) $product->stock;
        $prevCost = (float) $product->cost_price;
        $newCost = WeightedAverageCost::calculate($prevStock, $prevCost, $quantity, $unitCost);

        $product->update(['cost_price' => $newCost]);
        $stockTracker[$product->id] = $prevStock + $quantity;

        ProductCostHistory::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'cost_before' => $prevCost,
            'cost_after' => $newCost,
            'source' => ProductCostHistory::SOURCE_PURCHASE,
            'purchase_id' => $purchase->id,
            'user_id' => $user->id,
        ]);

        return $stockTracker;
    }

    /**
     * Contraparte de applyProductLine() cuando el producto elegido tiene
     * variantes (Product::hasVariants()): la compra se aplica sobre LA
     * VARIANTE - su propio stock y costo promedio ponderado, nunca sobre
     * products.stock/cost_price (columnas "fantasma" para un producto con
     * variantes, mismo concepto que ya existe para receta - ver
     * ProductAvailability). A diferencia de applyProductLine(), no se
     * registra ProductCostHistory: esa tabla no tiene product_variant_id y
     * hoy no la consume ninguna pantalla (ver Fase 1 "productos con
     * variaciones") - agregar esa columna queda para cuando haga falta de
     * verdad.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array{quantity: float|int, product_variant_id?: ?int, notes?: ?string}  $row
     * @return array<int, float>
     */
    private function applyVariantLine(User $user, Purchase $purchase, Product $product, Collection $variants, array $row, float $lineTotal, int $i, array $stockTracker): array
    {
        $variantId = (int) ($row['product_variant_id'] ?? 0);
        $variant = $variantId ? $variants->get($variantId) : null;
        if (! $variant || $variant->product_id !== $product->id) {
            throw ValidationException::withMessages(["lines.{$i}.product_variant_id" => 'Selecciona una variante para «'.$product->name.'».']);
        }

        $quantity = (int) round((float) $row['quantity']);
        $unitCost = round($lineTotal / $quantity, 4);

        $line = PurchaseLine::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'line_total_cop' => $lineTotal,
            'unit_cost_cop' => $unitCost,
            'notes' => $row['notes'] ?? null,
        ]);

        $this->stockService->registerVariantPurchase($user, $variant, $quantity, $line, $unitCost);

        $prevStock = $stockTracker[$variant->id] ?? (float) $variant->stock;
        $prevCost = (float) $variant->cost_price;
        $newCost = WeightedAverageCost::calculate($prevStock, $prevCost, $quantity, $unitCost);

        $variant->update(['cost_price' => $newCost]);
        $stockTracker[$variant->id] = $prevStock + $quantity;

        return $stockTracker;
    }

    /**
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  array{quantity: float|int, notes?: ?string}  $row
     * @return array{0: array<int, float>, 1: Ingredient}
     */
    private function applyIngredientLine(User $user, Purchase $purchase, Collection $ingredients, int $ingredientId, array $row, float $lineTotal, int $i, array $stockTracker): array
    {
        $ingredient = $ingredients->get($ingredientId);
        if (! $ingredient) {
            throw ValidationException::withMessages(["lines.{$i}.ingredient_id" => 'Ingrediente no encontrado.']);
        }

        $quantity = round((float) $row['quantity'], 4);
        $unitCost = round($lineTotal / $quantity, 4);

        $line = PurchaseLine::create([
            'purchase_id' => $purchase->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => $quantity,
            'line_total_cop' => $lineTotal,
            'unit_cost_cop' => $unitCost,
            'notes' => $row['notes'] ?? null,
        ]);

        $this->stockService->registerIngredientPurchase($user, $ingredient, $quantity, $line, $unitCost);

        $prevStock = $stockTracker[$ingredient->id] ?? (float) $ingredient->stock;
        $prevCost = (float) $ingredient->cost_price;
        $newCost = WeightedAverageCost::calculate($prevStock, $prevCost, $quantity, $unitCost);

        $ingredient->update(['cost_price' => $newCost]);
        $stockTracker[$ingredient->id] = $prevStock + $quantity;

        return [$stockTracker, $ingredient];
    }

    /**
     * Abono libre contra una compra a credito (sin plan de cuotas: se abona
     * lo que sea, cuando sea, contra el saldo). Cada abono genera SU PROPIO
     * gasto, fechado HOY: es la fecha real en que el dinero salio del
     * negocio. Sumarlo todo en un solo gasto al momento del ultimo abono
     * desalinearia el cierre de caja de los dias anteriores en que si hubo
     * abonos - mismo cuidado que con los pagos de apartados/servicios.
     */
    public function pay(User $user, Purchase $purchase, float $amount, string $paymentMethod): PurchasePayment
    {
        if ($purchase->payment_status === 'paid') {
            throw ValidationException::withMessages(['purchase' => 'Esta compra ya está pagada.']);
        }

        return DB::transaction(function () use ($user, $purchase, $amount, $paymentMethod) {
            $business = $user->business;
            $method = strtolower(trim($paymentMethod));
            // Igual que en Apartados/Servicios: no tiene sentido "pagar" una
            // compra con fiado, eso solo mueve la deuda de un lado a otro.
            $business->assertValidPaymentMethod($method, forbidCredit: true);

            $purchase->loadMissing('lines', 'payments');
            $amount = min($amount, $purchase->balance);
            if ($amount <= 0.009) {
                throw ValidationException::withMessages(['amount' => 'Esta compra ya está saldada.']);
            }

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'business_id' => $purchase->business_id,
                'amount' => $amount,
                'payment_method' => $method,
                'recorded_by_user_id' => $user->id,
            ]);

            $this->createExpenseForPurchase(
                $purchase->business_id, $purchase, $amount,
                Expense::labelForPaymentMethodId($method),
                now()->toDateString(),
            );

            $purchase->load('payments');
            if ($purchase->balance <= 0.009) {
                $purchase->update(['payment_status' => 'paid', 'paid_at' => now()]);

                // Ya se pago: el recordatorio de pago que se haya creado al
                // registrarla a credito (ver PurchaseController::store()) ya
                // no tiene nada que recordar.
                $purchase->reminders()->where('status', Reminder::STATUS_PENDING)->delete();
            }

            return $payment;
        });
    }

    private function createExpenseForPurchase(int $businessId, Purchase $purchase, float $total, string $paymentMethod, string $date): void
    {
        $typeId = ExpenseType::where('business_id', $businessId)
            ->whereIn('name', ['Insumos', 'insumos', 'Compras', 'compras'])
            ->value('id');

        $description = 'Compra de insumos';
        if ($purchase->invoice_number) {
            $description .= ' — Factura #'.$purchase->invoice_number;
        } elseif ($purchase->notes) {
            $description .= ': '.$purchase->notes;
        }

        Expense::create([
            'business_id' => $businessId,
            'date' => $date,
            'description' => $description,
            'value' => $total,
            'payment_method' => $paymentMethod,
            'type_id' => $typeId,
            'linkable_type' => Purchase::class,
            'linkable_id' => $purchase->id,
        ]);
    }
}
