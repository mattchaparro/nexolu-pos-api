<?php

namespace App\Capabilities\Support;

use App\Models\Ingredient;
use App\Models\Product;
use App\Support\NameMatcher;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve el nombre que dijo una persona a un producto o ingrediente concreto.
 *
 * Nunca adivina entre varios: si quedan dos candidatos lanza un error con los
 * nombres, para que el modelo le pregunte al usuario. Registrar la entrada de
 * stock del producto equivocado es peor que una pregunta de mas.
 */
trait ResolvesProductByName
{
    private function resolveProductByName(string $name): Product
    {
        $candidates = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'cost_price', 'price', 'track_stock', 'is_service']);

        return $this->pickOne($candidates, $name, 'producto');
    }

    private function resolveIngredientByName(string $name): Ingredient
    {
        $candidates = Ingredient::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'cost_price', 'stock']);

        return $this->pickOne($candidates, $name, 'ingrediente');
    }

    /**
     * @template T of Product|Ingredient
     *
     * @param  Collection<int, T>  $candidates
     * @return T
     */
    private function pickOne(mixed $candidates, string $name, string $noun): mixed
    {
        $name = trim($name);
        $nameOf = fn ($item) => (string) $item->name;

        // Una coincidencia exacta gana sobre las parciales: si el usuario dijo
        // "gaseosa" y existe un producto llamado "Gaseosa", ese es, aunque
        // haya otros que empiecen igual.
        $exact = NameMatcher::exact($candidates, $name, $nameOf);
        if (count($exact) === 1) {
            return $exact[0];
        }

        $partial = NameMatcher::filter($candidates, $name, $nameOf);

        if (count($partial) === 1) {
            return $partial[0];
        }

        if (count($partial) > 1) {
            throw ValidationException::withMessages([
                $noun => "\"{$name}\" coincide con varios: ".implode(', ', array_map($nameOf, $partial))
                    .'. Preguntale al usuario a cual se refiere antes de continuar.',
            ]);
        }

        throw ValidationException::withMessages([
            $noun => "No hay ningun {$noun} activo que coincida con \"{$name}\".",
        ]);
    }
}
