<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\User;
use App\Support\WeightedAverageCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de compras a proveedores: cada linea entra al inventario (via
 * StockService::registerPurchase, con purchase_line_id para trazabilidad) y
 * actualiza el costo promedio ponderado del producto. Solo lineas de
 * producto por ahora - purchase_lines.ingredient_id existe en el schema
 * compartido (igual que en legacy) pero esta clase todavia no lo admite;
 * mientras tanto, una entrada de stock de ingrediente se registra por
 * separado via StockService::ingredientEntry() (ver IngredientStockMovementController).
 */
class PurchaseService
{
    public function __construct(private StockService $stockService) {}

    /**
     * @param  array{supplier_id?: ?int, purchased_at: string, invoice_number?: ?string, notes?: ?string, is_credit?: bool, create_expense?: bool, expense_payment_method?: ?string, lines: array<int, array{product_id: int, quantity: int, line_total_cop: float|int, notes?: ?string}>}  $data
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

            $productIds = collect($lines)->pluck('product_id')->unique()->values()->all();
            $products = Product::where('business_id', $business->id)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Stock acumulado DENTRO de esta compra, por producto - aparte del
            // atributo Eloquent para que una segunda linea del mismo producto
            // vea el stock ya sumado por la primera al calcular el costo
            // promedio, sin arriesgarse a que ese valor viaje "de colado" en
            // un update() posterior y pise el incremento real que el
            // StockMovement ya aplico en la BD por SQL directo.
            $stockTracker = [];

            foreach ($lines as $i => $row) {
                $product = $products->get($row['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Producto no encontrado.']);
                }
                if ($product->is_single_sale) {
                    throw ValidationException::withMessages(["lines.{$i}.product_id" => 'No se compra stock de artículos de venta única: «'.$product->name.'».']);
                }

                $quantity = (int) $row['quantity'];
                $lineTotal = round((float) $row['line_total_cop'], 2);
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
                    'business_id' => $business->id,
                    'product_id' => $product->id,
                    'cost_before' => $prevCost,
                    'cost_after' => $newCost,
                    'source' => ProductCostHistory::SOURCE_PURCHASE,
                    'purchase_id' => $purchase->id,
                    'user_id' => $user->id,
                ]);
            }

            if (! empty($data['create_expense'])) {
                $total = round(collect($lines)->sum(fn ($l) => (float) $l['line_total_cop']), 2);
                $this->createExpenseForPurchase(
                    $business->id, $purchase, $total,
                    $data['expense_payment_method'] ?? Expense::PAYMENT_METHODS[0],
                    (string) $data['purchased_at'],
                );
            }

            return $purchase->load('lines.product', 'supplier');
        });
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
