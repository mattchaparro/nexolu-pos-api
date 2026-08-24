<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * notification_schedule: solo overrides, una entrada por tipo
     * schedulable (ver NotificationTypes::SCHEDULABLE) - un negocio que
     * nunca lo toco no tiene la clave, y el default vive en
     * NotificationTypes::DEFAULT_HOURS (asi que cambiar el default de la
     * plataforma no exige tocar cada fila). last_daily_summary_sent_on /
     * last_low_stock_alert_sent_on son el dedup por negocio: los comandos
     * pasan de dailyAt() fijo a everyFiveMinutes() (ver routes/console.php)
     * para poder disparar cerca de la hora que cada negocio eligio, y sin
     * esto reevaluarian (y podrian reenviar) el mismo dia en cada corrida
     * de 5 minutos despues de esa hora.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('notification_schedule')->nullable()->after('notification_preferences');
            $table->date('last_daily_summary_sent_on')->nullable()->after('notification_schedule');
            $table->date('last_low_stock_alert_sent_on')->nullable()->after('last_daily_summary_sent_on');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['notification_schedule', 'last_daily_summary_sent_on', 'last_low_stock_alert_sent_on']);
        });
    }
};
