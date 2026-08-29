<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La pasarela propia de un negocio (Wompi o Bold), con la que le cobra a sus
 * compradores. La plata va directo a su cuenta.
 *
 * Distinta de la pasarela de Nexolu (`services.payments_core.api_key`), que
 * es con la que le cobramos la suscripcion a el. Las dos hablan con el mismo
 * Payments Core, pero con credenciales distintas y hacia merchants
 * distintos: confundirlas seria cobrarle al comerciante lo que compro su
 * cliente.
 */
#[Fillable([
    'business_id', 'provider_slug', 'environment', 'payments_core_merchant_id',
    'integration_api_key', 'webhook_secret', 'is_active', 'connected_at', 'last_error',
])]
class BusinessPaymentGateway extends Model
{
    use BelongsToBusiness, HasFactory;

    public const PROVIDER_WOMPI = 'wompi';

    public const PROVIDER_BOLD = 'bold';

    /**
     * Proveedores que el negocio puede conectar. Bold ademas habilita el
     * cobro por datafono con la MISMA llave, asi que le sirve aunque nunca
     * abra su tienda online.
     *
     * @var list<string>
     */
    public const PROVIDERS = [self::PROVIDER_WOMPI, self::PROVIDER_BOLD];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            // Cifrados con la llave de la app: un dump de la base no puede
            // entregar la capacidad de cobrar en nombre del comercio.
            'integration_api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    /** Lista para cobrar de verdad, no solo marcada como activa. */
    public function isUsable(): bool
    {
        return $this->is_active
            && filled($this->integration_api_key)
            && filled($this->payments_core_merchant_id);
    }

    /**
     * Bold no tokeniza tarjetas: su unico flujo online es el link hospedado.
     * Wompi soporta ambos, pero para la tienda usamos link tambien -- no
     * queremos datos de tarjeta pasando por el storefront.
     */
    public function usesHostedLink(): bool
    {
        return true;
    }
}
