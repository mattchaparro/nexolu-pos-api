<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Difusion del superadmin a los negocios: aviso dentro del POS (el usuario lo
 * ve al entrar hasta que lo cierra) y, opcional, WhatsApp al numero del
 * negocio (ver SendAnnouncementWhatsApp).
 *
 * `audience` es el ROL que lo ve (all/admin/employee) y `business_scope` A QUE
 * NEGOCIOS va: los que estan en uso (activos y al dia), todos los activos, o
 * los elegidos en `business_ids`.
 */
#[Fillable([
    'title', 'body', 'cta_label', 'cta_url', 'audience', 'business_scope', 'business_ids', 'active',
    'created_by_user_id', 'whatsapp_requested_at', 'whatsapp_sent', 'whatsapp_failed',
])]
class Announcement extends Model
{
    use HasFactory;

    public const SCOPE_IN_USE = 'in_use';

    public const SCOPE_ACTIVE = 'active';

    public const SCOPE_SELECTED = 'selected';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'business_ids' => 'array',
            'whatsapp_requested_at' => 'datetime',
            'whatsapp_sent' => 'integer',
            'whatsapp_failed' => 'integer',
        ];
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }

    /**
     * Negocios a los que llega, segun el alcance.
     *
     * @return Collection<int, Business>
     */
    public function targetBusinesses(): Collection
    {
        return self::businessesFor($this->business_scope, $this->business_ids ?? []);
    }

    /**
     * @param  list<int>  $businessIds
     * @return Collection<int, Business>
     */
    public static function businessesFor(string $scope, array $businessIds = []): Collection
    {
        $query = Business::query()->where('active', true)->orderBy('name');

        if ($scope === self::SCOPE_SELECTED) {
            return $query->whereIn('id', $businessIds)->get();
        }

        $businesses = $query->get();

        return $scope === self::SCOPE_IN_USE
            ? $businesses->filter(fn (Business $b) => $b->isInUse())->values()
            : $businesses;
    }

    public function reachesBusiness(Business $business): bool
    {
        if (! $business->active) {
            return false;
        }

        return match ($this->business_scope) {
            self::SCOPE_SELECTED => in_array($business->id, array_map('intval', $this->business_ids ?? []), true),
            self::SCOPE_IN_USE => $business->isInUse(),
            default => true,
        };
    }

    public function reachesRole(User $user): bool
    {
        $isAdmin = $user->hasRole('admin');

        return match ($this->audience) {
            'admin' => $isAdmin,
            'employee' => ! $isAdmin,
            default => true,
        };
    }
}
