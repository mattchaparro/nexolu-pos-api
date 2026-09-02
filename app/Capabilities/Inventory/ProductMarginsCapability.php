<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\User;
use App\Services\InventoryReportService;

/**
 * Tool: margenes_producto. Que producto deja mas plata en un periodo.
 *
 * Delega en InventoryReportService::margins(), el mismo metodo que sirve el
 * reporte de margenes del POS. Dos cosas se ganan con eso frente a la version
 * del legacy, que rehacia la consulta:
 *
 * - El costo sale de unit_cost_at_sale (lo que costaba CUANDO se vendio), no
 *   del costo actual del producto. El legacy avisaba de esa imprecision en una
 *   nota al modelo; aca ya no hace falta.
 * - Los productos sin costo cargado no se mezclan con los demas inflando la
 *   utilidad: el reporte ya los separa en su propia lista.
 */
class ProductMarginsCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    public function __construct(private InventoryReportService $inventoryReportService) {}

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
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'orden' => ['sometimes', 'nullable', 'in:mas_utilidad,menos_utilidad,mejor_margen'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $report = $this->inventoryReportService->margins($business, [
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'with_sales' => true,
        ]);

        // Solo lo que efectivamente se vendio: el reporte trae el catalogo
        // completo con su margen teorico, y "que producto me deja mas plata"
        // es una pregunta sobre lo vendido, no sobre lo que esta en la vitrina.
        $products = array_values(array_filter(array_map(fn (array $row) => [
            'producto' => $row['name'],
            'unidades_vendidas' => (int) ($row['qty_sold'] ?? 0),
            'precio_venta' => $row['price'],
            'costo_unitario' => $row['cost_price'],
            'margen_porcentaje' => $row['margin_pct'],
            'utilidad' => (float) ($row['profit_from_sales'] ?? 0),
        ], $report['margin_rows']), fn (array $row) => $row['unidades_vendidas'] > 0));

        $order = $arguments['orden'] ?? 'mas_utilidad';
        usort($products, fn ($a, $b) => match ($order) {
            'menos_utilidad' => $a['utilidad'] <=> $b['utilidad'],
            'mejor_margen' => ($b['margen_porcentaje'] ?? -1) <=> ($a['margen_porcentaje'] ?? -1),
            default => $b['utilidad'] <=> $a['utilidad'],
        });

        $uncosted = array_map(fn (array $row) => [
            'producto' => $row['product']['name'] ?? 'Producto eliminado',
            'unidades_vendidas' => round((float) $row['qty_sold'], 2),
            'ingreso' => round((float) $row['revenue'], 2),
        ], $report['uncosted_rows']);

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'productos' => array_slice($products, 0, min((int) ($arguments['limite'] ?? 15), self::MAX_ROWS)),
            'vendidos_sin_costo_cargado' => $this->capRows($uncosted),
            'nota' => 'El costo es el que tenia el producto al momento de cada venta. Los productos '
                .'de vendidos_sin_costo_cargado no tienen costo registrado: de esos no se puede '
                .'saber la ganancia, avisalo en vez de asumir que todo el ingreso es utilidad.',
        ];
    }
}
