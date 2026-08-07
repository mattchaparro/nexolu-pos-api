<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StoreUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Usuarios de cualquier negocio, vistos y administrados desde SuperAdmin.
 * Sirve tanto la pantalla global de Usuarios como la pestana "Usuarios" de un
 * negocio puntual (filtrando por business_id) - una sola fuente para no
 * duplicar la logica de creacion/reseteo entre las dos pantallas.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['roles', 'business']);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->integer('business_id'));
        }

        return UserResource::collection($query->latest()->paginate(20)->withQueryString());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'business_id' => $data['business_id'],
            'is_active' => true,
        ]);
        $user->assignRole($data['role']);

        AuditLogger::log('superadmin.user.created', ['business_id' => $user->business_id, 'user_id' => $user->id, 'role' => $data['role']]);

        return new UserResource($user->load('roles', 'business'));
    }

    public function toggle(User $user): UserResource
    {
        $user->update(['is_active' => ! $user->is_active]);

        AuditLogger::log('superadmin.user.toggled', ['business_id' => $user->business_id, 'user_id' => $user->id, 'is_active' => $user->is_active]);

        return new UserResource($user->load('roles', 'business'));
    }

    /** Genera una contrasena nueva y la devuelve UNA vez en la respuesta - nunca se persiste en claro. */
    public function resetPassword(User $user): array
    {
        $newPassword = Str::random(10);
        $user->update(['password' => $newPassword]);

        AuditLogger::log('superadmin.user.password_reset', ['business_id' => $user->business_id, 'user_id' => $user->id]);

        return ['password' => $newPassword];
    }
}
