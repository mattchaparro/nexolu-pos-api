<?php

return [

    // Tarifas de WhatsApp Cloud API en micro-dolares (1 USD = 1_000_000
    // micros), por categoria de mensaje segun la clasificacion de Meta.
    // 'service' (respuestas dentro de la ventana de 24h) es gratis. Overridable
    // por env porque Meta las cambia por region sin previo aviso.
    'rates_micros' => [
        'marketing' => env('WHATSAPP_RATE_MARKETING_MICROS', 0),
        'utility' => env('WHATSAPP_RATE_UTILITY_MICROS', 0),
        'authentication' => env('WHATSAPP_RATE_AUTHENTICATION_MICROS', 0),
        'service' => 0,
    ],

    // Mapea el nombre de una plantilla (config('services.whatsapp.templates'))
    // a la categoria que Meta le asigno al aprobarla, para clasificar el
    // costo correctamente. Lo que no este aca cae en default_template_category.
    'template_categories' => [
        'verify_whatsapp_in_business' => 'authentication',
        'welcome_whatsapp_linked' => 'utility',
    ],

    'default_template_category' => 'utility',

];
