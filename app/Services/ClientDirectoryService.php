<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Client;

/**
 * El directorio de clientes se llena solo: quien cobra no tiene que tocar
 * "guardar como cliente". El POS sugiere los existentes mientras se escribe
 * (con su celular, para confirmar que es la misma persona); si no se escoge
 * ninguno, al guardar la venta se busca por telefono y, si no existe, se crea.
 */
class ClientDirectoryService
{
    /**
     * El cliente de una venta o cuenta. Hace falta nombre Y telefono para
     * crear uno: "Juan" a secas es el nombre de una cuenta de mesa, no una
     * persona identificable, y llenaria el directorio de fichas repetidas.
     */
    public function resolveForSale(Business $business, ?int $clientId, ?string $name, ?string $phone): ?int
    {
        if ($clientId !== null) {
            return $clientId;
        }
        if (! $business->hasFeature('clients')) {
            return null;
        }

        $name = trim((string) $name);
        $phone = trim((string) $phone);
        $digits = preg_replace('/\D/', '', $phone);
        if ($name === '' || strlen((string) $digits) < 7) {
            return null;
        }

        $existing = Client::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('phone', $phone)
            ->first();
        if ($existing !== null) {
            return $existing->id;
        }

        // El tope del plan no puede tumbar una venta: sin cupo, sin ficha.
        $count = Client::withoutGlobalScopes()->where('business_id', $business->id)->count();
        if ($count >= $business->clientLimit()) {
            return null;
        }

        return Client::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'name' => $name,
            'phone' => $phone,
        ])->id;
    }
}
