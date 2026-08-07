<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\UpdateEmployeePermissionsRequest;
use App\Http\Requests\Api\V1\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Mail\NewUserCredentialsMail;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Empleados y administradores adicionales de UN negocio, gestionados por su
 * propio admin. Distinto del SuperAdmin\UserController: aquel actua cross-tenant;
 * este solo ve los usuarios del negocio del admin autenticado.
 *
 * Rol employee y permisos DIRECTOS al usuario: a diferencia del rol admin (que
 * hereda todos los permisos del catalogo via su rol), un empleado no lleva
 * permisos por rol. Cada empleado tiene los suyos, asignados uno por uno con
 * syncPermissions() - asi dos empleados del mismo negocio pueden tener acceso
 * distinto.
 */
class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureEmployeeRoleHasNoPermissions();

        $users = User::where('business_id', $request->user()->business_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['employee', 'admin']))
            ->with('roles')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return EmployeeResource::collection($users);
    }

    public function catalog(): array
    {
        // Categorias + permisos ya filtrados a los que existen en la base:
        // agregar un checkbox de un permiso no creado fallaria al guardar.
        $existentes = Permission::pluck('name')->all();

        return [
            'categories' => PermissionCatalog::categoriasParaUI($existentes),
        ];
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        $this->ensureEmployeeRoleHasNoPermissions();

        $data = $request->validated();
        $role = $data['role'] ?? 'employee';
        $plainPassword = $data['password'];

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $plainPassword,
            'business_id' => $request->user()->business_id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        if ($role === 'employee') {
            $user->syncPermissions($data['permissions'] ?? []);
        }

        // Correo de credenciales, mismo diseño validado del legacy. Si el
        // mailer no esta configurado (dev sin SMTP) el envio falla silencioso
        // - la creacion del usuario no se debe bloquear por eso.
        try {
            Mail::to($user->email)->send(new NewUserCredentialsMail(
                $user, $request->user()->business, $plainPassword, $this->roleLabel($role),
            ));
        } catch (\Throwable) {
            //
        }

        // ->load() (no ->fresh()) para preservar wasRecentlyCreated - asi
        // Laravel responde 201, no 200.
        return new EmployeeResource($user->load('roles'));
    }

    public function update(UpdateEmployeeRequest $request, User $employee): EmployeeResource
    {
        $this->assertBelongsToSameBusiness($request, $employee);
        $this->ensureEmployeeRoleHasNoPermissions();

        $data = $request->validated();

        $updates = ['name' => $data['name'], 'email' => $data['email']];
        if (! empty($data['password'])) {
            $updates['password'] = $data['password'];
        }
        $employee->update($updates);

        $newRole = $data['role'] ?? $employee->roles->first()?->name ?? 'employee';
        $employee->syncRoles([$newRole]);

        if ($newRole === 'employee') {
            $perms = array_key_exists('permissions', $data)
                ? $data['permissions']
                : $employee->getDirectPermissions()->pluck('name')->toArray();
            $employee->syncPermissions($perms);
        } else {
            $employee->syncPermissions([]);
        }

        return new EmployeeResource($employee->fresh()->load('roles'));
    }

    public function updatePermissions(UpdateEmployeePermissionsRequest $request, User $employee): EmployeeResource
    {
        $this->assertBelongsToSameBusiness($request, $employee);
        $this->ensureEmployeeRoleHasNoPermissions();

        if (! $employee->hasRole('employee')) {
            throw ValidationException::withMessages(['role' => 'Solo se pueden actualizar permisos de empleados. Los admin heredan todos por rol.']);
        }

        $employee->syncPermissions($request->validated('permissions') ?? []);

        return new EmployeeResource($employee->fresh()->load('roles'));
    }

    public function toggle(Request $request, User $employee): EmployeeResource
    {
        $this->assertBelongsToSameBusiness($request, $employee);

        $employee->update(['is_active' => ! $employee->is_active]);

        return new EmployeeResource($employee->fresh()->load('roles'));
    }

    public function destroy(Request $request, User $employee): Response
    {
        $this->assertBelongsToSameBusiness($request, $employee);

        if ($employee->is_business_owner) {
            throw ValidationException::withMessages(['employee' => 'No puedes eliminar al dueño del negocio.']);
        }
        if ((int) $employee->id === (int) $request->user()->id) {
            throw ValidationException::withMessages(['employee' => 'No puedes eliminarte a ti mismo.']);
        }
        if ($employee->is_active) {
            throw ValidationException::withMessages(['employee' => 'Solo puedes eliminar empleados inactivos. Desactivalo primero.']);
        }

        $employee->delete();

        return response()->noContent();
    }

    private function assertBelongsToSameBusiness(Request $request, User $employee): void
    {
        abort_unless((int) $employee->business_id === (int) $request->user()->business_id, 404);
    }

    /**
     * Barrera de invariante: el rol employee nunca debe tener permisos. Todos
     * los permisos de un empleado van DIRECTOS al usuario. Si alguien (bug
     * futuro, tinker manual, migracion cruzada) le agrega uno al rol, un
     * empleado nuevo lo hereda sin querer y salta la logica de esta pantalla.
     */
    private function ensureEmployeeRoleHasNoPermissions(): void
    {
        $employeeRole = Role::query()
            ->where('name', 'employee')->where('guard_name', 'web')->first();

        if ($employeeRole && $employeeRole->permissions()->exists()) {
            $employeeRole->syncPermissions([]);
        }
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Administrador',
            'employee' => 'Empleado',
            default => ucfirst($role),
        };
    }
}
