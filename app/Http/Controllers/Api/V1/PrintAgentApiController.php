<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Resources\Api\V1\PrinterResource;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Services\PrintAgentService;
use App\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que habla el Nexolu Print Agent: vincularse, reportarse, pedir tickets
 * y confirmar cada uno.
 *
 * Ciclo del agente: claim cada ~2 s; por cada ticket, mandar los bytes a la
 * impresora (`printer.connection` + `printer.address`) y responder ack o
 * fail. heartbeat cada ~30 s con las impresoras de Windows que ve.
 */
class PrintAgentApiController extends Controller
{
    public function __construct(private PrintAgentService $agents, private PrintJobService $jobs) {}

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
            'version' => ['nullable', 'string', 'max:30'],
        ], [
            'code.digits' => 'El código tiene 6 números.',
        ]);

        ['agent' => $agent, 'token' => $token] = $this->agents->pair($data['code'], $data['version'] ?? null);
        $agent->loadMissing('business');

        return response()->json([
            'token' => $token,
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'business' => ['name' => $agent->business?->name],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:30'],
            'printers' => ['nullable', 'array', 'max:50'],
            'printers.*' => ['string', 'max:191'],
        ]);

        $agent = $this->agents->heartbeat($this->agent($request), $data['version'] ?? null, $data['printers'] ?? null);

        return response()->json([
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'printers' => PrinterResource::collection($agent->printers()->where('is_active', true)->get()),
            'poll_seconds' => 2,
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $this->agents->touch($agent);

        $jobs = $this->jobs->claim($agent);

        return response()->json([
            'jobs' => $jobs->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'type' => $job->type,
                'printer' => [
                    'id' => $job->printer->id,
                    'name' => $job->printer->name,
                    'connection' => $job->printer->connection,
                    'address' => $job->printer->address,
                ],
                'payload' => $job->payload,
                'attempt' => $job->attempts,
            ])->values(),
        ]);
    }

    public function acknowledge(Request $request, PrintJob $job): JsonResponse
    {
        $job = $this->jobs->acknowledge($this->agent($request), $job);

        return response()->json(['id' => $job->id, 'status' => $job->status]);
    }

    public function fail(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['error' => ['required', 'string', 'max:2000']]);
        $job = $this->jobs->fail($this->agent($request), $job, $data['error']);

        return response()->json(['id' => $job->id, 'status' => $job->status]);
    }

    private function agent(Request $request): PrintAgent
    {
        return $request->attributes->get(AuthenticatePrintAgent::ATTRIBUTE);
    }
}
