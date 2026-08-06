<?php

namespace App\Services;

use App\Models\Business;
use App\Support\SystemConfigStore;

/**
 * Fuente unica del monto mensual que se le cobra a un negocio, con la promo
 * de descuento por tiempo limitado aplicada. Antes este calculo estaba
 * duplicado entre la pantalla de facturacion y el checkout - cualquier
 * cambio aplicado a una sola copia (ej. una promo nueva) hace que la
 * pantalla muestre un precio y se cobre otro, asi que ambos caminos
 * consumen este servicio.
 *
 * El addon de IA (`ai_addon_*`) no se incluye: no existe todavia un
 * equivalente de facturacion de IA en este repo (ver
 * docs/MIGRATION_BACKLOG.md) - en el legacy tambien esta muerto hoy.
 */
class SubscriptionPricingService
{
    /**
     * Desglose completo del cobro mensual.
     *
     * @return array{
     *     plan_standard_cop: int,
     *     plan_base_cop: int,
     *     has_custom_price: bool,
     *     promo_discount_percent: int,
     *     promo_months: int,
     *     paid_cycles: int,
     *     is_promo_eligible: bool,
     *     promo_discount_cop: int,
     *     plan_after_promo_cop: int,
     *     total_cop: int
     * }
     */
    public function breakdown(Business $business): array
    {
        $planStandard = $this->resolvePlanPrice($business);
        $hasCustomPrice = (int) $business->custom_price_cop > 0;
        $planBase = $hasCustomPrice ? (int) $business->custom_price_cop : $planStandard;

        $promoDiscountPercent = (int) SystemConfigStore::get(
            'marketing.promo_discount_percent',
            (string) (config('marketing.pricing.promo_discount_percent') ?? 0)
        );
        $promoMonths = (int) SystemConfigStore::get(
            'marketing.promo_months',
            (string) (config('marketing.pricing.promo_months') ?? 0)
        );
        $paidCycles = $business->subscriptionPromoCyclesConsumed();
        $isPromoEligible = $promoMonths > 0 && $promoDiscountPercent > 0 && $paidCycles < $promoMonths;

        $planAfterPromo = $isPromoEligible
            ? (int) round($planBase * max(0, 100 - $promoDiscountPercent) / 100)
            : $planBase;
        $planAfterPromo = max(0, $planAfterPromo);

        return [
            'plan_standard_cop' => $planStandard,
            'plan_base_cop' => $planBase,
            'has_custom_price' => $hasCustomPrice,
            'promo_discount_percent' => $promoDiscountPercent,
            'promo_months' => $promoMonths,
            'paid_cycles' => $paidCycles,
            'is_promo_eligible' => $isPromoEligible,
            'promo_discount_cop' => max(0, $planBase - $planAfterPromo),
            'plan_after_promo_cop' => $planAfterPromo,
            'total_cop' => $planAfterPromo,
        ];
    }

    /** Monto total a cobrar. Atajo sobre breakdown(). */
    public function totalCop(Business $business): int
    {
        return $this->breakdown($business)['total_cop'];
    }

    private function resolvePlanPrice(Business $business): int
    {
        return match ($business->subscription_plan) {
            'full' => (int) SystemConfigStore::get('plans.full_price_cop', '85000'),
            'basic' => (int) SystemConfigStore::get('plans.basic_price_cop', '65000'),
            default => (int) SystemConfigStore::get(
                'marketing.monthly_cop',
                (string) (config('marketing.pricing.monthly_cop') ?? 0)
            ),
        };
    }
}
