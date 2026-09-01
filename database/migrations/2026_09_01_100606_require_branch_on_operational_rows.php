<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la invariante en la base para las dos tablas donde no basta con que
 * la aplicacion se porte bien.
 *
 * cash_closings es la urgente: su UNIQUE(business_id, date) significa "un
 * cierre de caja por dia por negocio", que con dos locales es directamente
 * imposible - el segundo en cerrar chocaria contra el indice. Pasa a
 * UNIQUE(business_id, branch_id, date): un cierre por dia POR SEDE.
 *
 * El orden importa y no es intercambiable. Con branch_id nullable, MySQL
 * considera distintas dos filas cuyo branch_id es NULL, asi que el indice
 * dejaria de proteger nada justo para las filas viejas. Por eso: backfill
 * primero, NOT NULL despues, indice al final.
 *
 * El backfill lo hace esta migracion y no se confia en que alguien haya
 * corrido branches:ensure-main: si el deploy corre migrate solo, esto tiene
 * que poder pararse en pie por si mismo. Lo que NO hace es crear sedes - si
 * queda algo sin sede, aborta con el comando exacto que hay que correr, en
 * vez de dejar a medias un cambio de esquema.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['stock_movements', 'cash_closings'];

    public function up(): void
    {
        // Crea las sedes principales que falten y reparte TODO lo operativo,
        // no solo las dos tablas de aca. Es imprescindible que corra dentro
        // de la migracion: en un despliegue nuevo ningun negocio tiene sede
        // todavia, asi que el backfill de abajo no encontraria a que sede
        // apuntar y esta migracion abortaria a mitad del deploy.
        //
        // Y aunque no abortara, dejar el resto de las tablas sin sede seria
        // peor: con el scope de sede ya activo, un negocio no veria sus
        // propias ventas hasta que alguien corriera el comando a mano.
        //
        // Se llama al comando en vez de repetir su SQL aca porque es
        // idempotente y esta cubierto por tests; dos implementaciones de lo
        // mismo es justo lo que hay que evitar en algo que corre una sola
        // vez y sin nadie mirando.
        Artisan::call('branches:ensure-main', ['--all' => true]);

        foreach (self::TABLES as $table) {
            $this->backfillFromMainBranch($table);
            $this->assertNothingLeftWithoutBranch($table);
        }

        foreach (self::TABLES as $table) {
            // La clave foranea nacio con nullOnDelete, que MySQL no admite
            // sobre una columna NOT NULL. Se recrea con restrict, que ademas
            // es la semantica correcta: una sede con ventas o movimientos
            // encima no se puede borrar en duro (la app la desactiva, ver
            // BranchController::deactivate).
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')->nullable(false)->change();
                $blueprint->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            });
        }

        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropUnique('cash_closings_business_id_date_unique');
            $table->unique(['business_id', 'branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'branch_id', 'date']);
            $table->unique(['business_id', 'date'], 'cash_closings_business_id_date_unique');
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')->nullable()->change();
                $blueprint->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }
    }

    private function backfillFromMainBranch(string $table): void
    {
        DB::statement("
            UPDATE {$table} AS t
            JOIN branches AS b ON b.business_id = t.business_id AND b.is_main = 1 AND b.deleted_at IS NULL
            SET t.branch_id = b.id
            WHERE t.branch_id IS NULL
        ");
    }

    private function assertNothingLeftWithoutBranch(string $table): void
    {
        $pending = DB::table($table)->whereNull('branch_id')->count();

        if ($pending === 0) {
            return;
        }

        throw new RuntimeException(
            "No se puede exigir sede en {$table}: quedan {$pending} filas sin sede porque su negocio no tiene ".
            'sede principal. Corre `php artisan branches:ensure-main --all` y vuelve a migrar.'
        );
    }
};
