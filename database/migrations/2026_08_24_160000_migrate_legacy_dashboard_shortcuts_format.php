<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * dashboard_shortcuts ya traia datos reales de negocios que usaron el
     * customizador del legacy (pos-saas-legacy/Components/Dashboard/
     * ShortcutCustomizer.vue) antes de que este frontend tuviera su propia
     * version de la pantalla - la columna es la misma, solo migro de base
     * de datos, nunca se limpio. Esos registros vienen en el formato viejo
     * ({route, color}, route de Laravel tipo "admin.sales.create", color
     * cualquiera de 11 valores) y DashboardController::updateShortcuts /
     * resolveShortcuts() en el frontend esperan el formato nuevo
     * ({route_name, color}, route_name de Vue Router, color solo
     * primary/outline) - sin este fix, cualquier admin que ya hubiera
     * personalizado sus atajos en el legacy los veia desaparecer por
     * completo (ningun route_name matcheaba, resolveShortcuts() los
     * descartaba a todos) en vez de heredarlos.
     *
     * route: mapeo 1:1 contra pos-saas-legacy/resources/js/menu/admin.json
     * (unica fuente de verdad de que ruta legacy es que modulo) resuelto
     * contra adminNavItems en nexolu-pos-front/src/router/navigation.ts. Lo
     * que no tiene modulo equivalente todavia en este frontend (ai-chat.index,
     * "Asistente IA") se descarta, mismo criterio que ya usa
     * resolveShortcuts() con cualquier ruta que ya no exista.
     *
     * color: acotado a la paleta de marca (ver ShortcutCustomizer.vue) -
     * "green" era el unico color que el legacy usaba para el primer atajo
     * destacado (Vender) en su set por defecto, se mapea a "primary"; el
     * resto (white/blue/indigo/amber/red/emerald/purple/orange/teal/pink)
     * pasa a "outline" (neutro).
     */
    private const ROUTE_MAP = [
        'admin.sales.create' => 'sales.create',
        'admin.sales.tabs' => 'open-tabs.index',
        'admin.service-orders.index' => 'service-orders.index',
        'admin.appointments.index' => 'appointments.index',
        'admin.sales.receivables.index' => 'receivables.index',
        'admin.layaways.index' => 'layaways.index',
        'admin.kitchen.index' => 'kitchen.index',
        'admin.products.index' => 'catalog.index',
        'admin.cash-shifts.index' => 'cash-shifts.index',
        'admin.reports.daily' => 'daily-summary.index',
        'admin.expenses.index' => 'expenses.index',
        'admin.reminders.index' => 'reminders.index',
        'admin.discounts.index' => 'discounts.index',
        'admin.reports.index' => 'reports.index',
        'admin.employees.index' => 'employees.index',
        'admin.audit.index' => 'audit-logs.index',
        'admin.settings.index' => 'business-settings.index',
        'admin.my-business.index' => 'business-overview.index',
    ];

    public function up(): void
    {
        User::query()
            ->whereNotNull('dashboard_shortcuts')
            ->each(function (User $user): void {
                $shortcuts = $user->dashboard_shortcuts;

                // Ya esta en formato nuevo (route_name), o vacio - nada que hacer.
                if (empty($shortcuts) || array_key_exists('route_name', $shortcuts[0])) {
                    return;
                }

                $migrated = [];
                foreach ($shortcuts as $shortcut) {
                    $routeName = self::ROUTE_MAP[$shortcut['route'] ?? null] ?? null;
                    if ($routeName === null) {
                        continue;
                    }
                    $migrated[] = [
                        'route_name' => $routeName,
                        'color' => ($shortcut['color'] ?? null) === 'green' ? 'primary' : 'outline',
                    ];
                }

                $user->update(['dashboard_shortcuts' => $migrated]);
            });
    }

    public function down(): void
    {
        // Intencionalmente no reversible: no hay forma de recuperar el
        // route/color original del legacy una vez traducido (perdida de
        // informacion aceptada, mismo criterio que la migracion hermana de
        // reports.business_overview).
    }
};
