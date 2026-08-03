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
            'expenses' => true,
            'managerial_accounting' => false,
            'cash_closing' => true,
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
            'expenses' => true,
            'managerial_accounting' => true,
            'cash_closing' => true,
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
}
