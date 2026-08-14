<?php

namespace App\Models;

use App\Mail\ResetPasswordMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'last_name',
    'email',
    'password',
    'username',
    'identifier',
    'identifier_type',
    'gender',
    'cellphone',
    'google_id',
    'code',
    'external_id',
    'is_active',
    'business_id',
    'is_business_owner',
    'dashboard_shortcuts',
    'whatsapp_onboarding_dismissed_at',
])]
#[Hidden([
    'password',
    'plain_password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_business_owner' => 'boolean',
            'dashboard_shortcuts' => 'array',
            'whatsapp_onboarding_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Roles/permissions in this application were assigned under the "web"
     * guard by the legacy monolith. Keep that guard so existing
     * model_has_roles / model_has_permissions rows keep resolving correctly.
     */
    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'admin'));
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'client'));
    }

    public function scopeEmployee(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'employee'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Chequeo de permiso granular del catalogo (ver App\Support\PermissionCatalog)
     * que un admin siempre pasa (hereda todo por rol) y que no explota si el
     * permiso todavia no existe en la tabla permissions (p.ej. permissions:sync
     * no corrio despues de un deploy que agrego uno nuevo al catalogo) - eso es
     * "el usuario no lo tiene", no un error 500. Unica logica de este tipo en
     * la app: EnsureBusinessPermission (rutas) y cualquier FormRequest que
     * necesite gatear un campo puntual (no toda la ruta) la reutilizan en vez
     * de reimplementarla.
     */
    public function hasBusinessPermission(string $permission): bool
    {
        if ($this->hasRole('admin')) {
            return true;
        }

        try {
            return $this->hasPermissionTo($permission, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Ultima vez que el usuario uso un token valido - fuente real de "ultima
     * conexion", sin agregar una columna nueva: Sanctum ya actualiza
     * personal_access_tokens.last_used_at en cada request autenticado
     * (config sanctum.last_used_at, default true).
     */
    public function lastActiveAt(): ?Carbon
    {
        $lastUsedAt = $this->tokens()->max('last_used_at');

        return $lastUsedAt ? Carbon::parse($lastUsedAt) : null;
    }

    /**
     * Override del comportamiento por defecto de Laravel (dispararia una
     * Notification via el canal 'mail' generico) - esta API ya tiene su
     * propio patron de Mailable + Mail::send() para todo lo demas (ver
     * WelcomeMail, NewUserCredentialsMail), con logging automatico via
     * App\Listeners\LogSentEmail. Password::sendResetLink() sigue llamando
     * este metodo tal cual (es parte del contrato CanResetPassword), solo
     * cambia que hace con el token.
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new ResetPasswordMail($this, $token));
    }
}
