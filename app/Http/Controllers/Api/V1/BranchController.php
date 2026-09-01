<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las sedes a las que este usuario puede entrar, mas cual tiene activa.
 *
 * Es lo unico que necesita el selector de sucursal de la barra superior: el
 * front lo llama una vez al iniciar sesion y despues manda la sede elegida en
 * el header X-Branch-Id (ver App\Http\Middleware\ResolveBranch), asi que
 * ningun otro endpoint tiene que recibirla como parametro.
 *
 * Devuelve solo las accesibles, no todas las del negocio: un cajero asignado
 * a una sede no debe siquiera ver el nombre de las demas en un desplegable
 * que no puede usar. La administracion de sedes (crear/editar) es otra cosa y
 * vive en su propia pantalla.
 */
class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $branches = Branch::query()
            ->where('business_id', $user->business_id)
            ->active()
            ->when(
                ! $user->canManageAllBranches(),
                fn ($query) => $query->whereIn('id', $user->branches()->select('branches.id'))
            )
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => BranchResource::collection($branches)->resolve(),
            'current_branch_id' => BranchContext::branchId(),
            'all_branches' => BranchContext::isAllBranches(),
            // Quien puede pedir el consolidado (X-Branch-Id: all) y quien no,
            // para que el front no ofrezca una opcion que va a dar 403.
            'can_view_all_branches' => $user->canManageAllBranches(),
        ]);
    }
}
