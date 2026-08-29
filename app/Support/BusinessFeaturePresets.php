<?php

namespace App\Support;

/**
 * Feature flags aplicados al crear un negocio según el perfil de registro.
 * Claves alineadas con middleware `feature:*` y menú por módulo.
 */
class BusinessFeaturePresets
{
    public const SETUP_RETAIL = 'retail';

    public const SETUP_FOOD_SERVICE = 'food_service';

    public const SETUP_MINIMAL = 'minimal';

    public const SETUP_CUSTOM = 'custom';

    public const SETUP_SPA = 'spa';

    public const SETUP_PROFESSIONAL = 'professional_services';

    /**
     * Banderas que NUNCA se heredan del comodin de retrocompatibilidad.
     *
     * Business::hasFeature() devuelve `true` para cualquier bandera cuando el
     * negocio no tiene JSON de flags (retrocompatibilidad con los negocios
     * mas viejos de la plataforma). Eso es inofensivo para un modulo interno,
     * pero no para uno que publica datos hacia afuera: un negocio antiguo se
     * encontraria con su catalogo expuesto en internet sin haberlo pedido.
     *
     * Ojo con lo que esta lista significa hoy: `online_store` SI viene con el
     * plan Full (ver full()), asi que un negocio Full con JSON de flags la
     * tiene encendida. Lo que esta lista impide es que la herede un negocio
     * SIN JSON, donde "todo encendido" es una suposicion, no una decision.
     *
     * @var list<string>
     */
    public const OPT_IN_ONLY = ['online_store'];

    /** @return list<string> */
    public static function setupModes(): array
    {
        return [
            self::SETUP_RETAIL,
            self::SETUP_FOOD_SERVICE,
            self::SETUP_MINIMAL,
            self::SETUP_CUSTOM,
            self::SETUP_SPA,
            self::SETUP_PROFESSIONAL,
        ];
    }

    /** Tienda al detalle: alineado con plan Básico. */
    public static function retail(): array
    {
        return self::basic();
    }

    /** Restaurante / café: alineado con plan Full. */
    public static function foodService(): array
    {
        return self::full();
    }

    /** Solo ventas y control básico: plan Básico sin gastos. */
    public static function minimal(): array
    {
        return array_merge(self::basic(), ['expenses' => false]);
    }

    /** Spa, salón de belleza, barbería: Full sin comandera/mesas/ingredientes. */
    public static function spa(): array
    {
        return array_merge(self::full(), [
            'open_tabs' => false,
            'inventory_advanced' => false,
            'ingredients' => false,
            'kitchen_board' => false,
            'variants' => false,
        ]);
    }

    /** Servicios profesionales: optómetras, psicólogos, médicos. Full sin módulos de cocina. */
    public static function professionalServices(): array
    {
        return array_merge(self::full(), [
            'open_tabs' => false,
            'inventory_advanced' => false,
            'ingredients' => false,
            'kitchen_board' => false,
            'variants' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function fromCustom(array $input): array
    {
        $out = [];
        foreach (array_keys(self::basic()) as $key) {
            $out[$key] = isset($input[$key]) && filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, bool>
     */
    public static function fromSetupMode(string $mode, array $customFlags = []): array
    {
        return match ($mode) {
            self::SETUP_RETAIL => self::retail(),
            self::SETUP_FOOD_SERVICE => self::foodService(),
            self::SETUP_MINIMAL => self::minimal(),
            self::SETUP_CUSTOM => self::fromCustom($customFlags),
            self::SETUP_SPA => self::spa(),
            self::SETUP_PROFESSIONAL => self::professionalServices(),
            default => self::retail(),
        };
    }

    /** Devuelve el plan ('basic' o 'full') que corresponde a un setup_mode. */
    public static function planForSetupMode(string $mode): string
    {
        return match ($mode) {
            self::SETUP_FOOD_SERVICE, self::SETUP_SPA, self::SETUP_PROFESSIONAL => 'full',
            default => 'basic',
        };
    }

    /** Plan básico: POS + cierre de caja + fiados + gastos + inventario básico + reportes de ventas. */
    public static function basic(): array
    {
        return [
            'open_tabs' => false,
            'inventory' => true,
            'inventory_advanced' => false,
            'ingredients' => false,
            'variants' => false,
            'expenses' => true,
            'managerial_accounting' => false,
            'cash_closing' => true,
            'shift_daily_close_required' => false,
            'receivables' => true,
            'kitchen_board' => false,
            'services' => false,
            'cash_receipts_pdf' => false,
            'permissions_management' => false,
            'low_stock_alert' => false,
            'audit_logs' => false,
            'clients' => false,
            'scheduling' => false,
            'layaway' => false,
            'discounts' => false,
            'charges' => false,
            'reminders' => true,
            'online_store' => false,
        ];
    }

    /** Plan full: todas las funcionalidades habilitadas. */
    public static function full(): array
    {
        return [
            'open_tabs' => true,
            'inventory' => true,
            'inventory_advanced' => true,
            'ingredients' => true,
            'variants' => true,
            'expenses' => true,
            'managerial_accounting' => true,
            'cash_closing' => true,
            'shift_daily_close_required' => false,
            'receivables' => true,
            'kitchen_board' => true,
            'services' => true,
            'cash_receipts_pdf' => true,
            'permissions_management' => true,
            'low_stock_alert' => true,
            'audit_logs' => true,
            'clients' => true,
            'scheduling' => true,
            'layaway' => false,
            'discounts' => false,
            'charges' => false,
            'reminders' => true,
            // Incluida en Full: vender por internet es parte de lo que se
            // paga en este plan, no un tramite aparte con soporte. Encenderla
            // NO publica nada: la tienda solo responde cuando el comerciante
            // ademas activa `business_store_settings.is_active` y publica sus
            // productos (ver ResolveStorefrontTenant).
            'online_store' => true,
        ];
    }

    public static function fromPlan(string $plan): array
    {
        return match ($plan) {
            'basic' => self::basic(),
            'full' => self::full(),
            default => self::basic(),
        };
    }

    /**
     * Precio mensual del plan en COP. Misma fuente que
     * SubscriptionPricingService::resolvePlanPrice() (SystemConfigStore,
     * editable por superadmin sin redeploy) - antes este metodo hardcodeaba
     * su propio default sin consultarla, lo que dejaba a
     * Business::monthlyPriceCop() y SuperAdminBusinessService::activate()
     * mostrando un precio desactualizado en cuanto alguien editara
     * `plans.full_price_cop`/`plans.basic_price_cop`.
     */
    public static function planPriceCop(string $plan): int
    {
        return match ($plan) {
            'full' => (int) SystemConfigStore::get('plans.full_price_cop', '85000'),
            default => (int) SystemConfigStore::get('plans.basic_price_cop', '65000'),
        };
    }

    /**
     * Catalogo completo de banderas para la pantalla "Planes y Funciones" de
     * SuperAdmin: clave, etiqueta, descripcion, grupo (mismos 6 grupos que
     * BusinessFormFields.vue del legacy) y si cada plan la trae encendida
     * por defecto. Solo lectura, no se persiste - basic()/full() siguen
     * siendo la unica fuente de verdad de los valores.
     *
     * @return list<array{key: string, label: string, description: string, group: string, basic: bool, full: bool}>
     */
    public static function catalog(): array
    {
        $basic = self::basic();
        $full = self::full();

        $entries = [
            ['key' => 'open_tabs', 'label' => 'Cuentas abiertas (mesas)', 'description' => 'Permite abrir una cuenta y agregar items antes de cobrar, pensado para mesas o clientes que consumen antes de pagar.', 'group' => 'POS y ventas'],
            ['key' => 'receivables', 'label' => 'Fiados / crédito', 'description' => 'Permite vender a crédito y llevar el saldo pendiente de cada cliente.', 'group' => 'POS y ventas'],
            ['key' => 'kitchen_board', 'label' => 'Comandera (cocina)', 'description' => 'Tablero de comandas para que cocina vea y actualice el estado de cada pedido.', 'group' => 'POS y ventas'],
            ['key' => 'cash_receipts_pdf', 'label' => 'Recibos de caja en PDF', 'description' => 'Genera y envía el comprobante de venta, apartado u orden de servicio como PDF descargable o por WhatsApp/correo.', 'group' => 'POS y ventas'],

            ['key' => 'inventory', 'label' => 'Inventario básico', 'description' => 'Lleva el stock de cada producto y lo descuenta automáticamente con cada venta.', 'group' => 'Inventario'],
            ['key' => 'inventory_advanced', 'label' => 'Inventario avanzado (compras/proveedores)', 'description' => 'Habilita compras a proveedores, historial de costos y ajustes manuales de stock.', 'group' => 'Inventario'],
            ['key' => 'ingredients', 'label' => 'Ingredientes y recetas', 'description' => 'Permite definir recetas y descontar ingredientes del inventario al vender un producto preparado.', 'group' => 'Inventario'],
            ['key' => 'variants', 'label' => 'Variaciones de producto (talla, color, presentación)', 'description' => 'Permite definir atributos combinables (talla, color, presentación) y vender cada combinación con su propio precio y stock.', 'group' => 'Inventario'],
            ['key' => 'low_stock_alert', 'label' => 'Alertas de stock bajo', 'description' => 'Envía avisos por correo o WhatsApp cuando un producto o ingrediente baja del umbral configurado.', 'group' => 'Inventario'],

            ['key' => 'expenses', 'label' => 'Gastos', 'description' => 'Registro de gastos del negocio, con tipos de gasto y plantillas recurrentes.', 'group' => 'Finanzas'],
            ['key' => 'managerial_accounting', 'label' => 'Contabilidad gerencial', 'description' => 'Reportes mensuales y anuales de ingresos, gastos y cierre contable.', 'group' => 'Finanzas'],
            ['key' => 'cash_closing', 'label' => 'Cierre de caja', 'description' => 'Turnos de caja con apertura, cierre y arqueo de efectivo.', 'group' => 'Finanzas'],
            ['key' => 'shift_daily_close_required', 'label' => 'Bloquear ventas con turno arrastrado', 'description' => 'Si un empleado deja un turno abierto de un día anterior, le impide seguir vendiendo hasta que lo cierre y abra uno nuevo (sin esto, solo ve un aviso). Apagado por defecto: bloquear ventas es disruptivo, se activa negocio por negocio si el dueño lo pide.', 'group' => 'Finanzas'],

            ['key' => 'services', 'label' => 'Catálogo de servicios', 'description' => 'Permite vender servicios (no solo productos) y crear órdenes de servicio.', 'group' => 'Catálogo'],
            ['key' => 'layaway', 'label' => 'Apartados', 'description' => 'Reserva de productos con abonos parciales antes de completar la venta.', 'group' => 'Catálogo'],
            ['key' => 'discounts', 'label' => 'Descuentos', 'description' => 'Descuentos configurables aplicables al vender.', 'group' => 'Catálogo'],
            ['key' => 'charges', 'label' => 'Cargos (servicio / impoconsumo)', 'description' => 'Cargos adicionales configurables que se suman al cobrar, por ejemplo propina obligatoria o impoconsumo.', 'group' => 'Catálogo'],

            ['key' => 'audit_logs', 'label' => 'Auditoría', 'description' => 'Historial de acciones sensibles realizadas por los usuarios del negocio.', 'group' => 'Equipo y seguridad'],
            ['key' => 'permissions_management', 'label' => 'Gestión de permisos de empleados', 'description' => 'Permite personalizar, empleado por empleado, qué acciones puede realizar cada uno.', 'group' => 'Equipo y seguridad'],

            ['key' => 'clients', 'label' => 'Directorio de clientes', 'description' => 'Ficha de cada cliente con su historial de compras.', 'group' => 'Clientes y agenda'],
            ['key' => 'scheduling', 'label' => 'Agenda', 'description' => 'Calendario de citas, independiente del catálogo de servicios.', 'group' => 'Clientes y agenda'],
            ['key' => 'reminders', 'label' => 'Planificador de recordatorios', 'description' => 'Recordatorios internos con fecha de vencimiento.', 'group' => 'Clientes y agenda'],

            ['key' => 'online_store', 'label' => 'Tienda online', 'description' => 'Publica un catálogo público en internet con el mismo inventario del POS, para que los clientes compren y paguen en línea. Incluida en el plan Full; habilitarla no publica nada hasta que el comerciante active su tienda y publique productos.', 'group' => 'Canales de venta'],
        ];

        return array_map(fn (array $entry) => [
            ...$entry,
            'basic' => $basic[$entry['key']],
            'full' => $full[$entry['key']],
        ], $entries);
    }
}
