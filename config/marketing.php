<?php

/**
 * Precios publicos por defecto (landing/checkout). Ajustable sin deploy
 * via system_configs (superadmin) - ver App\Support\SystemConfigStore y
 * App\Services\SubscriptionPricingService. Estos valores solo aplican
 * cuando no hay una fila en system_configs para la clave equivalente.
 */
return [
    'pricing' => [
        'monthly_cop' => 65000,
        'promo_discount_percent' => 40,
        // 1 mes, como el legacy en produccion (system_configs alla decia 1;
        // este default decia 2 y el POS nuevo, sin esa fila, cobraba 2).
        'promo_months' => 1,
    ],
];
