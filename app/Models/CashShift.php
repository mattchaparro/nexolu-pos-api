<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CashShift extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Turno cerrado por el propio flujo de turnos (un humano en pantalla). */
    const CLOSED_VIA_MANUAL = 'manual';

    /** Turno cerrado como efecto del cierre de caja del dia. */
    const CLOSED_VIA_AUTO_CASH_CLOSING = 'auto_cash_closing';

    /**
     * Horas que un turno puede seguir abierto tras cambiar el dia calendario
     * antes de considerarse arrastrado.
     *
     * Cruzar la medianoche NO basta como senal: hay negocios nocturnos (bares)
     * cuya jornada normal empieza a las 5pm y termina a las 3am. 18h separa
     * los dos casos sin necesidad de configuracion: la jornada nocturna mas
     * larga (5pm-3am) son 10h y no dispara; un turno olvidado de un dia para
     * otro (9am al 9am siguiente) son 24h y si dispara.
     */
    private const STALE_AFTER_HOURS = 18;

    protected $fillable = [
        'business_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_cash',
        'opening_note',
        'counted_cash',
        'expected_cash',
        'difference',
        'closing_note',
        'payment_breakdown',
        'total_sales',
        'total_cash',
        'total_other_methods',
        'total_expenses',
        'closed_by_user_id',
        'closed_via',
        'closed_by_cash_closing_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'payment_breakdown' => 'array',
            'total_sales' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_other_methods' => 'decimal:2',
            'total_expenses' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quien cerro el turno; en un auto-cierre, el admin que hizo el cierre de caja. */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** El cierre de caja que provoco el auto-cierre, si aplica. */
    public function closedByCashClosing(): BelongsTo
    {
        return $this->belongsTo(CashClosing::class, 'closed_by_cash_closing_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Turno abierto que viene de un dia calendario anterior Y que lleva
     * abierto lo bastante como para que no sea una jornada nocturna normal.
     * Sus totales se calcularian sobre una ventana que abarca dos dias y el
     * cierre de caja del dia anterior los truncaria - el cajero debe
     * cerrarlo y abrir uno nuevo.
     */
    public function isFromAPreviousDay(): bool
    {
        if (! $this->isOpen() || $this->opened_at === null) {
            return false;
        }

        return $this->opened_at->lt(now()->startOfDay())
            && $this->opened_at->diffInHours(now()) >= self::STALE_AFTER_HOURS;
    }

    /**
     * Turnos que el cierre de caja de $date puede auto-cerrar: abiertos y que
     * ya existian al final de ese dia. Un turno abierto DESPUES de la fecha
     * que se esta cerrando (p.ej. el turno de hoy cuando se hace el cierre
     * atrasado de ayer) no le pertenece a ese cierre y debe quedar intacto.
     *
     * Fuente de verdad unica junto con CashClosingService::autoCloseOpenShifts:
     * si divergen, la UI anuncia un auto-cierre que el backend no hace - y el
     * cajero termina cerrando su turno a mano (bug real de negocio, ya
     * corregido en el legacy y preservado aqui).
     */
    public function scopeAutoClosableOn(Builder $query, string $date): Builder
    {
        $endOfDay = Carbon::parse($date)->endOfDay();
        $closeAt = self::closeAtFor($date);

        return $query
            ->whereNull('closed_at')
            ->where('opened_at', '<=', $endOfDay)
            // Y que el turno no siga EN USO despues del instante de cierre.
            //
            // Cerrar la caja de un dia vencido debe ser posible, pero no puede
            // tocar el dia en curso. Si el cajero abrio ayer y sigue vendiendo
            // hoy con ese mismo turno, cerrarlo con closed_at = fin de ayer
            // dejaria FUERA las ventas de hoy y lo expulsaria del POS a mitad
            // de jornada.
            //
            // Se mide por ventas del propio cajero porque es lo unico
            // atribuido a un usuario; el resto de ingresos (abonos, servicios)
            // no guarda user_id. Un turno sin ventas posteriores si se
            // auto-cierra: ese es el caso legitimo del turno olvidado.
            ->whereNotExists(function ($sub) use ($closeAt) {
                $sub->selectRaw('1')
                    ->from('sales')
                    ->whereColumn('sales.user_id', 'cash_shifts.user_id')
                    ->whereColumn('sales.business_id', 'cash_shifts.business_id')
                    ->where('sales.created_at', '>', $closeAt);
            });
    }

    /**
     * Instante con el que el cierre de $date cierra los turnos: el fin de ese
     * dia, o ahora si el dia todavia no ha terminado. Vive aqui para que el
     * scope y CashClosingService usen exactamente el mismo valor.
     */
    public static function closeAtFor(string $date): Carbon
    {
        $endOfDay = Carbon::parse($date)->endOfDay();

        return $endOfDay->lt(now()) ? $endOfDay : now();
    }
}
