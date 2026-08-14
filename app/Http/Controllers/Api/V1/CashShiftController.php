<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseCashShiftRequest;
use App\Http\Requests\Api\V1\OpenCashShiftRequest;
use App\Http\Requests\Api\V1\UpdateCashShiftRequest;
use App\Http\Resources\Api\V1\CashShiftResource;
use App\Models\CashShift;
use App\Services\CashClosingService;
use App\Services\CashShiftService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CashShiftController extends Controller
{
    public function __construct(
        private CashShiftService $cashShiftService,
        private CashClosingService $cashClosingService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashShift::with(['user', 'closedByUser'])
            ->orderByRaw('closed_at IS NULL DESC')
            ->orderByDesc('opened_at');

        if ($request->filled('date')) {
            $date = $request->string('date');
            $query->where(function ($q) use ($date) {
                $q->whereDate('opened_at', $date)->orWhereNull('closed_at');
            });
        }

        return CashShiftResource::collection($query->paginate(25)->withQueryString());
    }

    /** El turno abierto del usuario autenticado, con un avance de sus totales hasta ahora. */
    public function current(Request $request): array
    {
        $user = $request->user();
        $open = $this->cashShiftService->findOpenShiftForUser($user);

        if (! $open) {
            return ['shift' => null, 'preview_totals' => null];
        }

        $previewTotals = $this->cashClosingService->calculateTotalsBetween(
            $open->opened_at->copy(),
            now(),
            (int) $user->business_id,
            (float) $open->opening_cash,
            (int) $user->id
        );

        return [
            'shift' => new CashShiftResource($open),
            'preview_totals' => $previewTotals,
        ];
    }

    public function store(OpenCashShiftRequest $request): CashShiftResource
    {
        $shift = $this->cashShiftService->openShift(
            $request->user(),
            (float) $request->validated('opening_cash'),
            $request->validated('opening_note')
        );

        AuditLogger::log('cash_shift.opened', ['cash_shift_id' => $shift->id, 'opening_cash' => $shift->opening_cash]);

        return new CashShiftResource($shift);
    }

    public function close(CloseCashShiftRequest $request, CashShift $cashShift): CashShiftResource
    {
        $shift = $this->cashShiftService->closeShift(
            $request->user(),
            $cashShift,
            (float) $request->validated('counted_cash'),
            $request->validated('closing_note')
        );

        AuditLogger::log('cash_shift.closed', [
            'cash_shift_id' => $shift->id,
            'expected_cash' => $shift->expected_cash,
            'counted_cash' => $shift->counted_cash,
        ]);

        return new CashShiftResource($shift);
    }

    /** Correccion administrativa: editar valores ya guardados, no un abrir/cerrar. */
    public function update(UpdateCashShiftRequest $request, CashShift $cashShift): CashShiftResource
    {
        $data = $request->validated();
        $payload = [
            'opening_cash' => $data['opening_cash'],
            'opening_note' => $data['opening_note'] ?? null,
        ];

        if (! $cashShift->isOpen()) {
            $newExpectedCash = (float) $data['opening_cash'] + (float) $cashShift->total_cash - (float) $cashShift->total_expenses;
            $newCountedCash = array_key_exists('counted_cash', $data) && $data['counted_cash'] !== null
                ? (float) $data['counted_cash']
                : (float) $cashShift->counted_cash;

            $payload['expected_cash'] = $newExpectedCash;
            $payload['counted_cash'] = $newCountedCash;
            $payload['difference'] = $newCountedCash - $newExpectedCash;
            $payload['closing_note'] = $data['closing_note'] ?? null;
        }

        $cashShift->update($payload);

        AuditLogger::log('cash_shift.updated', ['cash_shift_id' => $cashShift->id, 'changes' => $payload]);

        return new CashShiftResource($cashShift->fresh(['user', 'closedByUser']));
    }

    public function destroy(CashShift $cashShift): Response
    {
        AuditLogger::log('cash_shift.deleted', [
            'cash_shift_id' => $cashShift->id,
            'was_open' => $cashShift->isOpen(),
            'opened_at' => $cashShift->opened_at?->toDateTimeString(),
        ]);

        $cashShift->delete();

        return response()->noContent();
    }
}
