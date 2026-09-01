<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sedes (sucursales) de un negocio.
 *
 * `business_id` sigue siendo el UNICO tenant: la sede no es un negocio
 * aparte, es una dimension operativa dentro de el. Por eso el catalogo, los
 * clientes, el fiado, la suscripcion y los feature flags NO llevan sede -
 * solo lo que fisicamente ocurre en un local (ventas, caja, inventario,
 * compras, mesas). Modelar cada sede como un `business` propio habria
 * partido en N el catalogo y los clientes, y convertido un traslado de
 * inventario en una escritura cross-tenant, que es justo lo que el check C7
 * de businesses:verify-migration trata como corrupcion.
 *
 * Todo negocio tiene al menos una sede (`is_main`), incluso el monosede: asi
 * el resto de la app no necesita dos caminos, y encender multisede despues
 * no obliga a migrar nada. Ver App\Console\Commands\EnsureMainBranch.
 *
 * `branch_user` existe desde el primer dia (y no una FK simple en `users`)
 * porque los empleados rotan entre sedes: es el caso comun, no la excepcion.
 * `users.default_branch_id` es solo con cual entra al abrir sesion.
 *
 * Los overrides de ticket/factura son nullable a proposito: NULL significa
 * "usa el del negocio" (businesses.invoice_prefix, ticket_*), no "vacio".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Codigo corto para distinguirlas en pantalla ("CC", "FAB").
            $table->string('code', 20)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);

            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp_number')->nullable();

            // Numeracion de factura por sede: cada local lleva su propio
            // consecutivo con su propio prefijo. NULL hereda el del negocio.
            $table->string('invoice_prefix', 10)->nullable();

            // Overrides de impresion: una sede puede tener otra impresora y
            // otra direccion en el tiquete. NULL hereda el del negocio.
            $table->string('ticket_paper_width', 3)->nullable();
            $table->string('ticket_header_tagline', 500)->nullable();
            $table->string('ticket_thanks_message', 500)->nullable();
            $table->text('ticket_footer_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Sede con la que entra al abrir sesion. nullOnDelete y no
            // cascade: borrar una sede no puede borrar al empleado.
            $table->foreignId('default_branch_id')->nullable()->after('business_id')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['default_branch_id']);
            $table->dropColumn('default_branch_id');
        });

        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
