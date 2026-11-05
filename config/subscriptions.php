<?php

return [
    /*
     * El bloqueo por falta de pago (Business::isLockedOut) no aplica antes de
     * esta fecha. Existe por la transicion de nov-2026: el bloqueo no existia
     * y quien ya estaba vencido (Las Banquitas) merece un par de dias para
     * enterarse antes de que se le cierre el sistema. Pasada la fecha no
     * tiene efecto; vacio la desactiva.
     */
    'lockout_not_before' => env('SUBSCRIPTION_LOCKOUT_NOT_BEFORE', '2026-11-07'),

    /*
     * Dias despues del vencimiento en que subscriptions:renew intenta cobrar
     * el medio de pago guardado: el dia que vence, los dos de gracia y dos
     * reintentos mas ya bloqueado. No a diario: varios rechazos seguidos de
     * la misma tarjeta le bajan la reputacion al comercio ante el banco.
     */
    'auto_renew_attempt_days' => [0, 1, 2, 4, 7],
];
