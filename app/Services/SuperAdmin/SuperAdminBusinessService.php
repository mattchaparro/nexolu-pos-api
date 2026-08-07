<?php

namespace App\Services\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida comercial de un negocio (no confundir con la configuracion
 * operativa del negocio, que vive en BusinessController). Todo lo que mueve
 * plata o dias de suscripcion pasa por aqui para que quede en
 * saas_subscription_payments de forma consistente.
 */
class SuperAdminBusinessService
{
    /**
     * Extiende/activa el periodo pago y deja registro del pago. Es la accion
     * principal de la pantalla de cobros: un solo request cubre "cobre esto,
     * por estos dias, opcionalmente cambiando de plan".
     *
     * @param  array{days: int, amount_cop?: ?int, plan?: ?string, custom_price_cop?: ?int, notes?: ?string}  $data
     */
    public function activate(Business $business, User $recordedBy, array $data): SaasSubscriptionPayment
    {
        return DB::transaction(function () use ($business, $recordedBy, $data) {
            $plan = $data['plan'] ?? null;
            $days = (int) $data['days'];

            $updates = [];
            if ($plan) {
                $updates['subscription_plan'] = $plan;
                // Merge: los defaults del plan llenan claves faltantes, nunca pisan flags ya personalizados.
                $planDefaults = BusinessFeaturePresets::fromPlan($plan);
                $existing = $business->feature_flags ?? [];
                $updates['feature_flags'] = array_merge($planDefaults, $existing);
            }
            if (array_key_exists('custom_price_cop', $data)) {
                $updates['custom_price_cop'] = $data['custom_price_cop'] ?: null;
            }
            if ($updates) {
                $business->update($updates);
            }

            $amount = $this->resolveActivationAmount($business, $plan, $data);

            $business->activate($days);

            $note = trim((string) ($data['notes'] ?? ''));
            if ($note === '') {
                $planLabel = $plan ? (' — Plan '.($plan === 'basic' ? 'Básico' : 'Full')) : '';
                $note = 'Activación / extensión manual (SuperAdmin → Negocios)'.$planLabel.'.';
            }

            $periodLabel = $days >= 365 ? 'Anual' : ($days >= 28 ? 'Mensual' : "{$days} días");
            if ($plan) {
                $periodLabel .= ' — '.($plan === 'basic' ? 'Plan Básico' : 'Plan Full');
            }

            return SaasSubscriptionPayment::create([
                'business_id' => $business->id,
                'amount_cop' => max(0, $amount),
                'period_label' => $periodLabel,
                'days_granted' => $days,
                'paid_at' => now()->toDateString(),
                'payment_method' => 'manual_panel',
                'notes' => $note,
                'recorded_by_user_id' => $recordedBy->id,
            ]);
        });
    }

    /**
     * @param  array{amount_cop?: ?int, custom_price_cop?: ?int}  $data
     */
    private function resolveActivationAmount(Business $business, ?string $plan, array $data): int
    {
        if (! empty($data['amount_cop'])) {
            return (int) $data['amount_cop'];
        }

        if ($plan) {
            $customPrice = array_key_exists('custom_price_cop', $data) && $data['custom_price_cop']
                ? (int) $data['custom_price_cop']
                : $business->custom_price_cop;

            return $customPrice ?: BusinessFeaturePresets::planPriceCop($plan);
        }

        return $business->monthlyPriceCop();
    }

    public function changePlan(Business $business, string $plan): Business
    {
        $planDefaults = BusinessFeaturePresets::fromPlan($plan);
        $existing = is_array($business->feature_flags) ? $business->feature_flags : [];

        $business->update([
            'subscription_plan' => $plan,
            'feature_flags' => array_merge($planDefaults, $existing),
        ]);

        return $business;
    }

    /**
     * @param  array<string, bool>  $flags
     */
    public function updateConfig(Business $business, string $plan, array $flags): Business
    {
        $planDefaults = BusinessFeaturePresets::fromPlan($plan);
        $normalized = [];
        foreach ($planDefaults as $key => $default) {
            $normalized[$key] = isset($flags[$key]) ? (bool) $flags[$key] : $default;
        }
        // Preserva claves extra que no esten en el plan (flags de features futuras).
        foreach ($flags as $key => $value) {
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = (bool) $value;
            }
        }

        $business->update([
            'subscription_plan' => $plan,
            'feature_flags' => $normalized,
        ]);

        return $business;
    }
}
