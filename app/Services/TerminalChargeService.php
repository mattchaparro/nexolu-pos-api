<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessPaymentTerminal;
use App\Models\Sale;
use App\Models\TerminalCharge;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cobrar en el datafono desde la caja.
 *
 * El flujo tiene una espera en el medio y eso gobierna todo el diseño:
 *
 *   1. El cajero elige terminal y dispara el cobro. El monto aparece en el
 *      aparato. Aca NO se sabe si pagaron.
 *   2. El cliente pasa la tarjeta. El proveedor manda un webhook.
 *   3. La caja, que estuvo consultando, ve el cobro aprobado y recien
 *      entonces cierra la venta.
 *
 * La venta nace en el paso 3 y no en el 1: misma regla que en la tienda
 * online -- la venta es el hecho economico y aparece cuando entro la plata.
 */
class TerminalChargeService
{
    /**
     * Trae del proveedor los datafonos del negocio y los deja al dia.
     *
     * Los que ya no vengan se marcan inactivos en vez de borrarse: un cobro
     * viejo apunta a su terminal y borrarla dejaria el historial sin
     * explicacion.
     *
     * @return array<int, BusinessPaymentTerminal>
     */
    public function sync(Business $business): array
    {
        $gateway = $business->paymentGateways()
            ->where('provider_slug', 'bold')
            ->where('is_active', true)
            ->first();

        if ($gateway === null || ! $gateway->isUsable()) {
            throw ValidationException::withMessages([
                'provider' => 'Conecta Bold en Ajustes → Medios de pago antes de sincronizar datáfonos.',
            ]);
        }

        $remote = app(PaymentsCoreService::class)->usingGateway($gateway)->listTerminals();
        $seen = [];

        foreach ($remote as $item) {
            $serial = (string) ($item['serial'] ?? '');
            if ($serial === '') {
                continue;
            }
            $seen[] = $serial;

            BusinessPaymentTerminal::updateOrCreate(
                ['business_id' => $business->id, 'serial' => $serial],
                [
                    'model' => (string) ($item['model'] ?? ''),
                    'name' => $item['name'] ?: null,
                    'status' => (string) ($item['status'] ?? ''),
                    'is_active' => true,
                    'last_synced_at' => now(),
                ],
            );
        }

        BusinessPaymentTerminal::where('business_id', $business->id)
            ->whereNotIn('serial', $seen ?: [''])
            ->update(['is_active' => false]);

        return BusinessPaymentTerminal::where('business_id', $business->id)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Dispara el cobro y devuelve la fila que la caja va a consultar.
     *
     * `$actor` es el cajero: el proveedor quiere saber que vendedor cobro, y
     * ademas es quien va a firmar la venta despues.
     */
    public function start(User $actor, BusinessPaymentTerminal $terminal, float $amount): TerminalCharge
    {
        $business = $actor->business;
        $gateway = $business?->paymentGateways()
            ->where('provider_slug', 'bold')
            ->where('is_active', true)
            ->first();

        if ($business === null || $gateway === null || ! $gateway->isUsable()) {
            throw ValidationException::withMessages([
                'provider' => 'No hay una pasarela conectada para cobrar con datáfono.',
            ]);
        }

        if (! $terminal->isUsable()) {
            throw ValidationException::withMessages([
                'terminal' => 'Ese datáfono no está disponible. Sincroniza la lista e intenta de nuevo.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor a cero.']);
        }

        $response = app(PaymentsCoreService::class)->usingGateway($gateway)->chargeOnTerminal(
            amountCop: (int) round($amount),
            description: 'Venta en '.($business->name ?? 'mostrador'),
            terminalSerial: $terminal->serial,
            terminalModel: $terminal->model,
            // El proveedor lo usa para saber que vendedor cobro.
            sellerEmail: (string) $actor->email,
            metadata: ['business_id' => $business->id, 'user_id' => $actor->id],
        );

        $reference = (string) ($response['reference'] ?? '');
        if ($reference === '') {
            throw new RuntimeException('El cobro se disparó pero no devolvió una referencia con la que seguirlo.');
        }

        return TerminalCharge::create([
            'business_id' => $business->id,
            'user_id' => $actor->id,
            'business_payment_terminal_id' => $terminal->id,
            'reference' => $reference,
            'provider_slug' => 'bold',
            'provider_charge_id' => $response['provider_charge_id'] ?? null,
            'amount' => $amount,
            'status' => TerminalCharge::STATUS_PENDING,
        ]);
    }

    /**
     * Marca el resultado que llego por webhook.
     *
     * Idempotente: un reintento del proveedor sobre un cobro que ya se
     * resolvio no lo mueve. Sin esa guarda, un webhook repetido despues de
     * facturar volveria el cobro a `approved` y se podria cobrar dos veces.
     */
    public function resolve(TerminalCharge $charge, string $status, ?string $reason = null): TerminalCharge
    {
        if (! $charge->isWaiting()) {
            return $charge;
        }

        $charge->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
            'resolved_at' => now(),
        ])->save();

        return $charge;
    }

    /**
     * Consume un cobro aprobado: lo ata a la venta que acaba de crearse.
     *
     * `lockForUpdate` y el chequeo de estado DENTRO del lock porque dos
     * pestañas de la misma caja pueden cerrar la venta a la vez, y el cobro
     * solo puede facturarse una.
     *
     * El monto se compara contra el total REAL que calculo SaleService, no
     * contra uno que mande el cliente: sin eso se podria facturar $200.000
     * con un cobro de $20.000 aprobado.
     *
     * Asume estar dentro de una transaccion (la abre SaleController): si algo
     * de esto falla, la venta tiene que deshacerse tambien.
     */
    public function redeem(string $reference, Sale $sale): void
    {
        $charge = TerminalCharge::withoutGlobalScopes()
            ->where('reference', $reference)
            ->where('business_id', $sale->business_id)
            ->lockForUpdate()
            ->first();

        if ($charge === null || ! $charge->isRedeemable()) {
            throw ValidationException::withMessages([
                'terminal_charge_reference' => 'Ese cobro no está aprobado o ya se usó en otra venta.',
            ]);
        }

        if (abs((float) $charge->amount - (float) $sale->total) > 0.01) {
            throw ValidationException::withMessages([
                'terminal_charge_reference' => 'El monto cobrado en el datáfono no coincide con el total de la venta.',
            ]);
        }

        $charge->forceFill([
            'status' => TerminalCharge::STATUS_CONSUMED,
            'sale_id' => $sale->id,
        ])->save();
    }

    /** Cierra los cobros que nadie resolvio. Lo llama el scheduler. */
    public function expireStale(): int
    {
        return TerminalCharge::withoutGlobalScopes()
            ->where('status', TerminalCharge::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes(TerminalCharge::EXPIRE_AFTER_MINUTES))
            ->update([
                'status' => TerminalCharge::STATUS_EXPIRED,
                'resolved_at' => now(),
            ]);
    }
}
