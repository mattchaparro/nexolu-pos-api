<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBranchRequest;
use App\Http\Requests\Api\V1\UpdateBranchRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use App\Models\CashShift;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = Branch::create([
            ...$request->validated(),
            'business_id' => $request->user()->business_id,
            // La principal se crea con el negocio y no se elige aqui: es la
            // que hereda sus datos y la que recibe todo lo que no tiene sede.
            'is_main' => false,
        ]);

        // Quien la crea entra a ella de una: si no, un admin acabaria de
        // abrir una sede a la que no puede cambiarse.
        $request->user()->branches()->syncWithoutDetaching([$branch->id]);

        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $data = $request->validated();

        // Desactivar la sede donde alguien tiene la caja abierta dejaria ese
        // turno sin poder cerrarse: no se puede vender ni arquear en una sede
        // apagada, y el dinero de la jornada queda en el limbo.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->assertCanBeDeactivated($branch);
        }

        $branch->update($data);

        return new BranchResource($branch->fresh());
    }

    /**
     * No hay borrado: una sede tiene ventas, cajas e inventario colgando, y
     * borrarla se llevaria por delante la historia del negocio. Se desactiva,
     * que es lo que el usuario quiere decir con "cerramos ese punto".
     */
    public function deactivate(Request $request, Branch $branch): BranchResource
    {
        $this->assertCanBeDeactivated($branch);

        $branch->update(['is_active' => false]);

        return new BranchResource($branch->fresh());
    }

    private function assertCanBeDeactivated(Branch $branch): void
    {
        if ($branch->is_main) {
            throw ValidationException::withMessages([
                'branch' => 'La sede principal no se puede desactivar. Marca otra como principal primero.',
            ]);
        }

        $openShifts = CashShift::withoutGlobalScope('branch')
            ->where('branch_id', $branch->id)
            ->whereNull('closed_at')
            ->count();

        if ($openShifts > 0) {
            throw ValidationException::withMessages([
                'branch' => "No se puede desactivar {$branch->name}: tiene {$openShifts} turno(s) de caja sin cerrar.",
            ]);
        }
    }
}
