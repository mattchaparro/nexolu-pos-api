<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Solo lectura de las plantillas de WhatsApp (Meta) y los WhatsApp Flows que
 * este POS sabe usar - lee directo de config/services.php (unica fuente de
 * verdad, ver comentario ahi) en vez de duplicar el listado en una tabla.
 * Pensada para que un superadmin vea de un vistazo cuales existen, cuales
 * faltan crear/aprobar en Meta y que dispara cada una, sin tener que leer
 * codigo. Editar el nombre real o agregar una nueva sigue siendo un cambio
 * de config/env + código (cada plantilla tiene sus variables hardcodeadas en
 * el sitio que la usa) - ver docs/WHATSAPP_TEMPLATES_PENDING.md para el
 * detalle de las que faltan crear.
 */
class WhatsAppTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = collect(config('services.whatsapp.templates', []))
            ->map(fn (array $template, string $key) => [
                'key' => $key,
                'name' => $template['name'] ?: null,
                'lang' => $template['lang'] ?? null,
                'description' => $template['description'] ?? null,
                'triggered_by' => $template['triggered_by'] ?? null,
                'status' => filled($template['name'] ?? null) ? 'configured' : 'pending',
            ])
            ->values();

        $flows = collect(config('services.whatsapp.flows', []))
            ->map(fn (array $flow, string $key) => [
                'key' => $key,
                'id' => $flow['id'] ?: null,
                'screen' => $flow['screen'] ?? null,
                'description' => $flow['description'] ?? null,
                'triggered_by' => $flow['triggered_by'] ?? null,
                'status' => filled($flow['id'] ?? null) ? 'configured' : 'pending',
            ])
            ->values();

        return response()->json(['templates' => $templates, 'flows' => $flows]);
    }
}
