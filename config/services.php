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

        // API key de PLATAFORMA del IA Core (NEXOLU_PLATFORM_API_KEY del lado
        // de ese servicio, no una app individual): unico uso, GET
        // /v1/platform/usage para el costo real de IA en el dashboard de
        // Finance de SuperAdmin - ver AiPlatformUsageService.
        'platform_api_key' => env('IA_CORE_PLATFORM_API_KEY'),
    ],

    'legacy' => [
        // Misma credencial simetrica en los dos sentidos: pos-saas (legacy)
        // la usa para llamar POST /admin/businesses/{id}/run-migration-patches
        // (superadmin, boton "Correr parches" en Businesses/Show.vue), este
        // POS la usa aca para verificarla - ver EnsureValidLegacyAdminKey.
        'admin_key' => env('LEGACY_ADMIN_API_KEY'),
    ],

    // Nexolu Payments Core (servicio Python aparte, repo nexolu-payments-core):
    // pasarela de pagos unificada, este POS ya no le habla a Wompi directo.
    // api_key autentica las llamadas salientes de este POS al Core
    // (POST /v1/payments/intents, GET /v1/payments/transactions/{reference}).
    // webhook_secret verifica que un webhook entrante (POST a nuestro
    // endpoint) realmente vino del Core - ver PaymentsCoreWebhookController.
    'payments_core' => [
        'api_key' => env('PAYMENTS_CORE_API_KEY'),
        'base_url' => env('PAYMENTS_CORE_BASE_URL', 'http://host.docker.internal:8003'),
        'webhook_secret' => env('PAYMENTS_CORE_WEBHOOK_SECRET'),
        // Llave ADMINISTRATIVA, distinta de api_key: da de alta merchants e
        // integraciones. Solo la usa BusinessPaymentGatewayService, cuando un
        // comerciante conecta su propia pasarela. Nunca se expone a una ruta
        // publica ni viaja al frontend.
        'provisioning_key' => env('PAYMENTS_CORE_PROVISIONING_KEY'),
    ],

    // Nexolu Communications (servicio Python aparte, repo nexolu-comms-api):
    // envio centralizado de WhatsApp/email para todo el ecosistema Nexolu.
    // api_key autentica las llamadas salientes de este POS
    // (POST /v1/notifications/send, POST /v1/whatsapp/read-receipt,
    // GET /v1/usage/summary - ver NexoluCommsChannel/NexoluCommsCostReporter).
    // webhook_secret verifica que un evento entrante (POST a nuestro
    // endpoint, reenviado por Nexolu Communications) de verdad vino de alli
    // y no fue interpretado/alterado en el camino - ver
    // NexoluCommsWebhookController. `driver` decide si el envio saliente de
    // WhatsApp usa este servicio ('nexolu_comms') o sigue yendo directo a
    // Meta ('whatsapp_direct', valor de hoy) - ver AppServiceProvider.
    'comms_core' => [
        'driver' => env('MESSAGING_DRIVER', 'whatsapp_direct'),
        'api_key' => env('COMMS_CORE_API_KEY'),
        'base_url' => env('COMMS_CORE_BASE_URL', 'http://localhost:8010'),
        'webhook_secret' => env('COMMS_CORE_WEBHOOK_SECRET'),
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
        // 'description'/'triggered_by' son el texto que consume
        // SuperAdmin\WhatsAppTemplateController (pantalla de solo lectura,
        // ver docs/WHATSAPP_TEMPLATES_PENDING.md para el detalle mas
        // profundo de las que faltan crear en Meta: texto sugerido, etc).
        'templates' => [
            'otp' => [
                'name' => 'verify_whatsapp_in_business',
                'lang' => 'es',
                'has_button' => true,
                'description' => 'Código OTP para vincular un número de WhatsApp a una cuenta.',
                'triggered_by' => 'WhatsAppOtpSender',
            ],
            'welcome' => [
                'name' => 'welcome_whatsapp_linked',
                'lang' => 'es_CO',
                'var' => null,
                'var_max' => 60,
                'description' => 'Bienvenida al vincular exitosamente un número de WhatsApp.',
                'triggered_by' => 'ChannelLinkService',
            ],
            'recordatorio' => [
                'name' => 'general_reminder',
                'lang' => 'es_CO',
                'description' => 'Recordatorio genérico (Planificador de recordatorios).',
                'triggered_by' => 'reminders:send-whatsapp-notifications',
            ],
            // Resumen diario del negocio (notifications:send-daily-whatsapp-summary).
            // Mismo nombre real que ya usa legacy en el mismo WABA: creada en
            // Meta como 'daily_business_summary', EN REVISION al 2026-07-23.
            // Si Meta la rechaza aun, el envio falla silenciosamente (log, sin
            // tumbar el comando - WhatsAppCloudClient::post no lanza), igual
            // que en legacy.
            'resumen_diario' => [
                'name' => 'daily_business_summary',
                'lang' => 'es_CO',
                'description' => 'Resumen diario del negocio (ventas, caja). En revisión en Meta desde 2026-07-23.',
                'triggered_by' => 'notifications:send-daily-whatsapp-summary',
            ],
            // Alerta de inventario bajo (inventory:send-low-stock-alerts).
            // Creada en Meta como 'low_stock_alert', es_CO, APPROVED. 3
            // variables en el body: negocio, cantidad total, bloque de items
            // urgentes - ver InventorySendLowStockAlerts::whatsappComponents().
            'inventario_bajo' => [
                'name' => 'low_stock_alert',
                'lang' => 'es_CO',
                'description' => 'Alerta de inventario bajo (productos e ingredientes).',
                'triggered_by' => 'inventory:send-low-stock-alerts',
            ],
            // Confirmacion al agendar + recordatorio 2h antes de una cita -
            // funcionalidad nueva, sin equivalente en legacy (que solo
            // mandaba un recordatorio por correo el dia anterior, ver
            // AppointmentsSendReminders). Sin plantilla real aprobada
            // todavia en Meta, por eso via env() y no hardcodeado como las
            // de arriba (mismo patron que flows.gasto.id): AppointmentWhatsappNotifier
            // no envia nada hasta que se configure el nombre real.
            'cita_confirmacion' => [
                'name' => env('WHATSAPP_TEMPLATE_CITA_CONFIRMACION', ''),
                'lang' => 'es_CO',
                'description' => 'Confirmación al agendar una cita. Sin crear en Meta todavía.',
                'triggered_by' => 'AppointmentWhatsappNotifier::sendConfirmation',
            ],
            'cita_recordatorio' => [
                'name' => env('WHATSAPP_TEMPLATE_CITA_RECORDATORIO', ''),
                'lang' => 'es_CO',
                'description' => 'Recordatorio 2 horas antes de una cita. Sin crear en Meta todavía.',
                'triggered_by' => 'appointments:send-two-hour-reminders',
            ],

            // Tienda online. Las tres van al COMPRADOR, a su telefono, no a
            // un usuario del negocio con canal vinculado. Sin plantilla
            // aprobada en Meta no se manda nada y no falla: el correo
            // (OnlineOrderStatusMail) cubre mientras tanto.
            //
            // Variables, en orden: {{1}} nombre del comprador,
            // {{2}} nombre de la tienda, {{3}} numero de pedido,
            // {{4}} total (solo en pedido_recibido).
            'pedido_recibido' => [
                'name' => env('WHATSAPP_TEMPLATE_PEDIDO_RECIBIDO', ''),
                'lang' => 'es_CO',
                'description' => 'Al comprador: su pedido llegó a la tienda. Sin crear en Meta todavía.',
                'triggered_by' => 'OnlineOrderNotifier::sendReceived',
            ],
            'pedido_confirmado' => [
                'name' => env('WHATSAPP_TEMPLATE_PEDIDO_CONFIRMADO', ''),
                'lang' => 'es_CO',
                'description' => 'Al comprador: la tienda confirmó su pedido. Sin crear en Meta todavía.',
                'triggered_by' => 'OnlineOrderNotifier::sendConfirmed',
            ],
            'pedido_enviado' => [
                'name' => env('WHATSAPP_TEMPLATE_PEDIDO_ENVIADO', ''),
                'lang' => 'es_CO',
                'description' => 'Al comprador: su pedido va en camino. Sin crear en Meta todavía.',
                'triggered_by' => 'OnlineOrderNotifier::sendShipped',
            ],
        ],

        // WhatsApp Flows publicados en Meta, para confirmar borradores de
        // escritura del chat de IA sin salir de WhatsApp (Fase 2). Sin 'id'
        // configurado, ProcessWhatsAppInbound cae de vuelta al aviso de
        // texto ("confirmalo desde el POS") - mismo patron tolerante que las
        // plantillas. 'screen'/'body'/'cta' son el mejor esfuerzo contra los
        // argumentos reales de CreateExpenseCapability; falta verificarlos
        // contra el Flow JSON real una vez WHATSAPP_FLOW_GASTO_ID exista.
        'flows' => [
            'gasto' => [
                'id' => env('WHATSAPP_FLOW_GASTO_ID'),
                'screen' => 'GASTO',
                'body' => 'Confirma los datos del gasto:',
                'cta' => 'Confirmar',
                'description' => 'Confirmar un borrador de gasto del Asistente de IA sin salir de WhatsApp. Sin crear en Meta todavía.',
                'triggered_by' => 'ProcessWhatsAppInbound::sendExpenseFlow',
            ],
        ],
    ],

];
