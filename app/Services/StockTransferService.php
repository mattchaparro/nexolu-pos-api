<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mover inventario de una sede a otra.
 *
 * En v1 el traslado es inmediato: sale de la sede origen y entra a la destino
 * en la misma transaccion. No hay "en transito" - si la camioneta se demora,
 * el sistema ya cuenta la mercancia en destino. Es una simplificacion
 * consciente: ningun negocio de la plataforma tiene hoy varias sedes, y el
 * estado intermedio agrega un flujo de confirmacion (quien recibe, que pasa si
 * llega de menos) que no vale la pena inventar antes de tener un caso real.
 * `stock_transfers.status` ya existe para cuando lo haya.
 *
 * Los dos movimientos comparten stock_transfer_id, asi que el historial de
 * inventario de cada sede muestra su mitad y ambas se pueden reconciliar.
 */
class StockTransferService
{
    /**
     * @param  list<array{product_id?: ?int, product_variant_id?: ?int, ingredient_id?: ?int, quantity: float}>  $items
     */
    public function transfer(User $user, Branch $from, Branch $to, array $items, ?string $notes = null): StockTransfer
    {
        $this->assertBranchesAreUsable($user, $from, $to);

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'El traslado no tiene productos.']);
        }

        return DB::transaction(function () use ($user, $from, $to, $items, $notes) {
            $transfer = StockTransfer::create([
                'business_id' => $from->business_id,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'user_id' => $user->id,
                'status' => StockTransfer::STATUS_COMPLETED,
                'notes' => $notes,
                'transferred_at' => now(),
            ]);

            foreach ($items as $item) {
                $this->transferOne($user, $transfer, $from, $to, $item);
            }

            return $transfer->load('items');
        });
    }

    /**
     * @param  array{product_id?: ?int, product_variant_id?: ?int, ingredient_id?: ?int, quantity: float}  $item
     */
    private function transferOne(User $user, StockTransfer $transfer, Branch $from, Branch $to, array $item): void
    {
        $quantity = (float) ($item['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['items' => 'La cantidad a trasladar debe ser mayor que cero.']);
        }

        [$column, $target] = $this->resolveTarget($transfer, $item);

        // lockForUpdate dentro de la transaccion: sin el, dos traslados
        // simultaneos de la misma sede podrian pasar los dos la validacion de
        // saldo y dejarla en negativo.
        $available = (float) DB::table('branch_stocks')
            ->where('branch_id', $from->id)
            ->where($column, $target->getKey())
            ->lockForUpdate()
            ->value('stock');

        if ($available < $quantity) {
            throw ValidationException::withMessages([
                'items' => sprintf(
                    'No hay suficiente stock de "%s" en %s: hay %s y se intentan trasladar %s.',
                    $this->labelFor($target),
                    $from->name,
                    rtrim(rtrim(number_format($available, 2, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ','),
                ),
            ]);
        }

        $unitCost = $target->cost_price !== null ? (float) $target->cost_price : null;

        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'business_id' => $transfer->business_id,
            $column => $target->getKey(),
            'quantity' => $quantity,
            'unit_cost_cop' => $unitCost,
        ]);

        $shared = [
            'business_id' => $transfer->business_id,
            'stock_transfer_id' => $transfer->id,
            'unit_cost_cop' => $unitCost,
            'reference' => "Traslado #{$transfer->id}",
            'user_id' => $user->id,
        ];

        // product_id ademas del variant, igual que el resto de StockService:
        // los reportes agrupan por producto padre.
        $productColumns = $column === 'product_variant_id'
            ? ['product_id' => $target->product_id, 'product_variant_id' => $target->getKey()]
            : [$column => $target->getKey()];

        StockMovement::create([
            ...$shared,
            ...$productColumns,
            'branch_id' => $from->id,
            'type' => StockMovement::TYPE_EXIT,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_TRANSFER_OUT),
            'quantity' => -$quantity,
            'notes' => "Hacia {$to->name}",
        ]);

        StockMovement::create([
            ...$shared,
            ...$productColumns,
            'branch_id' => $to->id,
            'type' => StockMovement::TYPE_ENTRY,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode(StockMovementReason::CODE_TRANSFER_IN),
            'quantity' => $quantity,
            'notes' => "Desde {$from->name}",
        ]);
    }

    /**
     * @param  array{product_id?: ?int, product_variant_id?: ?int, ingredient_id?: ?int}  $item
     * @return array{0: string, 1: Product|ProductVariant|Ingredient}
     */
    private function resolveTarget(StockTransfer $transfer, array $item): array
    {
        $candidates = [
            'product_variant_id' => ProductVariant::class,
            'product_id' => Product::class,
            'ingredient_id' => Ingredient::class,
        ];

        foreach ($candidates as $column => $model) {
            if (empty($item[$column])) {
                continue;
            }

            $target = $model::withoutGlobalScopes()
                ->where('business_id', $transfer->business_id)
                ->find($item[$column]);

            if (! $target) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los productos del traslado no pertenece a este negocio.',
                ]);
            }

            return [$column, $target];
        }

        throw ValidationException::withMessages([
            'items' => 'Cada linea del traslado debe indicar un producto, una variante o un insumo.',
        ]);
    }

    private function labelFor(Product|ProductVariant|Ingredient $target): string
    {
        return (string) ($target->name ?? $target->sku ?? '#'.$target->getKey());
    }

    private function assertBranchesAreUsable(User $user, Branch $from, Branch $to): void
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'La sede de origen y la de destino no pueden ser la misma.',
            ]);
        }

        if ((int) $from->business_id !== (int) $to->business_id) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'No se puede trasladar inventario entre negocios distintos.',
            ]);
        }

        foreach (['from_branch_id' => $from, 'to_branch_id' => $to] as $field => $branch) {
            if (! $branch->is_active) {
                throw ValidationException::withMessages([
                    $field => "La sede {$branch->name} esta inactiva.",
                ]);
            }

            if (! $user->canAccessBranch($branch)) {
                throw ValidationException::withMessages([
                    $field => "No tienes acceso a la sede {$branch->name}.",
                ]);
            }
        }
    }
}
