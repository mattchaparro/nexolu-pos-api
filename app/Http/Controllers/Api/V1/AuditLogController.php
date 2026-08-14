<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LogActionResource;
use App\Support\AuditActionDictionary;
use App\Support\AuditLogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auditoria del negocio - misma tabla log_actions que SuperAdmin\AuditLogController,
 * pero acotada al business_id del usuario (ver AuditLogQuery::forBusiness()).
 * Solo cubre las acciones instrumentadas via AuditLogger::log() en los
 * servicios/controllers de venta, caja, gastos, productos y contabilidad -
 * ver AuditActionDictionary para la lista completa.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LogActionResource::collection(
            AuditLogQuery::forBusiness($request, $request->user()->business_id)
                ->latest()
                ->paginate(30)
                ->withQueryString()
        );
    }

    public function actions(): JsonResponse
    {
        return response()->json(AuditActionDictionary::all());
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'auditoria-'.now()->format('Y-m-d-His').'.csv';
        $query = AuditLogQuery::forBusiness($request, $request->user()->business_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(10000);

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Fecha y hora', 'Accion', 'Usuario', 'Correo usuario', 'IP', 'URL', 'Metodo', 'Detalle (JSON)'], ';');

            foreach ($query->cursor() as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->created_at?->format('d/m/Y H:i:s'),
                    AuditActionDictionary::label($row->action),
                    $row->user?->name ?? '',
                    $row->user?->email ?? '',
                    $row->ip,
                    $row->url,
                    $row->method,
                    json_encode($row->details ?? [], JSON_UNESCAPED_UNICODE),
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
