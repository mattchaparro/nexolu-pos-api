<?php

// Canal de soporte de Nexolu hacia los negocios (ver App\Support\SupportContact).
// El valor efectivo puede sobreescribirse en caliente desde system_config
// (`billing.whatsapp_number`), igual que en el legacy.
return [
    'whatsapp_number' => env('SUPPORT_WHATSAPP_NUMBER', '573239251072'),
];
