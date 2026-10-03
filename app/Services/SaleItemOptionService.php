<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Opciones de elección de una línea de venta (salsas, toppings): valida la
 * elección contra los grupos del producto, guarda la foto en
 * sale_item_options y descuenta/restaura el insumo de las opciones que lo
 * tengan. Las opciones sin insumo solo informan a cocina y al recibo.
 */
class SaleItemOptionService
{
    /**
     * @param  array<int, int|string>  $optionIds
     * @return Collection<int, ProductOption> opciones elegidas, con su grupo cargado.
     *
     * @throws ValidationException
     */
    public function resolve(Product $product, array $optionIds): Collection
    {
        $optionIds = collect($optionIds)->map(fn ($id) => (int) $id)->unique()->values();
        $product->loadMissing('optionGroups.options');

        $selectedByGroup = [];
        $chosen = collect();

        foreach ($optionIds as $optionId) {
            $group = $product->optionGroups->first(fn ($g) => $g->options->contains('id', $optionId));
            $option = $group?->options->firstWhere('id', $optionId);

            if (! $group || ! $option || ! $option->is_active) {
                throw ValidationException::withMessages([
                    'items' => 'Una de las opciones elegidas para «'.$product->name.'» ya no está disponible.',
                ]);
            }

            $option->setRelation('group', $group);
            $selectedByGroup[$group->id] = ($selectedByGroup[$group->id] ?? 0) + 1;
            $chosen->push($option);
        }

        foreach ($product->optionGroups as $group) {
            $count = $selectedByGroup[$group->id] ?? 0;
            $activeCount = $group->options->where('is_active', true)->count();
            $required = min($group->min_choices, $activeCount);

            if ($count < $required) {
                throw ValidationException::withMessages([
                    'items' => 'Elige '.($required === 1 ? 'una opción' : "al menos {$required} opciones").' de «'.$group->name.'» para «'.$product->name.'».',
                ]);
            }
            if ($count > $group->max_choices) {
                throw ValidationException::withMessages([
                    'items' => 'Máximo '.$group->max_choices.' de «'.$group->name.'» para «'.$product->name.'».',
                ]);
            }
        }

        return $chosen;
    }

    /** @param  Collection<int, ProductOption>  $options */
    public function extraTotal(Collection $options): float
    {
        return round((float) $options->sum(fn (ProductOption $o) => (float) $o->extra_price), 2);
    }

    /**
     * Guarda la foto de la elección y descuenta el insumo de las opciones que
     * lo traen (cantidad por unidad de la línea).
     *
     * @param  Collection<int, ProductOption>  $options
     */
    public function attach(User $user, Sale $sale, SaleItem $item, Collection $options): void
    {
        foreach ($options as $option) {
            $item->options()->create([
                'product_option_id' => $option->id,
                'group_name' => $option->group->name,
                'name' => $option->name,
                'extra_price' => $option->extra_price,
                'ingredient_id' => $option->ingredient_id,
                'ingredient_quantity' => $option->ingredient_quantity,
            ]);
        }

        $this->moveIngredients($user, $sale, $item, $item->options()->get(), StockMovement::TYPE_EXIT);
    }

    /** Devuelve al inventario el insumo de las opciones de la línea (reverso, cancelación, edición). */
    public function restore(User $user, Sale $sale, SaleItem $item, ?string $notes = null): void
    {
        $this->moveIngredients($user, $sale, $item, $item->options()->get(), StockMovement::TYPE_ENTRY, $notes);
    }

    /** @param  Collection<int, SaleItemOption>  $options */
    private function moveIngredients(User $user, Sale $sale, SaleItem $item, Collection $options, string $type, ?string $notes = null): void
    {
        $sign = $type === StockMovement::TYPE_EXIT ? -1 : 1;
        $reasonCode = $type === StockMovement::TYPE_EXIT ? StockMovementReason::CODE_SALE : StockMovementReason::CODE_SALE_REVERSAL;
        $reference = $type === StockMovement::TYPE_EXIT ? "Venta #{$sale->id}" : "Ajuste venta #{$sale->id}";

        foreach ($options as $option) {
            if (! $option->ingredient_id || (float) $option->ingredient_quantity <= 0) {
                continue;
            }

            StockMovement::create([
                'ingredient_id' => $option->ingredient_id,
                'business_id' => $sale->business_id,
                'type' => $type,
                'stock_movement_reason_id' => StockMovementReason::systemIdForCode($reasonCode),
                'quantity' => $sign * abs((float) $option->ingredient_quantity * (int) $item->quantity),
                'reference' => $reference,
                'notes' => $notes,
                'user_id' => $user->id,
            ]);
        }
    }
}
