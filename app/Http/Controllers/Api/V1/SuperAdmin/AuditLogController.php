<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SuperAdmin\LogActionResource;
use App\Support\AuditLogQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LogActionResource::collection(
            AuditLogQuery::forSuperadmin($request)->latest()->paginate(30)->withQueryString()
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'auditoria-global-'.now()->format('Y-m-d-His').'.csv';
        $query = AuditLogQuery::forSuperadmin($request)->orderBy('created_at')->orderBy('id')->limit(10000);

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Negocio ID', 'Fecha y hora', 'Accion', 'Usuario', 'Correo usuario', 'IP', 'URL', 'Metodo', 'Detalle (JSON)'], ';');

            foreach ($query->cursor() as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->business_id,
                    $row->created_at?->format('d/m/Y H:i:s'),
                    $row->action,
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
