<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
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
}
