<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una impresora termica del local y lo que imprime (su rol).
 *
 * El agente es quien la alcanza: por red (ip:puerto, casi siempre 9100) o
 * por la cola de Windows (nombre de la impresora instalada, para USB).
 */
#[Fillable(['business_id', 'branch_id', 'print_agent_id', 'name', 'role', 'connection', 'address', 'paper_width', 'is_active'])]
class Printer extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Comandas de cocina. */
    public const ROLE_KITCHEN = 'kitchen';

    /** Facturas y tickets de domicilio, con el cajon conectado. */
    public const ROLE_COUNTER = 'counter';

    public const ROLES = [self::ROLE_KITCHEN, self::ROLE_COUNTER];

    public const CONNECTION_NETWORK = 'network';

    public const CONNECTION_WINDOWS = 'windows';

    public const CONNECTIONS = [self::CONNECTION_NETWORK, self::CONNECTION_WINDOWS];

    public const PAPER_WIDTHS = [58, 80];

    /** Los mismos defaults de la columna, para que la respuesta al crear no los traiga en null. */
    protected $attributes = [
        'paper_width' => 80,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'paper_width' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'print_agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /** Caracteres por linea en fuente normal: 48 en 80 mm, 32 en 58 mm. */
    public function charsPerLine(): int
    {
        return $this->paper_width === 58 ? 32 : 48;
    }
}
