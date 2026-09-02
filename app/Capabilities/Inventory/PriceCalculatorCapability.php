<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Tool: calcular_precio. A como vender para ganar X, o cuanto deja un precio.
 *
 * Nace de una cuenta que el micro-comerciante hace mal a diario, de cabeza y
 * redondeando para abajo. El margen es sobre el PRECIO, no sobre el costo
 * (markup), igual que margenes_producto: 30% de margen sobre $10.000 de costo
 * da $14.286, no $13.000. Mezclar las dos convenciones en el mismo asistente
 * confundiria mas de lo que ayuda.
 */
class PriceCalculatorCapability implements Capability
{
    use ResolvesProductByName;

    public function requiredPermission(): ?string
    {
        return 'reports.inventory';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'producto' => ['sometimes', 'nullable', 'string', 'max:200'],
            'costo' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'margen_deseado' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'lt:100'],
            'precio_venta' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $cost = $this->resolveCost($arguments);

        $hasMargin = isset($arguments['margen_deseado']);
        $hasPrice = isset($arguments['precio_venta']);

        if ($hasMargin === $hasPrice) {
            throw ValidationException::withMessages([
                'margen_deseado' => 'Da exactamente uno de los dos: el margen de ganancia deseado, o '
                    .'un precio de venta del que calcular el margen.',
            ]);
        }

        if ($hasMargin) {
            $margin = (float) $arguments['margen_deseado'];
            $price = $cost / (1 - $margin / 100);

            return [
                'costo' => round($cost, 2),
                'margen_objetivo_porcentaje' => round($margin, 2),
                'precio_sugerido' => round($price, 2),
                'utilidad_por_unidad' => round($price - $cost, 2),
            ];
        }

        $price = (float) $arguments['precio_venta'];
        $profit = $price - $cost;

        $result = [
            'costo' => round($cost, 2),
            'precio_venta' => round($price, 2),
            'utilidad_por_unidad' => round($profit, 2),
            'margen_resultante_porcentaje' => round(($profit / $price) * 100, 2),
        ];

        if ($profit < 0) {
            $result['nota'] = 'Este precio queda por debajo del costo: se venderia con perdida.';
        }

        return $result;
    }

    /** @param  array<string, mixed>  $arguments */
    private function resolveCost(array $arguments): float
    {
        $hasCost = isset($arguments['costo']);
        $hasProduct = ! empty($arguments['producto']);

        if ($hasCost === $hasProduct) {
            throw ValidationException::withMessages([
                'costo' => 'Da exactamente uno de los dos: el nombre del producto (para tomar su '
                    .'costo actual), o un costo directo.',
            ]);
        }

        if ($hasCost) {
            return (float) $arguments['costo'];
        }

        $cost = (float) $this->resolveProductByName((string) $arguments['producto'])->cost_price;

        if ($cost <= 0) {
            throw ValidationException::withMessages([
                'producto' => 'Ese producto no tiene costo cargado, asi que no hay con que calcular '
                    .'el margen. Pidele al usuario el costo, o que lo registre en Inventario.',
            ]);
        }

        return $cost;
    }
}
