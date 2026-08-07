<?php

namespace App\Support;

/**
 * product_categories.icon en el schema compartido guarda nombres de Google
 * Material Icons (ej. 'local_bar', 'inventory_2' - ver el picker de categorias
 * del legacy en resources/js/utils/categoryIcons.js), porque el legacy los
 * renderiza con la fuente Material Icons. Este frontend usa PrimeIcons como
 * unico sistema de iconos, asi que ese valor crudo no coincide con ninguna
 * clase real y no renderiza nada - no es un problema de CSS, es un desajuste
 * de formato de dato heredado del legacy.
 *
 * A proposito no se migra el dato en BD (product_categories sigue siendo una
 * tabla que el monolito legacy lee/escribe - ver CUTOVER_TODO.md): la
 * traduccion vive aca, en la capa de lectura, asi que el legacy sigue
 * funcionando con sus propios valores intactos.
 */
final class CategoryIconResolver
{
    public const DEFAULT_ICON = 'pi pi-tag';

    /**
     * Nombre de Material Icon (legacy) => nombre de PrimeIcon (sin el
     * prefijo "pi pi-"). Cobertura completa de CATEGORY_ICONS del legacy
     * (resources/js/utils/categoryIcons.js) - PrimeIcons es un set mas chico
     * y generico, asi que varias entradas caen en el mismo icono cuando no
     * hay equivalente exacto (ej. todo el grupo "Comida", que PrimeIcons no
     * cubre en absoluto).
     *
     * @var array<string, string>
     */
    private const MATERIAL_TO_PRIME = [
        // Tecnologia
        'smartphone' => 'mobile',
        'phone_android' => 'android',
        'phone_iphone' => 'apple',
        'tablet_android' => 'tablet',
        'laptop' => 'desktop',
        'desktop_windows' => 'desktop',
        'headphones' => 'headphones',
        'earbuds' => 'headphones',
        'speaker' => 'volume-up',
        'tv' => 'desktop',
        'watch' => 'clock',
        'electrical_services' => 'bolt',
        'battery_charging_full' => 'bolt',
        'usb' => 'microchip',
        'sd_card' => 'microchip',
        'memory' => 'microchip',
        'storage' => 'database',
        'shield' => 'shield',
        'photo_camera' => 'camera',
        'router' => 'wifi',
        'bluetooth' => 'wifi',
        'sports_esports' => 'desktop',
        'devices_other' => 'mobile',
        'keyboard' => 'desktop',
        'mouse' => 'desktop',
        'print' => 'print',
        // Comida (PrimeIcons no tiene iconos de comida propios)
        'coffee' => 'shop',
        'restaurant' => 'shop',
        'local_bar' => 'shop',
        'bakery_dining' => 'shop',
        'icecream' => 'shop',
        'lunch_dining' => 'shop',
        'local_pizza' => 'shop',
        'local_drink' => 'shop',
        // General / Retail
        'storefront' => 'shop',
        'local_grocery_store' => 'shopping-cart',
        'checkroom' => 'tag',
        'shopping_basket' => 'shopping-cart',
        'spa' => 'sparkles',
        'medication' => 'box',
        'cleaning_services' => 'refresh',
        'pets' => 'heart',
        'home_repair_service' => 'wrench',
        'auto_awesome' => 'sparkles',
        'edit_note' => 'pencil',
        'inventory_2' => 'box',
        // Ropa & Moda
        'dry_cleaning' => 'refresh',
        'iron' => 'bolt',
        'style' => 'tag',
        'shopping_bag' => 'shopping-bag',
        'man' => 'user',
        'woman' => 'user',
        'backpack' => 'briefcase',
        'luggage' => 'briefcase',
        'local_mall' => 'shopping-bag',
        // Tatuajes / arte corporal
        'brush' => 'palette',
        'palette' => 'palette',
        'gesture' => 'pencil',
        'format_paint' => 'palette',
        'create' => 'pencil',
        'design_services' => 'palette',
        'edit' => 'pencil',
        'color_lens' => 'palette',
        'accessibility_new' => 'user',
        // Smoke shop
        'smoking_rooms' => 'box',
        'vaping_rooms' => 'cloud',
        'whatshot' => 'bolt',
        'local_fire_department' => 'bolt',
        'grass' => 'box',
        'air' => 'cloud',
        'eco' => 'heart',
        'filter_vintage' => 'palette',
        'science' => 'microchip',
        // Referenciado por TECH_ICON_RULES del legacy pero fuera de
        // CATEGORY_ICONS (dron/drone) - se cubre igual por completitud.
        'flight' => 'send',
    ];

    /**
     * @param  ?string  $rawIcon  Valor crudo de product_categories.icon.
     * @return string Clase PrimeIcons completa (ej. "pi pi-shop"), nunca vacia.
     */
    public static function resolve(?string $rawIcon): string
    {
        $value = trim((string) $rawIcon);
        if ($value === '') {
            return self::DEFAULT_ICON;
        }

        // Ya viene en formato PrimeIcons (ej. creado/editado desde esta API) -
        // se respeta tal cual, no se reinterpreta como Material Icon.
        if (str_starts_with($value, 'pi ') || str_starts_with($value, 'pi-')) {
            return str_starts_with($value, 'pi-') ? "pi {$value}" : $value;
        }

        $mapped = self::MATERIAL_TO_PRIME[$value] ?? null;

        return $mapped ? "pi pi-{$mapped}" : self::DEFAULT_ICON;
    }
}
