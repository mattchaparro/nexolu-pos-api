<?php

// Defaults del addon de cupo de mensajes de IA (ver App\Support\AiQuotaSettings).
// Nombres de env vars alineados con el legacy (pos-saas-legacy config/ai.php)
// a proposito, aunque este repo no porta el resto de ese archivo (trial/addon
// viejo con expiracion, ver docs/MIGRATION_BACKLOG.md - ese modelo esta muerto
// en el legacy, AiAccessService::estado() nunca lo consulta).
return [
    'addon' => [
        'monthly_included_messages' => (int) env('AI_MONTHLY_INCLUDED_MESSAGES', 300),
        'pack_size' => (int) env('AI_PACK_SIZE', 1000),
        'pack_price_cop' => (int) env('AI_PACK_PRICE_COP', 15000),
        'employee_daily_share' => (float) env('AI_EMPLOYEE_DAILY_SHARE', 0.6),
    ],
];
