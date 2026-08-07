<?php

namespace App\Traits;

use App\Models\Business;

/**
 * Al guardar el modelo, normaliza payment_method al id configurado en el
 * negocio. Resuelve aliases legacy <-> espanol (cash<->efectivo, etc.) - ver
 * Business::normalizePaymentMethodId(). Mitigacion del BUG CRITICO 1 descrito
 * en el CONTEXT.md legacy (payment_method con formatos inconsistentes entre
 * tablas).
 */
trait NormalizesPaymentMethod
{
    protected static function bootNormalizesPaymentMethod(): void
    {
        static::saving(function ($model) {
            $paymentMethod = $model->payment_method ?? null;
            if ($paymentMethod === null || $paymentMethod === '') {
                return;
            }

            $business = $model->business_id ? Business::find($model->business_id) : null;
            if (! $business) {
                return;
            }

            $normalized = $business->normalizePaymentMethodId((string) $paymentMethod);
            if ($normalized !== null && $normalized !== $paymentMethod) {
                $model->payment_method = $normalized;
            }
        });
    }
}
