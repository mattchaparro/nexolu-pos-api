<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SupportContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contacto de soporte para el negocio autenticado.
 *
 * Reemplaza al modulo de tickets, que nadie uso nunca. El enlace se arma en
 * el backend y no en el frontend por dos razones: el numero es configurable
 * (system_config) y no puede quedar quemado en el bundle, y el mensaje sale
 * ya identificado - sin eso, soporte recibe un "hola" sin saber quien
 * escribe ni de que negocio.
 */
class SupportController extends Controller
{
    public function contact(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->business;

        $message = sprintf(
            'Hola, soy %s de %s (negocio #%d). Necesito ayuda con:',
            $user->name,
            $business?->name ?? 'mi negocio',
            $business?->id ?? 0,
        );

        return response()->json([
            'whatsapp_number' => SupportContact::whatsappNumber(),
            'whatsapp_url' => SupportContact::whatsappUrl($message),
        ]);
    }
}
