<?php

namespace App\Services;

use App\Exceptions\AiQuotaExceededException;
use App\Exceptions\AiSubscriptionExpiredException;
use App\Models\AiUsageDaily;
use App\Models\Business;
use App\Models\User;
use App\Support\AiQuotaSettings;
use Illuminate\Support\Carbon;

/**
 * Cupo de mensajes del Asistente de IA: cupo mensual incluido en el plan
 * (con override por negocio en Business::ai_chat_daily_messages, nombre
 * heredado del legacy) + balance de paquetes comprados como fallback una vez
 * agotado. Puerto de AiAccessService::estado()/consumirMensualMensual() del
 * legacy.
 *
 * Orden de consumo deliberado y nunca invertido: primero el cupo mensual
 * (gratis, se resetea cada mes), despues los paquetes (pagados, no expiran)
 * - gastar primero lo comprado mientras todavia sobra cupo gratis le
 * quemaria al cliente algo que pago.
 *
 * Los empleados comparten el mismo pool agregado que el dueno (ai_usage_daily
 * es por negocio, no por usuario) pero con un tope mas bajo
 * (AiQuotaSettings::employeeQuotaShare(), piso de 1 mensaje): asi el dueno
 * siempre conserva una porcion del cupo aunque los empleados ya hayan
 * consumido la suya.
 */
class AiQuotaService
{
    public function __construct(private AiMessagePackService $packs) {}

    /** @throws AiSubscriptionExpiredException */
    public function assertAccess(Business $business): void
    {
        if (! $business->hasAccess()) {
            throw new AiSubscriptionExpiredException;
        }
    }

    /**
     * Descuenta un mensaje del cupo mensual aplicable a $user, o del balance
     * de paquetes si ya no queda cupo mensual disponible. Atomico: nunca dos
     * requests concurrentes pueden hacer que el consumo total supere el cupo.
     *
     * @throws AiQuotaExceededException
     */
    public function consumeMessage(Business $business, User $user): void
    {
        $applicableQuota = $this->applicableQuotaFor($business, $user);
        $today = Carbon::today();
        $consumedBeforeToday = $this->consumedBetween($business, $today->copy()->startOfMonth(), $today->copy()->subDay());
        $remainingToday = $applicableQuota - $consumedBeforeToday;

        if ($remainingToday > 0) {
            $row = AiUsageDaily::firstOrCreate(
                ['business_id' => $business->id, 'date' => $today->toDateString()],
                ['messages_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_micros' => 0]
            );

            $incremented = AiUsageDaily::where('id', $row->id)
                ->where('messages_count', '<', $remainingToday)
                ->increment('messages_count');

            if ($incremented > 0) {
                return;
            }
        }

        if ($this->packs->consumeOne($business)) {
            return;
        }

        throw new AiQuotaExceededException($user->hasRole('admin'));
    }

    /**
     * Estado del cupo para mostrar en la UI (card de Ajustes, ver
     * docs/MIGRATION_BACKLOG.md): cupo mensual del negocio, el aplicable a
     * este usuario, lo consumido este mes, el balance de paquetes, y el
     * tamano/precio del paquete comprable (para mostrarlo en el boton de
     * compra antes de iniciar el checkout).
     *
     * @return array{monthly_quota: int, applicable_quota: int, consumed_this_month: int, remaining_quota: int, pack_balance: int, pack_size: int, pack_price_cop: int, is_admin: bool}
     */
    public function state(Business $business, User $user): array
    {
        $applicableQuota = $this->applicableQuotaFor($business, $user);
        $today = Carbon::today();
        $consumed = $this->consumedBetween($business, $today->copy()->startOfMonth(), $today);

        return [
            'monthly_quota' => $this->monthlyQuotaFor($business),
            'applicable_quota' => $applicableQuota,
            'consumed_this_month' => $consumed,
            'remaining_quota' => max(0, $applicableQuota - $consumed),
            'pack_balance' => (int) $business->ai_message_pack_balance,
            'pack_size' => AiQuotaSettings::packSize(),
            'pack_price_cop' => AiQuotaSettings::packPriceCop(),
            'is_admin' => $user->hasRole('admin'),
        ];
    }

    private function monthlyQuotaFor(Business $business): int
    {
        $override = (int) $business->ai_chat_daily_messages;

        return $override > 0 ? $override : AiQuotaSettings::monthlyIncludedMessages();
    }

    private function applicableQuotaFor(Business $business, User $user): int
    {
        $monthly = $this->monthlyQuotaFor($business);

        if ($user->hasRole('admin')) {
            return $monthly;
        }

        return max(1, (int) floor($monthly * AiQuotaSettings::employeeQuotaShare()));
    }

    private function consumedBetween(Business $business, Carbon $from, Carbon $to): int
    {
        return (int) AiUsageDaily::where('business_id', $business->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('messages_count');
    }
}
