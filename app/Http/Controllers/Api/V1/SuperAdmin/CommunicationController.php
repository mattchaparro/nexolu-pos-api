<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\SendBusinessCommunicationRequest;
use App\Models\Business;
use App\Services\BusinessCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bitacora unificada de todo lo que la app manda hacia afuera (email +
 * WhatsApp), para soporte/auditoria: "¿le llego esto a este negocio?".
 * email_logs (App\Listeners\LogSentEmail) y whatsapp_logs
 * (App\Services\WhatsApp\LoggingMessagingChannel) tienen shapes distintos -
 * se normalizan aca en vez de forzar un modelo Eloquent comun sobre dos
 * tablas con columnas diferentes (to_email/subject vs to_phone). Un UNION
 * ALL a nivel SQL (no un merge de colecciones en PHP) para poder paginar y
 * ordenar sin traer todo el historico a memoria.
 */
class CommunicationController extends Controller
{
    public function __construct(private BusinessCommunicationService $communications) {}

    public function index(Request $request): array
    {
        $channel = $request->string('channel')->toString();
        $businessId = $request->filled('business_id') ? $request->integer('business_id') : null;

        $email = DB::table('email_logs')->select([
            'id',
            DB::raw("'email' as channel"),
            'business_id',
            'type',
            'to_email as recipient',
            'subject',
            'status',
            'error',
            'created_at',
        ]);
        $whatsapp = DB::table('whatsapp_logs')->select([
            'id',
            DB::raw("'whatsapp' as channel"),
            'business_id',
            'type',
            'to_phone as recipient',
            DB::raw('NULL as subject'),
            'status',
            'error',
            'created_at',
        ]);

        if ($businessId !== null) {
            $email->where('business_id', $businessId);
            $whatsapp->where('business_id', $businessId);
        }

        $query = match ($channel) {
            'email' => $email,
            'whatsapp' => $whatsapp,
            default => $email->unionAll($whatsapp),
        };

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30)->withQueryString();

        $businessNames = Business::withoutGlobalScopes()
            ->whereIn('id', collect($page->items())->pluck('business_id')->filter()->unique())
            ->pluck('name', 'id');

        $page->through(fn ($row) => [
            // id no es unico entre las dos tablas (cada una arranca en 1) -
            // uid si lo es, para usar como key en listas del frontend.
            'uid' => "{$row->channel}-{$row->id}",
            'channel' => $row->channel,
            'business_id' => $row->business_id,
            'business_name' => $row->business_id ? ($businessNames[$row->business_id] ?? null) : null,
            'type' => $row->type,
            'recipient' => $row->recipient,
            'subject' => $row->subject,
            'status' => $row->status,
            'error' => $row->error,
            'created_at' => $row->created_at,
        ]);

        return [
            'data' => $page->items(),
            // Mismo shape que LengthAwarePaginator::toArray() (from/to
            // incluidos) para que el frontend reuse el mismo PaginatedResponse<T>
            // generico que ya usa para endpoints paginados con Eloquent.
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ];
    }

    public function send(SendBusinessCommunicationRequest $request, Business $business): JsonResponse
    {
        try {
            $this->communications->send(
                $business,
                $request->validated('channel'),
                $request->validated('subject'),
                $request->validated('message'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }
}
