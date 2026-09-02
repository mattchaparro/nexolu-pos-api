<?php

namespace App\Capabilities\Clients;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tool: clientes_frecuentes. Quien gasta mas, quien viene mas y quien dejo de
 * venir.
 *
 * IMPORTANTE - por que no lee la tabla `customers`: el legacy resolvia esto
 * leyendo `customers`, donde su SaleService mantiene visits_count, total_spent
 * y last_sale_at denormalizados. Este POS no escribe esa tabla (no existe
 * siquiera un modelo Customer), asi que en un negocio ya migrado esos
 * contadores quedan congelados en la fecha del cutover: el chat responderia
 * "tu mejor cliente" con datos de hace meses, sin ninguna señal de que estan
 * viejos. Aca se calcula desde `sales`, que es la fuente real y funciona igual
 * antes y despues de migrar.
 *
 * La contrapartida es que la identidad del cliente sale de los datos de la
 * venta (telefono, cedula o nombre), no de un registro unico: es el mismo
 * criterio con el que el POS agrupa los fiados.
 */
class FrequentClientsCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'clients.manage';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'nombre_cliente' => ['sometimes', 'nullable', 'string', 'max:150'],
            'orden' => ['sometimes', 'nullable', 'in:mas_gastan,mas_visitan,hace_mas_que_no_vienen'],
            'minimo_visitas' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $order = $arguments['orden'] ?? 'mas_gastan';
        $limit = min((int) ($arguments['limite'] ?? 15), self::MAX_ROWS);

        $query = Sale::query()
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            // Una venta sin ningun dato de cliente no identifica a nadie:
            // agruparlas todas juntas daria un "cliente" gigante que en
            // realidad es el mostrador.
            ->where(function ($sub) {
                $sub->whereNotNull('customer_name')
                    ->orWhereNotNull('customer_phone')
                    ->orWhereNotNull('customer_identification');
            })
            ->selectRaw('COALESCE(NULLIF(customer_phone, ""), NULLIF(customer_identification, ""), customer_name) as customer_key')
            ->selectRaw('MAX(customer_name) as cliente')
            ->selectRaw('MAX(customer_phone) as telefono')
            ->selectRaw('COUNT(*) as visitas')
            ->selectRaw('SUM(total) as total_gastado')
            ->selectRaw('MAX(closed_at) as ultima_compra')
            ->groupBy('customer_key');

        if (! empty($arguments['nombre_cliente'])) {
            $like = '%'.addcslashes((string) $arguments['nombre_cliente'], '%_\\').'%';
            $query->where('customer_name', 'like', $like);
        }

        // "Hace mas que no vienen" pide un minimo de visitas (2 por defecto):
        // quien compro una sola vez nunca fue, en rigor, un cliente habitual
        // que "dejara de venir".
        if ($order === 'hace_mas_que_no_vienen') {
            $query->havingRaw('COUNT(*) >= ?', [(int) ($arguments['minimo_visitas'] ?? 2)])
                ->orderBy('ultima_compra');
        } else {
            $query->orderByDesc($order === 'mas_visitan' ? DB::raw('COUNT(*)') : DB::raw('SUM(total)'));
        }

        $rows = $query->limit($limit)->get();

        return [
            'orden' => $order,
            'clientes' => $rows->map(function ($row) {
                $visits = (int) $row->visitas;
                $spent = (float) $row->total_gastado;

                return [
                    'cliente' => $row->cliente ?: 'Sin nombre',
                    'telefono' => $row->telefono,
                    'visitas' => $visits,
                    'total_gastado' => round($spent, 2),
                    'ticket_promedio' => $visits > 0 ? round($spent / $visits, 2) : 0.0,
                    'ultima_compra' => $row->ultima_compra ? substr((string) $row->ultima_compra, 0, 10) : null,
                    'dias_sin_comprar' => $row->ultima_compra ? (int) now()->diffInDays($row->ultima_compra) : null,
                ];
            })->values()->all(),
            'nota' => 'Se calcula desde las ventas cerradas, agrupando por telefono, cedula o nombre '
                .'del cliente. Las ventas registradas sin ningun dato del cliente no aparecen.',
        ];
    }
}
