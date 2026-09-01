<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sede (sucursal) de un negocio. Ver la migracion create_branches_tables
 * para por que la sede es una dimension dentro del negocio y no un negocio
 * aparte.
 *
 * Los campos de ticket y facturacion son overrides: NULL significa "usa el
 * del negocio", no "vacio". Por eso se leen siempre por los helpers de abajo
 * y nunca directo desde el atributo - un `$branch->invoice_prefix` crudo
 * devolveria null para la sede que no lo personalizo.
 */
#[Fillable([
    'business_id',
    'name',
    'code',
    'is_main',
    'is_active',
    'address',
    'phone',
    'whatsapp_number',
    'invoice_prefix',
    'ticket_paper_width',
    'ticket_header_tagline',
    'ticket_thanks_message',
    'ticket_footer_text',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToBusiness, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Una sola sede principal por negocio. MySQL no tiene indices
        // parciales, asi que la invariante se sostiene aca: la nueva
        // principal degrada a la anterior en vez de fallar. Es lo que quiere
        // el usuario que cambia cual es su sede principal, y evita que la
        // pantalla tenga que hacer dos escrituras coordinadas.
        $demoteSiblings = function (Branch $branch): void {
            if (! $branch->is_main || ! $branch->business_id) {
                return;
            }

            static::withoutGlobalScope('business')
                ->where('business_id', $branch->business_id)
                ->when($branch->exists, fn (Builder $query) => $query->whereKeyNot($branch->getKey()))
                ->where('is_main', true)
                ->update(['is_main' => false]);
        };

        static::created($demoteSiblings);
        static::updated($demoteSiblings);
    }

    /**
     * Empleados asignados a esta sede. Un admin o dueño no necesita fila
     * aqui: entra a todas las de su negocio (ver User::canAccessBranch()).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Prefijo del consecutivo de facturas de esta sede, o el del negocio. */
    public function invoicePrefix(): string
    {
        return $this->invoice_prefix ?: (string) ($this->business?->invoice_prefix ?? 'FAC');
    }

    /**
     * Configuracion de impresion resuelta: lo propio de la sede y, para todo
     * lo que no personalizo, lo del negocio.
     *
     * @return array{paper_width: string, header_tagline: ?string, thanks_message: ?string, footer_text: ?string, address: ?string, phone: ?string}
     */
    public function ticketConfig(): array
    {
        $business = $this->business;

        return [
            'paper_width' => $this->ticket_paper_width ?: (string) ($business?->ticket_paper_width ?? '80'),
            'header_tagline' => $this->ticket_header_tagline ?: $business?->ticket_header_tagline,
            'thanks_message' => $this->ticket_thanks_message ?: $business?->ticket_thanks_message,
            'footer_text' => $this->ticket_footer_text ?: $business?->ticket_footer_text,
            'address' => $this->address ?: $business?->address,
            'phone' => $this->phone ?: $business?->phone,
        ];
    }
}
