<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Difusiones del superadmin a los negocios: un aviso que el admin ve al
 * entrar al POS y, si se pide, un WhatsApp al numero del negocio.
 *
 * `announcements` viene del legacy (titulo, texto, boton, audiencia por rol)
 * pero nada lo mostraba. Se le agrega A QUE NEGOCIOS va (en uso, activos o
 * elegidos) y el resultado del envio por WhatsApp. El legacy ya no escribe en
 * esta tabla (se apago el 5-nov), asi que ampliarla no le rompe nada.
 *
 * `announcement_dismissals`: quien ya lo vio y lo cerro, para no volver a
 * mostrarselo en cada entrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // in_use: activos y al dia (prueba, pago o gracia); active: todos los activos; selected: business_ids.
            $table->string('business_scope', 20)->default('in_use')->after('audience');
            $table->json('business_ids')->nullable()->after('business_scope');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('active');
            $table->timestamp('whatsapp_requested_at')->nullable()->after('created_by_user_id');
            $table->unsignedInteger('whatsapp_sent')->default(0)->after('whatsapp_requested_at');
            $table->unsignedInteger('whatsapp_failed')->default(0)->after('whatsapp_sent');
        });

        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['business_scope', 'business_ids', 'created_by_user_id', 'whatsapp_requested_at', 'whatsapp_sent', 'whatsapp_failed']);
        });
    }
};
