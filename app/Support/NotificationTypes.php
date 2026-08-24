<?php

namespace App\Support;

/**
 * Catalogo de notificaciones que un negocio puede activar por WhatsApp.
 * Fuente unica de que tipos existen: la pantalla de Ajustes, la validacion
 * del guardado (BusinessController::updateNotifications) y los comandos
 * programados que ya las envian (InventorySendLowStockAlerts,
 * SendDailyWhatsAppSummary) la consumen.
 */
class NotificationTypes
{
    /** @var array<string, array{label:string, desc:string}> */
    public const TYPES = [
        'resumen_diario' => [
            'label' => 'Resumen del dia',
            'desc' => 'Cada noche: cuanto vendiste, como quedo la caja y lo importante del dia.',
        ],
        'inventario_bajo' => [
            'label' => 'Inventario bajo',
            'desc' => 'Cuando un producto esta por agotarse, para que puedas reponer a tiempo.',
        ],
        'recordatorios' => [
            'label' => 'Recordatorios y citas',
            'desc' => 'Tus recordatorios del planificador y las citas del dia.',
        ],
        'fiados_vencidos' => [
            'label' => 'Fiados vencidos',
            'desc' => 'Cuando un fiado pasa su fecha de pago.',
        ],
    ];

    /**
     * Que tipos ademas dejan elegir A QUE HORA llegan (ver
     * Business::notificationHour(), SendDailyWhatsAppSummary,
     * InventorySendLowStockAlerts). recordatorios no aplica: cada
     * recordatorio ya trae su propia notify_time, no hay "una hora" de
     * negocio que fijar. fiados_vencidos tampoco: todavia no existe un
     * comando que lo envie.
     *
     * @var list<string>
     */
    public const SCHEDULABLE = ['resumen_diario', 'inventario_bajo'];

    /**
     * Hora por defecto (America/Bogota, unica zona horaria del producto)
     * para quien nunca la personalizo - mismos horarios que ya usaban los
     * `dailyAt()` originales de ambos comandos antes de este feature.
     *
     * @var array<string, string>
     */
    public const DEFAULT_HOURS = [
        'resumen_diario' => '20:00',
        'inventario_bajo' => '08:00',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Catalogo con la clave incluida, para el frontend.
     *
     * @return list<array{key:string, label:string, desc:string, schedulable:bool, default_hour:?string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TYPES as $key => $meta) {
            $out[] = [
                'key' => $key,
                ...$meta,
                'schedulable' => in_array($key, self::SCHEDULABLE, true),
                'default_hour' => self::DEFAULT_HOURS[$key] ?? null,
            ];
        }

        return $out;
    }
}
