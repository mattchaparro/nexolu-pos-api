<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\BusinessRegistrationService;
use App\Support\AuditLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private BusinessRegistrationService $businessRegistrationService) {}

    /** Alta de un negocio nuevo y su dueño; responde igual que login() para poder iniciar sesion de inmediato. */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $this->businessRegistrationService->register($request->validated());
        $user = $data['user'];

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw new AuthenticationException('Las credenciales son incorrectas.');
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Si el token que se cierra es de impersonacion, es un superadmin
        // saliendo de "ver como" - ver ImpersonateController::start(). El
        // inicio ya queda auditado; sin esto, el cierre no dejaba rastro.
        if (str_starts_with((string) $token->name, ImpersonateController::TOKEN_NAME_PREFIX)) {
            AuditLogger::log('superadmin.impersonation.ended', [
                'business_id' => $user->business_id,
                'impersonated_user_id' => $user->id,
                'superadmin_id' => (int) substr((string) $token->name, strlen(ImpersonateController::TOKEN_NAME_PREFIX)),
            ]);
        }

        $token->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    /** Perfil propio (nombre, apellido, correo, celular) - nunca la contraseña, eso va por updatePassword(). */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user->fresh()->load('roles'));
    }

    /**
     * Cambio de contraseña autoservicio: a diferencia de
     * EmployeeController::update() (un admin reseteando la de otro), exige
     * la contraseña actual - es el propio usuario cambiando la suya.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['La contraseña actual no es correcta.']]);
        }

        $user->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Tu contraseña se actualizó correctamente.']);
    }

    /**
     * Dispara el correo con el enlace de restablecer (User::sendPasswordResetNotification).
     * Respuesta genérica siempre, exista o no ese correo - a diferencia del
     * broker de contraseñas por defecto de Laravel (que legacy también usa
     * sin modificar), que responde distinto según si el correo existe,
     * permitiendo confirmar qué direcciones están registradas.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [$this->passwordResetErrorMessage($status)]]);
        }

        return response()->json(['message' => 'Tu contraseña se actualizó correctamente.']);
    }

    /**
     * Esta app no tiene archivos de lang/ (Laravel 13 ya no los publica por
     * defecto) - __($status) devolvería literalmente 'passwords.token' en
     * vez de un mensaje, así que se traducen los 3 estados de error del
     * broker de contraseñas a mano en vez de depender de traducciones que
     * no existen.
     */
    private function passwordResetErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'No encontramos una cuenta con ese correo.',
            Password::INVALID_TOKEN => 'Este enlace ya no es válido o expiró. Solicita uno nuevo.',
            Password::RESET_THROTTLED => 'Ya solicitaste un restablecimiento hace poco. Espera un momento e intenta de nuevo.',
            default => 'No pudimos restablecer tu contraseña.',
        };
    }
}
