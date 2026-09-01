<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traslados de inventario entre sedes.
 *
 * Un traslado NO es una entrada ni una salida sueltas: son las dos, atadas.
 * Sin esta tabla, mover 10 unidades de la fabrica al centro comercial se veria
 * en el historial como una merma en un lado y un ingreso inexplicado en el
 * otro, y nadie podria auditar que las cantidades cuadran.
 *
 * En v1 el traslado es inmediato y atomico: los dos StockMovement se crean en
 * la misma transaccion y `status` nace en 'completed'. El estado "en transito"
 * (despachado en origen, pendiente de recibir en destino) queda para cuando un
 * negocio real lo pida; la columna ya existe para no tener que alterar la
 * tabla ese dia.
 *
 * Se usan motivos ('transfer_in'/'transfer_out') y no un valor nuevo en el
 * ENUM `type` de stock_movements a proposito: esa tabla viene del schema
 * legacy y ampliar su ENUM es justo el tipo de ALTER que CLAUDE.md pide
 * evitar. Los motivos son datos, no esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            // nullOnDelete: borrar al empleado no puede borrar el traslado.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('completed');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('transferred_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'transferred_at']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 14, 4);
            // Costo del momento: el traslado no cambia el costo promedio del
            // negocio, pero saber a que costo salio permite valorar lo que hay
            // en cada sede sin recalcular hacia atras.
            $table->decimal('unit_cost_cop', 14, 4)->nullable();

            $table->timestamps();
            $table->index('stock_transfer_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_transfer_id')->nullable()
                ->constrained('stock_transfers')->nullOnDelete();
        });

        // Motivos globales (business_id null), mismo patron que los 8 que ya
        // trae el schema legacy.
        $now = now();
        foreach ([
            ['code' => 'transfer_out', 'label' => 'Traslado a otra sede'],
            ['code' => 'transfer_in', 'label' => 'Traslado desde otra sede'],
        ] as $reason) {
            DB::table('stock_movement_reasons')->insertOrIgnore([
                'business_id' => null,
                'code' => $reason['code'],
                'label' => $reason['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('stock_movement_reasons')
            ->whereNull('business_id')
            ->whereIn('code', ['transfer_out', 'transfer_in'])
            ->delete();

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['stock_transfer_id']);
            $table->dropColumn('stock_transfer_id');
        });

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
