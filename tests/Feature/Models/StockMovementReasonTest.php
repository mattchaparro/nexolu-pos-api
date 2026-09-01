<?php

namespace Tests\Feature\Models;

use App\Models\StockMovementReason;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionClass;
use Tests\TestCase;

class StockMovementReasonTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * El candado que faltaba: cada constante CODE_* tiene que resolver a un
     * motivo global de verdad.
     *
     * Sin esto, agregar una constante y olvidar la fila no rompe nada visible
     * - systemIdForCode() devuelve null, el movimiento se guarda igual porque
     * la columna es nullable, y el motivo desaparece del historial en
     * silencio. Fue lo que paso con 'layaway' y 'layaway_cancel', declaradas
     * desde el principio y nunca creadas.
     */
    public function test_every_declared_code_resolves_to_a_global_reason(): void
    {
        $codes = collect((new ReflectionClass(StockMovementReason::class))->getConstants())
            ->filter(fn ($value, $name) => str_starts_with($name, 'CODE_'))
            ->values();

        $this->assertNotEmpty($codes);

        foreach ($codes as $code) {
            $this->assertNotNull(
                StockMovementReason::systemIdForCode($code),
                "El motivo de sistema '{$code}' esta declarado en el modelo pero no existe en la base."
            );
        }
    }

    public function test_the_layaway_reasons_exist(): void
    {
        foreach ([StockMovementReason::CODE_LAYAWAY, StockMovementReason::CODE_LAYAWAY_CANCEL] as $code) {
            $this->assertNotNull(StockMovementReason::systemIdForCode($code));
        }
    }
}
