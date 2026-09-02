<?php

namespace App\Capabilities;

use App\Capabilities\Appointments\AppointmentsCapability;
use App\Capabilities\Cash\CashStatusCapability;
use App\Capabilities\Clients\CreateClientCapability;
use App\Capabilities\Clients\FrequentClientsCapability;
use App\Capabilities\Clients\ReceivablesCapability;
use App\Capabilities\Expenses\CreateExpenseCapability;
use App\Capabilities\Expenses\ExpensesSummaryCapability;
use App\Capabilities\Inventory\CreateIngredientEntryCapability;
use App\Capabilities\Inventory\CreateStockEntryCapability;
use App\Capabilities\Inventory\IngredientStockCapability;
use App\Capabilities\Inventory\InventoryListCapability;
use App\Capabilities\Inventory\PriceCalculatorCapability;
use App\Capabilities\Inventory\ProductCostVariationCapability;
use App\Capabilities\Inventory\ProductMarginsCapability;
use App\Capabilities\Inventory\ProductRecipeCapability;
use App\Capabilities\Inventory\ProductStockCapability;
use App\Capabilities\Inventory\RestockSuggestionsCapability;
use App\Capabilities\Inventory\StockHistoryCapability;
use App\Capabilities\Layaways\LayawaysCapability;
use App\Capabilities\Products\CreateProductCapability;
use App\Capabilities\Purchases\CreatePurchaseCapability;
use App\Capabilities\Purchases\PayablesCapability;
use App\Capabilities\Purchases\PurchasesDetailCapability;
use App\Capabilities\Purchases\PurchasesSummaryCapability;
use App\Capabilities\Purchases\SuppliersCapability;
use App\Capabilities\Reminders\CreateReminderCapability;
use App\Capabilities\Reminders\PendingRemindersCapability;
use App\Capabilities\Sales\AccountHistoryCapability;
use App\Capabilities\Sales\OpenTabsCapability;
use App\Capabilities\Sales\PaymentMethodsCapability;
use App\Capabilities\Sales\SalesByDayCapability;
use App\Capabilities\Sales\SalesBySellerCapability;
use App\Capabilities\Sales\SalesHistoryCapability;
use App\Capabilities\Sales\SalesSummaryCapability;
use App\Capabilities\Sales\TopProductsCapability;
use App\Capabilities\Services\ServiceOrdersCapability;

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

        // Portadas del chat del legacy (2026-09-02). Ninguna se usaba ahi -- el
        // chat viejo murio con 19 conversaciones en total -- pero el catalogo
        // se construyo respondiendo a preguntas reales de dueños, y lo que
        // costaba era descubrir cuales hacian falta, no escribirlas.
        'ventas_historico' => SalesHistoryCapability::class,
        'ventas_por_vendedor' => SalesBySellerCapability::class,
        'metodos_de_pago' => PaymentMethodsCapability::class,
        'productos_top' => TopProductsCapability::class,
        'cuentas_abiertas' => OpenTabsCapability::class,
        'historial_cuenta' => AccountHistoryCapability::class,
        'clientes_frecuentes' => FrequentClientsCapability::class,
        'fiados_pendientes' => ReceivablesCapability::class,
        'gastos_resumen' => ExpensesSummaryCapability::class,
        'inventario_reposicion' => RestockSuggestionsCapability::class,
        'ingredientes_stock' => IngredientStockCapability::class,
        'margenes_producto' => ProductMarginsCapability::class,
        'calcular_precio' => PriceCalculatorCapability::class,
        'receta_producto' => ProductRecipeCapability::class,
        'variacion_costo_producto' => ProductCostVariationCapability::class,
        'historial_stock' => StockHistoryCapability::class,
        'proveedores' => SuppliersCapability::class,
        'compras_resumen' => PurchasesSummaryCapability::class,
        'compras_detalle' => PurchasesDetailCapability::class,
        'cuentas_por_pagar' => PayablesCapability::class,
        'apartados' => LayawaysCapability::class,
        'citas_agendadas' => AppointmentsCapability::class,
        'servicios_estado' => ServiceOrdersCapability::class,
        'recordatorios_pendientes' => PendingRemindersCapability::class,
        'crear_compra' => CreatePurchaseCapability::class,
        'crear_entrada_inventario' => CreateStockEntryCapability::class,
        'crear_entrada_ingrediente' => CreateIngredientEntryCapability::class,
        'crear_recordatorio' => CreateReminderCapability::class,
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
