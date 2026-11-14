<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impresion automatica (comandera fisica, factura al cobrar, ticket de
 * domicilio): una cola de tickets en el servidor y un agente local por
 * restaurante (Nexolu Print Agent, servicio de Windows) que la consulta,
 * imprime en ESC/POS y confirma.
 *
 * Todo son tablas nuevas: no se toca `businesses` ni `sales`. La
 * configuracion de la factura vive en su propia tabla 1:1 con el negocio,
 * como business_store_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_print_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            // Factura impresa sola al cobrar (sin esto, solo se reimprime a mano).
            $table->boolean('auto_print_receipts')->default(true);
            // 1 = solo cliente, 2 = cliente + copia del local.
            $table->unsignedTinyInteger('receipt_copies')->default(1);
            // Abrir el cajon (conectado a la impresora del mostrador) cuando hay efectivo.
            $table->boolean('open_cash_drawer')->default(true);
            $table->timestamps();
        });

        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            // sha256 del token del agente. El token no se guarda: se entrega una sola vez al vincular.
            $table->string('token_hash', 64)->nullable()->unique();
            // sha256 del codigo de 6 digitos con el que el agente se vincula; vence en minutos.
            $table->string('pairing_code_hash', 64)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('version', 30)->nullable();
            // Impresoras instaladas en Windows que el agente reporta, para elegirlas desde el POS.
            $table->json('reported_printers')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // NULL = sirve a todas las sedes (el monosede).
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('print_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            // kitchen | counter
            $table->string('role', 20);
            // network (address = ip:puerto) | windows (address = nombre de la impresora en Windows)
            $table->string('connection', 20);
            $table->string('address', 191);
            $table->unsignedTinyInteger('paper_width')->default(80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'role']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            // Un ticket de anulacion sigue imprimiendose aunque la cuenta se haya borrado.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            // Bytes ESC/POS en base64: el servidor arma el ticket, el agente solo lo manda.
            $table->longText('payload');
            // pending | claimed | printed | failed
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['printer_id', 'status']);
            $table->index(['business_id', 'status']);
        });

        // Lo que la cocina ya recibio impreso de cada venta. Contra esto se
        // calcula la adicion o la anulacion: las lineas de la venta no sirven,
        // porque editar una cuenta borra y recrea todas sus lineas y agregar
        // un plato que ya estaba le suma cantidad a la linea existente.
        Schema::create('kitchen_sent_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            // producto + variante + opciones elegidas
            $table->string('signature', 191);
            $table->string('label', 255);
            $table->text('options_label')->nullable();
            // Piezas de un combo por unidad ([{name, quantity}]): la cocina
            // tiene que saber que lleva, tambien en una anulacion.
            $table->json('components')->nullable();
            $table->integer('quantity');
            $table->timestamps();
            $table->unique(['sale_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_sent_lines');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('print_agents');
        Schema::dropIfExists('business_print_settings');
    }
};
