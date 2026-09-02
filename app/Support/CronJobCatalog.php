<?php

namespace App\Support;

/**
 * Fuente unica de verdad de los jobs programados visibles para el
 * superadmin (ver SuperAdmin\CronJobController) - agregar un job es tocar
 * UN array, en UN archivo, igual que App\Support\PermissionCatalog. Las
 * claves coinciden con las que usan las llamadas a CronJobLogger::attachTo()
 * en routes/console.php.
 *
 * `queue:work` y `database:backup` de legacy no tienen entrada aca: son
 * infraestructura de su hosting propio (droplet de 1 core sin
 * queue:work persistente, backups a disco local), no aplica a esta API -
 * ver docs/MIGRATION_BACKLOG.md.
 */
class CronJobCatalog
{
    /**
     * @var list<array{key: string, name: string, description: string, schedule: string, command: string}>
     */
    private const JOBS = [
        [
            'key' => 'subscription_expiring',
            'name' => 'Avisos de suscripcion por vencer',
            'description' => 'Notifica por correo a negocios cuya suscripcion vence en 3 dias.',
            'schedule' => 'Diario a las 8:30 AM',
            'command' => 'subscriptions:notify-expiring',
        ],
        [
            'key' => 'trial_expiring',
            'name' => 'Aviso de prueba por vencer',
            'description' => 'Envia correo a negocios en prueba gratuita que vence en 3 dias y en 1 dia para que activen un plan.',
            'schedule' => 'Diario a las 9:00 AM',
            'command' => 'trials:notify-expiring',
        ],
        [
            'key' => 'online_orders_expire',
            'name' => 'Vencer pedidos online sin confirmar',
            'description' => 'Libera la reserva de stock de los pedidos de la tienda que nadie confirmo en 24h.',
            'schedule' => 'Cada 10 minutos',
            'command' => 'orders:expire-stale',
        ],
        [
            'key' => 'abandoned_cart_reminders',
            'name' => 'Recordar carritos abandonados',
            'description' => 'Escribe una sola vez a quien dejo el carrito lleno y dejo como contactarlo, y poda los carritos viejos.',
            'schedule' => 'Cada 15 minutos',
            'command' => 'carts:send-abandoned-reminders',
        ],
        [
            'key' => 'audit_prune',
            'name' => 'Limpieza de logs de auditoria',
            'description' => 'Elimina registros de auditoria vencidos segun la retencion configurada.',
            'schedule' => 'Diario a las 3:15 AM',
            'command' => 'audit:prune',
        ],
        [
            'key' => 'appointment_reminders',
            'name' => 'Recordatorios de citas',
            'description' => 'Envia recordatorio 24h antes de cada cita confirmada o pendiente.',
            'schedule' => 'Diario a las 9:00 AM',
            'command' => 'appointments:send-reminders',
        ],
        [
            'key' => 'appointment_two_hour_reminders',
            'name' => 'Recordatorio de cita (2h antes) por WhatsApp',
            'description' => 'Avisa por WhatsApp al cliente que su cita es en 2 horas.',
            'schedule' => 'Cada 5 minutos',
            'command' => 'appointments:send-two-hour-reminders',
        ],
        [
            'key' => 'low_stock_alerts',
            'name' => 'Alertas de inventario bajo',
            'description' => 'Avisa por correo y/o WhatsApp a negocios con productos o ingredientes bajo el umbral minimo configurado.',
            'schedule' => 'Diario a las 8:00 AM',
            'command' => 'inventory:send-low-stock-alerts',
        ],
        [
            'key' => 'reminder_whatsapp',
            'name' => 'Recordatorios del Planificador por WhatsApp',
            'description' => 'Avisa por WhatsApp los recordatorios con hora y aviso activado, cuando llega esa hora.',
            'schedule' => 'Cada 5 minutos',
            'command' => 'reminders:send-whatsapp-notifications',
        ],
        [
            'key' => 'scheduled_expenses',
            'name' => 'Gastos programados',
            'description' => 'Registra automaticamente los gastos recurrentes configurados por cada negocio.',
            'schedule' => 'Diario a las 12:05 AM',
            'command' => 'expenses:register-scheduled',
        ],
        [
            'key' => 'daily_whatsapp_summary',
            'name' => 'Resumen diario por WhatsApp',
            'description' => 'Envia el resumen diario del negocio por WhatsApp a los admins que lo activaron.',
            'schedule' => 'Diario a las 8:00 PM',
            'command' => 'notifications:send-daily-whatsapp-summary',
        ],
        [
            'key' => 'trial_winback',
            'name' => 'Reactivacion de pruebas abandonadas',
            'description' => 'Envia correo amigable a negocios que crearon una prueba pero no regresaron.',
            'schedule' => 'Lunes a las 10:00 AM',
            'command' => 'businesses:send-trial-winback',
        ],
        [
            'key' => 'inactive_trial_warning',
            'name' => 'Aviso de cuenta por eliminar',
            'description' => 'Avisa a negocios con prueba vencida hace mas de 60 dias y sin plan activo.',
            'schedule' => 'Diario a las 10:00 AM',
            'command' => 'businesses:warn-inactive-trial',
        ],
        [
            'key' => 'exchange_rate_fetch',
            'name' => 'Tasa de cambio (TRM)',
            'description' => 'Descarga la TRM oficial del dia (con respaldo de mercado) para valorar gastos en dolares.',
            'schedule' => 'Diario a las 6:00 AM',
            'command' => 'exchange-rate:fetch',
        ],
    ];

    /** @return list<array{key: string, name: string, description: string, schedule: string, command: string}> */
    public static function all(): array
    {
        return self::JOBS;
    }

    /** @return array{key: string, name: string, description: string, schedule: string, command: string}|null */
    public static function find(string $key): ?array
    {
        return collect(self::JOBS)->firstWhere('key', $key);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::JOBS, 'key');
    }

    public static function isEnabled(string $key): bool
    {
        return SystemConfigStore::get("cron.{$key}.enabled", '1') === '1';
    }

    public static function setEnabled(string $key, bool $enabled): void
    {
        SystemConfigStore::putMany(["cron.{$key}.enabled" => $enabled ? '1' : '0']);
    }
}
