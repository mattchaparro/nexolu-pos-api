<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\User;
use App\Services\SalesReportService;
use App\Support\NameMatcher;

/**
 * Tool: ventas_por_vendedor.
 *
 * Delega en SalesReportService::salesBySeller(), el mismo metodo que sirve la
 * pantalla de reportes: el legacy reimplementaba el agrupamiento en la
 * herramienta y sus cifras podian diferir de las del reporte que el dueño
 * tenia abierto al lado.
 */
class SalesBySellerCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    public function __construct(private SalesReportService $salesReportService) {}

    public function requiredPermission(): ?string
    {
        return 'reports.sales_by_seller';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'nombre_vendedor' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $report = $this->salesReportService->salesBySeller(
            $business,
            $start->toDateString(),
            $end->toDateString()
        );

        $sellers = $report['sellers'];

        // El filtro por nombre va en PHP y por palabras, no con un LIKE: el
        // nombre lo dicta una persona hablando ("las ventas de Juan") y
        // "juan" tiene que encontrar a "Juan Carlos Perez".
        if (! empty($arguments['nombre_vendedor'])) {
            $sellers = NameMatcher::filter(
                $sellers,
                (string) $arguments['nombre_vendedor'],
                fn (array $seller) => (string) $seller['user_name']
            );
        }

        return [
            'desde' => $report['from'],
            'hasta' => $report['to'],
            'vendedores' => $this->capRows(array_map(fn (array $seller) => [
                'vendedor' => $seller['user_name'],
                'numero_ventas' => $seller['sales_count'],
                'total_vendido' => $seller['gross_total'],
                'ticket_promedio' => $seller['avg_ticket'],
                'unidades_vendidas' => $seller['items_sold'],
                'ultima_venta' => $seller['last_sale_at'],
            ], array_values($sellers))),
            'nota' => 'El vendedor es quien CERRO la venta, que es como lo cuenta el reporte de '
                .'ventas por vendedor del POS.',
        ];
    }
}
