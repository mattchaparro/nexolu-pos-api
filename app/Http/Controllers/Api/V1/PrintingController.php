<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PrinterRequest;
use App\Http\Requests\Api\V1\UpdatePrintSettingsRequest;
use App\Http\Resources\Api\V1\PrintAgentResource;
use App\Http\Resources\Api\V1\PrinterResource;
use App\Http\Resources\Api\V1\PrintJobResource;
use App\Models\BusinessPrintSettings;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Sale;
use App\Services\PrintAgentService;
use App\Services\PrintJobService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuracion › Impresoras (solo admin) y lo que cualquier empleado
 * necesita de la impresion: el estado para el aviso rojo y reimprimir.
 */
class PrintingController extends Controller
{
    public function __construct(private PrintAgentService $agents, private PrintJobService $jobs) {}

    /** Todo lo de la pantalla en una sola consulta: ajustes, equipos, impresoras y tickets fallidos. */
    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'settings' => $this->settingsPayload(BusinessPrintSettings::forBusiness($businessId)),
            'agents' => PrintAgentResource::collection(PrintAgent::query()->orderBy('id')->get()),
            'printers' => PrinterResource::collection(Printer::query()->orderBy('role')->orderBy('id')->get()),
            'failed_jobs' => PrintJobResource::collection(
                PrintJob::query()->with('printer')->where('status', PrintJob::STATUS_FAILED)->latest('id')->limit(20)->get(),
            ),
        ]);
    }

    /**
     * Lo minimo para el aviso de Vender y Despacho: si hay equipo vinculado,
     * si esta en linea y cuantos tickets fallaron hoy.
     */
    public function status(): JsonResponse
    {
        $agents = PrintAgent::query()->whereNotNull('token_hash')->whereNull('revoked_at')->get();

        return response()->json([
            'has_agent' => $agents->isNotEmpty(),
            'agents_offline' => $agents->reject(fn (PrintAgent $agent) => $agent->isOnline())->pluck('name')->values(),
            'failed_jobs' => PrintJob::query()
                ->where('status', PrintJob::STATUS_FAILED)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
            'has_counter_printer' => Printer::query()->where('role', Printer::ROLE_COUNTER)->where('is_active', true)->exists(),
        ]);
    }

    public function updateSettings(UpdatePrintSettingsRequest $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $settings = BusinessPrintSettings::query()->firstOrNew(['business_id' => $businessId]);
        $settings->fill($request->validated())->save();
        AuditLogger::log('printing.settings_updated', $request->validated());

        return response()->json(['settings' => $this->settingsPayload($settings)]);
    }

    /** Crea un equipo y devuelve el codigo de 6 digitos para el instalador del agente. */
    public function storeAgent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $request->user()->business_id)],
        ]);

        ['agent' => $agent, 'code' => $code] = $this->agents->create($request->user()->business, $data['name'], $data['branch_id'] ?? null);
        AuditLogger::log('printing.agent_created', ['print_agent_id' => $agent->id]);

        return response()->json(['agent' => new PrintAgentResource($agent), 'pairing_code' => $code], 201);
    }

    public function pairingCode(PrintAgent $agent): JsonResponse
    {
        $code = $this->agents->issuePairingCode($agent);
        AuditLogger::log('printing.agent_repaired', ['print_agent_id' => $agent->id]);

        return response()->json(['agent' => new PrintAgentResource($agent->fresh()), 'pairing_code' => $code]);
    }

    /** Desvincula y borra el equipo. Sus impresoras quedan sin computador hasta asignarles otro. */
    public function destroyAgent(PrintAgent $agent): JsonResponse
    {
        $this->agents->revoke($agent);
        $agent->delete();
        AuditLogger::log('printing.agent_deleted', ['print_agent_id' => $agent->id]);

        return response()->json(null, 204);
    }

    public function storePrinter(PrinterRequest $request): JsonResponse
    {
        $printer = Printer::create($request->validated());
        AuditLogger::log('printing.printer_created', ['printer_id' => $printer->id]);

        return response()->json(new PrinterResource($printer), 201);
    }

    public function updatePrinter(PrinterRequest $request, Printer $printer): PrinterResource
    {
        $printer->update($request->validated());

        return new PrinterResource($printer);
    }

    public function destroyPrinter(Printer $printer): JsonResponse
    {
        $printer->delete();
        AuditLogger::log('printing.printer_deleted', ['printer_id' => $printer->id]);

        return response()->json(null, 204);
    }

    public function testPrinter(Request $request, Printer $printer): JsonResponse
    {
        if ($printer->print_agent_id === null) {
            throw ValidationException::withMessages(['printer' => 'Asígnale a esta impresora el computador que la maneja.']);
        }

        $job = $this->jobs->enqueueTest($printer, $request->user());

        return response()->json(new PrintJobResource($job->load('printer')), 201);
    }

    public function retryJob(PrintJob $job): PrintJobResource
    {
        return new PrintJobResource($this->jobs->retry($job)->load('printer'));
    }

    /** Reimprimir la factura de una venta desde el POS (cualquier empleado). */
    public function reprintReceipt(Request $request, Sale $sale): JsonResponse
    {
        $job = $this->jobs->enqueueReceipt($sale, $request->user(), isReprint: true);
        if ($job === null) {
            throw ValidationException::withMessages(['printer' => 'No hay una impresora del mostrador conectada.']);
        }
        AuditLogger::log('printing.receipt_reprinted', ['sale_id' => $sale->id]);

        return response()->json(new PrintJobResource($job->load('printer')), 201);
    }

    /** @return array{auto_print_receipts: bool, receipt_copies: int, open_cash_drawer: bool} */
    private function settingsPayload(BusinessPrintSettings $settings): array
    {
        return [
            'auto_print_receipts' => (bool) $settings->auto_print_receipts,
            'receipt_copies' => (int) $settings->receipt_copies,
            'open_cash_drawer' => (bool) $settings->open_cash_drawer,
        ];
    }
}
