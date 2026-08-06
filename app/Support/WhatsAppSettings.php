<?php

namespace App\Support;

use App\Models\WhatsAppUsageDaily;

/**
 * Tarifas y categoria de costo de WhatsApp Cloud API. A diferencia de
 * legacy (que las hacia editables en caliente via un panel de SuperAdmin),
 * aqui viven en config/whatsapp.php - no existe todavia un equivalente de
 * ese panel en esta API. Si se necesita ajustarlas sin deploy, ese es un
 * modulo aparte, no parte de esta migracion.
 */
class WhatsAppSettings
{
    public static function rateMicros(string $category): int
    {
        return max(0, (int) config("whatsapp.rates_micros.{$category}", 0));
    }

    /**
     * @return array<string, int>
     */
    public static function allRatesMicros(): array
    {
        $rates = [];
        foreach (WhatsAppUsageDaily::CATEGORIES as $category) {
            $rates[$category] = self::rateMicros($category);
        }

        return $rates;
    }

    public static function categoryForTemplate(?string $templateName): string
    {
        if ($templateName === null || $templateName === '') {
            return (string) config('whatsapp.default_template_category', 'utility');
        }

        $map = (array) config('whatsapp.template_categories', []);

        return (string) ($map[$templateName] ?? config('whatsapp.default_template_category', 'utility'));
    }
}
