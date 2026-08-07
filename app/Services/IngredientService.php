<?php

namespace App\Services;

use App\Models\Ingredient;

class IngredientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Ingredient
    {
        return Ingredient::create([
            'cost_price' => 0,
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        $previousCost = (float) $ingredient->cost_price;

        $ingredient->update($data);

        if (abs($previousCost - (float) $ingredient->fresh()->cost_price) > 0.0001) {
            $ingredient->syncLinkedProductCosts();
        }

        return $ingredient->fresh();
    }
}
