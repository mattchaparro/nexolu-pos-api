<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fuente unica de verdad de los permisos del sistema.
 *
 * Agregar un permiso es tocar UN array, en UN archivo:
 *  - Se crea en la base con `php artisan permissions:sync`
 *  - Se asigna al rol admin en el mismo comando
 *  - La pantalla de Permisos lo muestra solo (via categoriasParaUI)
 *
 * ai_chat.use vive aqui a proposito. El modulo de IA es un servicio aparte
 * (no forma parte de esta API, ver decision del propietario) pero el permiso
 * es de este dominio: es el admin de un negocio quien decide si su empleado
 * lo puede usar. El servicio externo, cuando llegue, lo lee de aqui.
 */
class PermissionCatalog
{
    /**
     * Categorias para agrupar los permisos en la pantalla de Permisos, en
     * el orden en que deben aparecer.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    private const CATEGORIAS = [
        'ventas' => ['label' => 'Ventas y caja', 'icon' => 'point_of_sale'],
        'inventario' => ['label' => 'Inventario', 'icon' => 'inventory_2'],
        'clientes' => ['label' => 'Clientes y agenda', 'icon' => 'people'],
        'finanzas' => ['label' => 'Finanzas', 'icon' => 'account_balance_wallet'],
        'reportes' => ['label' => 'Reportes', 'icon' => 'bar_chart'],
        'planificador' => ['label' => 'Planificador', 'icon' => 'event_note'],
        'ia' => ['label' => 'Asistente de IA', 'icon' => 'smart_toy'],
        'administracion' => ['label' => 'Administración', 'icon' => 'admin_panel_settings'],
    ];

    /**
     * Todos los permisos del sistema, con su metadata para la pantalla de
     * Permisos.
     *
     * @var list<array{name: string, category: string, label: string, description: string, recommended?: bool, warning?: bool}>
     */
    private const CATALOGO = [
        [
            'name' => 'cash_shift.manage',
            'category' => 'ventas',
            'label' => 'Abrir y cerrar turno de caja',
            'description' => 'Permite al empleado abrir y cerrar su propio turno. Necesario para procesar ventas en el POS.',
            'recommended' => true,
        ],
        [
            'name' => 'discounts.apply',
            'category' => 'ventas',
            'label' => 'Aplicar descuentos',
            'description' => 'Permite aplicar descuentos directamente en ventas individuales.',
        ],
        [
            'name' => 'discounts.manage',
            'category' => 'ventas',
            'label' => 'Crear y editar descuentos',
            'description' => 'Permite gestionar los descuentos disponibles en el sistema.',
        ],
        [
            'name' => 'inventory.view',
            'category' => 'inventario',
            'label' => 'Ver existencias',
            'description' => 'Permite consultar el stock actual y los movimientos de productos.',
        ],
        [
            'name' => 'inventory.add',
            'category' => 'inventario',
            'label' => 'Agregar productos',
            'description' => 'Permite crear nuevos productos en el catálogo de inventario.',
        ],
        [
            'name' => 'inventory.adjust',
            'category' => 'inventario',
            'label' => 'Ajustar stock',
            'description' => 'Permite hacer entradas, salidas y ajustes manuales de inventario.',
        ],
        [
            'name' => 'purchases.manage',
            'category' => 'inventario',
            'label' => 'Compras a proveedores',
            'description' => 'Permite gestionar proveedores, crear y recibir compras de mercancía.',
        ],
        [
            'name' => 'clients.manage',
            'category' => 'clientes',
            'label' => 'Gestionar clientes',
            'description' => 'Permite crear, editar y consultar el directorio de clientes del negocio.',
        ],
        [
            'name' => 'appointments.manage',
            'category' => 'clientes',
            'label' => 'Gestionar citas',
            'description' => 'Permite crear, editar y cancelar citas en la agenda del negocio.',
        ],
        [
            'name' => 'layaways.manage',
            'category' => 'clientes',
            'label' => 'Gestionar apartados',
            'description' => 'Permite crear, editar y abonar los apartados (compras a plazos) de clientes.',
        ],
        [
            'name' => 'receivables.manage',
            'category' => 'clientes',
            'label' => 'Gestionar fiados',
            'description' => 'Permite ver y cobrar los fiados (cuentas por cobrar) pendientes de clientes.',
        ],
        [
            'name' => 'expenses.create',
            'category' => 'finanzas',
            'label' => 'Registrar gastos',
            'description' => 'Permite crear nuevos registros de gastos del negocio.',
        ],
        [
            'name' => 'expenses.manage',
            'category' => 'finanzas',
            'label' => 'Gestionar todos los gastos',
            'description' => 'Permite editar y eliminar cualquier gasto, no solo los propios.',
        ],
        [
            'name' => 'reports.sales',
            'category' => 'reportes',
            'label' => 'Reportes de ventas',
            'description' => 'Permite acceder a los reportes de ventas totales y por vendedor.',
        ],
        [
            'name' => 'reports.inventory',
            'category' => 'reportes',
            'label' => 'Reportes de inventario',
            'description' => 'Permite ver reportes de valor, márgenes y movimientos de stock.',
        ],
        [
            'name' => 'reminders.manage',
            'category' => 'planificador',
            'label' => 'Usar el Planificador',
            'description' => 'Permite crear, completar, posponer y eliminar recordatorios en el Planificador.',
        ],
        [
            'name' => 'ai_chat.use',
            'category' => 'ia',
            'label' => 'Usar el Asistente de IA',
            'description' => 'Permite usar el chat del Asistente para consultar datos del negocio y preparar registros (gastos, compras, etc.) para confirmar. Nunca escribe nada sin que un humano lo confirme.',
        ],
        [
            'name' => 'cash_closing.manage',
            'category' => 'administracion',
            'label' => 'Cierres de caja',
            'description' => 'Permite crear, ver e imprimir el historial completo de cierres de caja.',
        ],
        [
            'name' => 'cash_closing.undo',
            'category' => 'administracion',
            'label' => 'Deshacer cierres de caja',
            'description' => 'Permite revertir un cierre de caja del mismo día (reabre los turnos que cerró). Otorgar con cuidado.',
            'warning' => true,
        ],
        [
            'name' => 'permissions.manage',
            'category' => 'administracion',
            'label' => 'Gestionar permisos de empleados',
            'description' => 'Permite configurar los permisos de otros empleados. Otorgar con cuidado.',
            'warning' => true,
        ],
    ];

    /** @return list<string> */
    public static function permissions(): array
    {
        return array_column(self::CATALOGO, 'name');
    }

    /**
     * Catalogo agrupado por categoria, listo para la pantalla de Permisos.
     * Solo incluye permisos que de verdad existen en la base (por si el
     * catalogo se actualizo y `permissions:sync` todavia no corrio): mostrar
     * un checkbox para un permiso que no existe fallaria al guardar.
     *
     * @param  list<string>  $permisosExistentes  nombres ya creados en la base
     * @return list<array{key: string, label: string, icon: string, permissions: list<array<string, mixed>>}>
     */
    public static function categoriasParaUI(array $permisosExistentes): array
    {
        $existentes = array_flip($permisosExistentes);

        $porCategoria = [];
        foreach (self::CATALOGO as $permiso) {
            if (! isset($existentes[$permiso['name']])) {
                continue;
            }
            $porCategoria[$permiso['category']][] = [
                'name' => $permiso['name'],
                'label' => $permiso['label'],
                'description' => $permiso['description'],
                'recommended' => $permiso['recommended'] ?? false,
                'warning' => $permiso['warning'] ?? false,
            ];
        }

        $resultado = [];
        foreach (self::CATEGORIAS as $key => $info) {
            if (empty($porCategoria[$key])) {
                continue;
            }
            $resultado[] = [
                'key' => $key,
                'label' => $info['label'],
                'icon' => $info['icon'],
                'permissions' => $porCategoria[$key],
            ];
        }

        return $resultado;
    }

    /**
     * Deja la base al dia con el catalogo: crea los permisos que falten y se los
     * asigna al rol admin. Idempotente y sin efecto destructivo.
     *
     * NO usa syncPermissions() sobre el rol admin a proposito: sync borraria
     * cualquier permiso que el rol tenga y no figure aqui, que en otra base
     * podria ser legitimo. Solo agrega lo que falta.
     *
     * El rol admin recibe TODO. Los empleados reciben permisos uno a uno desde
     * la pantalla de usuario y no deben heredar nada de este metodo.
     *
     * @return array{creados: list<string>, otorgados: list<string>}
     */
    public static function sync(): array
    {
        $creados = [];

        foreach (self::permissions() as $nombre) {
            if (! Permission::query()->where('name', $nombre)->where('guard_name', 'web')->exists()) {
                Permission::create(['name' => $nombre, 'guard_name' => 'web']);
                $creados[] = $nombre;
            }
        }

        $otorgados = [];
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();

        if ($admin) {
            foreach (self::permissions() as $nombre) {
                if (! $admin->hasPermissionTo($nombre, 'web')) {
                    $admin->givePermissionTo($nombre);
                    $otorgados[] = $nombre;
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return ['creados' => $creados, 'otorgados' => $otorgados];
    }
}
