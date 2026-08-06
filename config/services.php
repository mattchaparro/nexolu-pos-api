<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ia_core' => [
        // Misma credencial simetrica en los dos sentidos: el IA Core la usa
        // para llamar POST /api/ai/tools/invoke, y este POS la usa aca para
        // llamar POST {base_url}/v1/chat - ver AiChatService.
        'api_key' => env('IA_CORE_API_KEY'),
        'base_url' => env('IA_CORE_BASE_URL', 'http://localhost:8000'),
    ],

    // WhatsApp Cloud API (asistente IA omnichannel). verify_token valida el
    // webhook (GET), access_token/phone_number_id son para ENVIAR (Graph
    // API) - credenciales distintas, no se derivan una de la otra.
    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v25.0'),

        // Plantillas aprobadas en Meta: no son secretos, cambian poco y son
        // las mismas en todo ambiente, por eso van hardcodeadas y no en env.
        'templates' => [
            'otp' => [
                'name' => 'verify_whatsapp_in_business',
                'lang' => 'es',
                'has_button' => true,
            ],
            'welcome' => [
                'name' => 'welcome_whatsapp_linked',
                'lang' => 'es_CO',
                'var' => null,
                'var_max' => 60,
            ],
        ],
    ],

];
