<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta y normalizacion de sedes. Unico lugar donde nace una sede principal,
 * para que el registro publico, el alta manual del superadmin y el backfill
 * de un negocio ya migrado lleguen exactamente al mismo resultado.
 *
 * La invariante que sostiene todo lo demas: TODO negocio tiene una sede
 * principal, tambien el que nunca abrira una segunda. Sin ella habria que
 * escribir dos veces cada consulta operativa ("filtra por sede, salvo que el
 * negocio no tenga sedes"), y encender multisede seria una migracion de datos
 * en vez de un feature flag.
 */
class BranchService
{
    /**
     * Tablas donde una fila pertenece a un local concreto. Es la lista viva:
     * cuando un modulo se vuelve multisede, su tabla entra aca y el backfill
     * la cubre desde la siguiente corrida.
     *
     * @var list<string>
     */
    public const OPERATIONAL_TABLES = [
        'sales',
        'cash_shifts',
        'cash_closings',
        'stock_movements',
        'purchases',
        'expenses',
        'business_tables',
        'appointments',
        'service_orders',
        'layaways',
        'receivables',
        'orders',
        'business_payment_terminals',
    ];

    /**
     * La sede principal del negocio, creandola si no existe.
     *
     * Idempotente a proposito: lo llama el registro (donde nunca existe) y el
     * backfill de negocios ya migrados (donde puede existir de una corrida
     * anterior). Si el negocio tiene sedes pero ninguna marcada como
     * principal - estado que solo se alcanza borrandola a mano - promueve la
     * mas antigua en vez de crear una duplicada.
     */
    public function ensureMainBranch(Business $business): Branch
    {
        $branches = Branch::withoutGlobalScope('business')->where('business_id', $business->id);

        if ($main = (clone $branches)->where('is_main', true)->first()) {
            return $main;
        }

        if ($oldest = (clone $branches)->orderBy('id')->first()) {
            $oldest->update(['is_main' => true]);

            return $oldest;
        }

        return Branch::create([
            'business_id' => $business->id,
            'name' => 'Sede principal',
            'is_main' => true,
            'is_active' => true,
            // Se copian, no se referencian: desde que existe la sede, la
            // direccion y el telefono que salen en el tiquete son los del
            // local. Los del negocio quedan como el dato fiscal/comercial.
            'address' => $business->address,
            'phone' => $business->phone,
            'whatsapp_number' => $business->whatsapp_number,
        ]);
    }

    /**
     * Id de la sede principal del negocio, creandola si hace falta.
     *
     * Es el ultimo recurso de todo lo que necesita una sede y no la recibio
     * del contexto del request (comandos, jobs, seeders). Sostiene la
     * invariante sin obligar a cada uno de esos caminos a saber de sedes.
     */
    public function mainBranchId(int $businessId): ?int
    {
        $mainBranchId = Branch::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('is_main', true)
            ->value('id');

        if ($mainBranchId) {
            return (int) $mainBranchId;
        }

        $business = Business::withoutGlobalScopes()->find($businessId);

        return $business ? $this->ensureMainBranch($business)->id : null;
    }

    /**
     * Deja al negocio consistente: sede principal, empleados asignados,
     * ninguna fila operativa sin sede y todo su inventario contabilizado en
     * una sede.
     *
     * @return array{branch: Branch, users: int, rows: array<string, int>, stock: array<string, int>}
     */
    public function backfill(Business $business, bool $dryRun = false): array
    {
        $branch = $dryRun
            ? ($this->existingMainBranch($business) ?? new Branch(['business_id' => $business->id, 'name' => 'Sede principal']))
            : $this->ensureMainBranch($business);

        return [
            'branch' => $branch,
            'users' => $this->assignUsers($business, $branch, $dryRun),
            'rows' => $this->assignOperationalRows($business, $branch, $dryRun),
            'stock' => $this->seedBranchStock($business, $branch, $dryRun),
        ];
    }

    /**
     * Lleva el saldo que hoy vive en la columna del catalogo a la sede
     * principal. Es el paso que convierte "el negocio tiene 10 unidades" en
     * "hay 10 unidades en la sede principal", que es lo unico que sabemos con
     * certeza de un negocio que hasta ayer era monosede.
     *
     * Solo siembra objetivos que no tienen NINGUNA fila en branch_stocks: si
     * un producto ya se repartio entre sedes, volver a correr esto no puede
     * duplicarle el saldo. Por eso es seguro repetirlo despues de crear
     * productos nuevos.
     *
     * @return array<string, int> filas sembradas por tipo de objetivo
     */
    private function seedBranchStock(Business $business, Branch $branch, bool $dryRun): array
    {
        $sources = [
            'products' => ['table' => 'products', 'column' => 'product_id', 'soft_deletes' => true],
            'product_variants' => ['table' => 'product_variants', 'column' => 'product_variant_id', 'soft_deletes' => true],
            'ingredients' => ['table' => 'ingredients', 'column' => 'ingredient_id', 'soft_deletes' => false],
        ];

        $seeded = [];

        foreach ($sources as $label => $source) {
            $pending = DB::table($source['table'].' as source')
                ->where('source.business_id', $business->id)
                ->when($source['soft_deletes'], fn ($query) => $query->whereNull('source.deleted_at'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('branch_stocks')
                    ->whereColumn('branch_stocks.'.$source['column'], 'source.id')
                );

            if ($dryRun || ! $branch->exists) {
                $count = (clone $pending)->count();

                if ($count > 0) {
                    $seeded[$label] = $count;
                }

                continue;
            }

            $count = DB::table('branch_stocks')->insertUsing(
                ['business_id', 'branch_id', $source['column'], 'stock', 'created_at', 'updated_at'],
                (clone $pending)->select([
                    'source.business_id',
                    DB::raw($branch->id.' as branch_id'),
                    'source.id',
                    'source.stock',
                    DB::raw('NOW() as created_at'),
                    DB::raw('NOW() as updated_at'),
                ])
            );

            if ($count > 0) {
                $seeded[$label] = $count;
            }
        }

        return $seeded;
    }

    private function existingMainBranch(Business $business): ?Branch
    {
        return Branch::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    /**
     * Todos los empleados del negocio quedan asignados a la sede principal y
     * con ella por defecto. Es lo correcto para el monosede (es su unico
     * local) y el punto de partida razonable para el que abre una segunda:
     * el admin quita a quien se mude, en vez de tener que asignarlos a todos
     * de cero y descubrir a mitad de dia que un cajero no puede entrar.
     */
    private function assignUsers(Business $business, Branch $branch, bool $dryRun): int
    {
        $userIds = User::where('business_id', $business->id)->pluck('id');

        if ($userIds->isEmpty() || ! $branch->exists) {
            return $dryRun ? $userIds->count() : 0;
        }

        $missing = $userIds->diff($branch->users()->pluck('users.id'));

        if ($dryRun) {
            return $missing->count();
        }

        $branch->users()->syncWithoutDetaching($missing->all());

        User::where('business_id', $business->id)
            ->whereNull('default_branch_id')
            ->update(['default_branch_id' => $branch->id]);

        return $missing->count();
    }

    /**
     * @return array<string, int> filas actualizadas por tabla (solo las que tenian alguna)
     */
    private function assignOperationalRows(Business $business, Branch $branch, bool $dryRun): array
    {
        $updated = [];

        foreach (self::OPERATIONAL_TABLES as $table) {
            $pending = DB::table($table)
                ->where('business_id', $business->id)
                ->whereNull('branch_id');

            $count = $dryRun ? (clone $pending)->count() : 0;

            if (! $dryRun) {
                $count = $pending->update(['branch_id' => $branch->id]);
            }

            if ($count > 0) {
                $updated[$table] = $count;
            }
        }

        return $updated;
    }
}
