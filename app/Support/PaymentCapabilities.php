<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;

/**
 * Que puede hacer cada proveedor, y con QUE credenciales.
 *
 * La primera version modelaba un solo juego de llaves por proveedor. Estaba
 * mal: Bold emite DOS juegos distintos y no intercambiables -- uno para el
 * boton de pagos (cobrar por internet) y otro para la API de datafono
 * (cobrar en el mostrador). Pedirle datafonos con la llave del boton de
 * pagos devuelve 403; asi se descubrio, probando contra la cuenta real.
 *
 * Por eso el modelo es por CAPACIDAD y no por proveedor: un comercio puede
 * tener una, la otra o las dos. Hay negocios que solo quieren datafono y
 * nunca abren tienda, y al reves.
 */
class PaymentCapabilities
{
    /** Cobrar por internet: la tienda online. */
    public const ONLINE = 'online';

    /** Cobrar en el mostrador contra un datafono fisico. */
    public const TERMINAL = 'terminal';

    /**
     * Credenciales por proveedor y capacidad.
     *
     * Las claves del array interno son los nombres que viajan a
     * payments-core; el frontend dibuja el formulario a partir de esto en vez
     * de tenerlos escritos a mano.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function map(): array
    {
        return [
            BusinessPaymentGateway::PROVIDER_BOLD => [
                self::ONLINE => ['identity_key', 'secret_key'],
                // Juego aparte, el de "API Datafono" del panel de Bold.
                self::TERMINAL => ['terminal_identity_key', 'terminal_secret_key'],
            ],
            BusinessPaymentGateway::PROVIDER_WOMPI => [
                // Wompi no cobra contra datafono: solo por internet.
                self::ONLINE => ['public_key', 'private_key', 'integrity_secret', 'events_secret'],
            ],
        ];
    }

    /** @return list<string> */
    public static function fieldsFor(string $provider, string $capability): array
    {
        return self::map()[$provider][$capability] ?? [];
    }

    /** Todas las credenciales que acepta un proveedor, de cualquier capacidad. */
    public static function allFieldsFor(string $provider): array
    {
        return array_merge(...array_values(self::map()[$provider] ?? [[]]));
    }

    /** @return list<string> */
    public static function capabilitiesOf(string $provider): array
    {
        return array_keys(self::map()[$provider] ?? []);
    }

    /**
     * Si el negocio puede configurar esta capacidad.
     *
     * Cobrar por internet no tiene sentido sin tienda: seria pedirle llaves
     * para algo que no puede usar. El datafono, en cambio, es del mostrador y
     * esta disponible siempre -- mismo criterio que las ventas cruzadas.
     */
    public static function availableTo(Business $business, string $capability): bool
    {
        return $capability === self::ONLINE
            ? $business->hasFeature('online_store')
            : true;
    }
}
