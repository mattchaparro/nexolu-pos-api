<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sede en todo lo que fisicamente ocurre en un local.
 *
 * Deliberadamente NO llevan sede: el catalogo (products, variants,
 * categorias, ingredientes, recetas), los clientes, los proveedores, los
 * descuentos, la suscripcion ni la tienda online - todo eso es del negocio y
 * se comparte entre sedes. El precio SI puede variar por sede, pero via una
 * tabla de overrides (F2), no partiendo el producto en dos.
 *
 * Las tablas hijas (sale_items, purchase_lines, layaway_items,
 * service_order_items, sale_payment_splits...) tampoco la llevan: heredan la
 * sede por su padre, igual que hoy heredan el tenant.
 *
 * Todas nullable: es la unica forma de que esto sea aditivo sobre tablas del
 * schema legacy, y ademas lo que permite que BusinessDataExporter siga
 * migrando negocios sin un solo cambio (filtra por las columnas que existen
 * en destino, asi que simplemente no las llena). El backfill y el paso a NOT
 * NULL van despues, por tabla, cuando el modulo se vuelva multisede de
 * verdad - ver App\Console\Commands\EnsureMainBranch.
 *
 * `receivables.branch_id` es informativo (sede donde se origino el fiado): la
 * deuda es con el negocio, no con el local, pero el reporte de "cuanto fio
 * cada sede" lo necesita.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
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

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')->nullable()
                    ->constrained('branches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['branch_id']);
                $blueprint->dropColumn('branch_id');
            });
        }
    }
};
