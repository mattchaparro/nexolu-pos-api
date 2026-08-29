<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessPaymentTerminal;
use App\Models\TerminalCharge;
use App\Services\TerminalChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cobrar en el datafono desde la caja.
 *
 * `show` es el que consulta la caja mientras espera. Se consulta por
 * `reference` y no por id: es lo que devuelve `store` y lo mismo con lo que
 * el webhook encuentra el cobro, asi que hay una sola llave en juego.
 */
class TerminalChargeController extends Controller
{
    public function __construct(private TerminalChargeService $charges) {}

    /** Los datafonos que la caja puede ofrecer. */
    public function terminals(): JsonResponse
    {
        $terminals = BusinessPaymentTerminal::where('is_active', true)->orderBy('name')->get();

        return response()->json([
            'terminals' => $terminals->map(fn (BusinessPaymentTerminal $t) => [
                'id' => $t->id,
                'name' => $t->displayName(),
                'serial' => $t->serial,
                'model' => $t->model,
                'is_usable' => $t->isUsable(),
            ])->values(),
            'last_synced_at' => $terminals->max('last_synced_at')?->toIso8601String(),
        ]);
    }

    /** Trae la lista del proveedor. La dispara el comerciante a mano. */
    public function sync(Request $request): JsonResponse
    {
        try {
            $this->charges->sync($request->user()->business);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return $this->terminals();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $terminal = BusinessPaymentTerminal::findOrFail($data['terminal_id']);

        try {
            $charge = $this->charges->start($request->user(), $terminal, (float) $data['amount']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($this->present($charge), 201);
    }

    /** Lo que consulta la caja mientras espera al cliente. */
    public function show(string $reference): JsonResponse
    {
        $charge = TerminalCharge::where('reference', $reference)->firstOrFail();

        return response()->json($this->present($charge));
    }

    /**
     * Cancela la espera del lado del POS.
     *
     * No cancela el cobro en el aparato -- el proveedor no expone eso -- asi
     * que se marca como error y se dice en el mensaje: si el cliente igual
     * pasa la tarjeta, ese cobro hay que anularlo desde Bold.
     */
    public function destroy(string $reference): JsonResponse
    {
        $charge = TerminalCharge::where('reference', $reference)->firstOrFail();
        $this->charges->resolve($charge, TerminalCharge::STATUS_ERROR, 'Cancelado desde la caja');

        return response()->json($this->present($charge->fresh()));
    }

    /** @return array<string, mixed> */
    private function present(TerminalCharge $charge): array
    {
        return [
            'reference' => $charge->reference,
            'status' => $charge->status,
            'amount' => (float) $charge->amount,
            'terminal' => $charge->terminal?->displayName(),
            'failure_reason' => $charge->failure_reason,
            'sale_id' => $charge->sale_id,
            'resolved_at' => $charge->resolved_at?->toIso8601String(),
        ];
    }
}
