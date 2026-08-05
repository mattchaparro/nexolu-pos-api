<?php

namespace App\Http\Controllers\Api;

use App\Capabilities\Registry;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Unico punto de entrada por el que el Nexolu IA Core (servicio Python
 * externo, repo aparte) ejecuta una capacidad del negocio. Contrato fijo,
 * no se agregan rutas por herramienta - ver
 * core/tools/dispatch_client.py del lado del IA Core.
 *
 * Autenticado por API key de aplicacion (middleware ia-core.key), no
 * Sanctum: el IA Core no tiene sesion de usuario propia, solo afirma quien
 * pregunta via el bloque "context" del body. Por eso este controller vuelve
 * a resolver y validar ese contexto contra la base de datos en vez de
 * confiar en el a ciegas - "Laravel sigue siendo la unica fuente de
 * verdad" del negocio y los permisos.
 */
class AiToolInvokeController extends Controller
{
    public function __construct(private Registry $registry) {}

    public function invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tool' => ['required', 'string'],
            'arguments' => ['sometimes', 'array'],
            'context' => ['required', 'array'],
            'context.business_id' => ['required'],
            'context.user_id' => ['required'],
        ]);

        $business = Business::find($validated['context']['business_id']);
        $user = User::find($validated['context']['user_id']);

        if (! $business || ! $user || (int) $user->business_id !== (int) $business->id || ! $user->is_active) {
            return response()->json(['error' => 'Contexto invalido: el negocio y el usuario no coinciden.'], 422);
        }

        $capability = $this->registry->resolve($validated['tool']);
        if (! $capability) {
            return response()->json(['error' => "Herramienta '{$validated['tool']}' no reconocida."], 404);
        }

        if ($capability->requiredFeature() && ! $business->hasFeature($capability->requiredFeature())) {
            return response()->json(['error' => 'Este negocio no tiene habilitada esa funcion.'], 403);
        }

        if ($capability->requiredPermission()
            && ! $user->hasRole('admin')
            && ! $user->hasPermissionTo($capability->requiredPermission(), 'web')) {
            return response()->json(['error' => 'El usuario no tiene permiso para esta accion.'], 403);
        }

        $argumentsValidator = Validator::make($validated['arguments'] ?? [], $capability->rules());
        if ($argumentsValidator->fails()) {
            return response()->json(['error' => $argumentsValidator->errors()->first()], 422);
        }

        // A partir de aqui el resto del codigo (Services, traits como
        // BelongsToBusiness) confia en auth()->user() igual que en
        // cualquier request normal autenticado con Sanctum.
        Auth::setUser($user);

        try {
            $data = $capability->execute($business, $user, $argumentsValidator->validated());
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }
}
