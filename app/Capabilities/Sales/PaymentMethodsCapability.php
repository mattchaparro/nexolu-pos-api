<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\User;
use App\Services\SalesReportService;
use Illuminate\Validation\ValidationException;

/**
 * Tool: metodos_de_pago. Como pagaron los clientes en un periodo.
 *
 * Delega en SalesReportService::dailySummary(), que es de donde sale el
 * desglose del "Resumen del dia" del POS. Dos razones para no reimplementar la
 * consulta como hacia el legacy:
 *
 * 1. Los pagos divididos viven en sale_payment_splits y las ventas de un solo
 *    metodo lo llevan en la propia venta: sumar una sola de las dos fuentes
 *    deja plata afuera.
 * 2. El legacy solo miraba ventas. Un abono a un fiado, a un apartado o a una
 *    orden de servicio TAMBIEN es dinero que entro por un medio de pago, y no
 *    aparecia. El resumen del dia si los cuenta, asi que ahora el chat y el
 *    tablero dan la misma cifra.
 */
class PaymentMethodsCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    /** Mismo tope que el resumen del dia del POS (SalesReportService). */
    private const MAX_DAYS = 92;

    public function __construct(private SalesReportService $salesReportService) {}

    public function requiredPermission(): ?string
    {
        return 'reports.sales';
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
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        // El resumen del dia recorta el rango en silencio si se pasa; aca se
        // avisa, porque el modelo tiene que poder decirle al usuario que la
        // cifra no cubre lo que pidio en vez de presentarla como si si.
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'desde' => 'El desglose por medio de pago cubre maximo '.self::MAX_DAYS
                    .' dias. Pide un rango mas corto.',
            ]);
        }

        $summary = $this->salesReportService->dailySummary(
            $business,
            $start->toDateString(),
            $end->toDateString()
        );

        $grandTotal = (float) $summary['total_sales'];

        $methods = array_map(fn (array $method) => [
            'metodo' => $method['label'],
            'total' => round((float) $method['total'], 2),
            'porcentaje' => $grandTotal > 0 ? round(((float) $method['total'] / $grandTotal) * 100, 1) : 0.0,
        ], $summary['payment_breakdown']);

        usort($methods, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'desde' => $summary['date_from'],
            'hasta' => $summary['date_to'],
            'total_periodo' => round($grandTotal, 2),
            'metodos' => $this->capRows($methods),
            'nota' => 'Incluye ventas, abonos a fiados, abonos a apartados y pagos de servicios: '
                .'todo lo que entro por caja en el periodo, igual que el resumen del dia del POS.',
        ];
    }
}
