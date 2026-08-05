<?php

namespace App\Capabilities;

use App\Capabilities\Cash\CashStatusCapability;
use App\Capabilities\Clients\CreateClientCapability;
use App\Capabilities\Expenses\CreateExpenseCapability;
use App\Capabilities\Inventory\InventoryListCapability;
use App\Capabilities\Inventory\ProductStockCapability;
use App\Capabilities\Products\CreateProductCapability;
use App\Capabilities\Sales\SalesByDayCapability;
use App\Capabilities\Sales\SalesSummaryCapability;

/**
 * Nombre de herramienta (tal como lo conoce el IA Core, ver
 * apps/pos/tools.py en Nexolu-IA-Core) -> clase que la implementa. Unica
 * fuente de verdad del vocabulario compartido: agregar una capacidad nueva
 * es una entrada mas aqui, no una ruta nueva.
 */
class Registry
{
    /** @var array<string, class-string<Capability>> */
    private const MAP = [
        'ventas_resumen' => SalesSummaryCapability::class,
        'ventas_por_dia' => SalesByDayCapability::class,
        'estado_caja' => CashStatusCapability::class,
        'inventario' => InventoryListCapability::class,
        'stock_producto' => ProductStockCapability::class,
        'crear_gasto' => CreateExpenseCapability::class,
        'crear_producto' => CreateProductCapability::class,
        'crear_cliente' => CreateClientCapability::class,
    ];

    public function resolve(string $name): ?Capability
    {
        $class = self::MAP[$name] ?? null;

        return $class ? app($class) : null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::MAP);
    }
}
