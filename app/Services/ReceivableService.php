<?php

namespace App\Services;

use App\Models\Receivable;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReceivableService
{
    /**
     * Cobra un fiado completo (no hay abonos parciales sobre un Receivable en
     * el legacy - solo se cobra el saldo entero de una vez).
     *
     * @param  array{customer_name?: string, customer_phone?: string, customer_identification?: string}  $customerData
     */
    public function collect(User $user, Receivable $receivable, string $paymentMethod, array $customerData = []): Receivable
    {
        if ($receivable->status === 'paid') {
            throw ValidationException::withMessages([
                'receivable' => 'Esta cuenta ya fue cobrada.',
            ]);
        }

        $business = $user->business;
        $method = strtolower(trim($paymentMethod));
        if (! in_array($method, $business->allowedPaymentMethodIds(), true) || $business->isCreditPaymentMethod($method)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metodo de pago no permitido para este negocio.',
            ]);
        }

        $name = trim((string) ($customerData['customer_name'] ?? ''));
        $phone = trim((string) ($customerData['customer_phone'] ?? ''));
        $identification = trim((string) ($customerData['customer_identification'] ?? ''));

        $receivable->update([
            'customer_name' => $name !== '' ? $name : $receivable->customer_name,
            'customer_phone' => $phone !== '' ? $phone : $receivable->customer_phone,
            'customer_identification' => $identification !== '' ? $identification : $receivable->customer_identification,
            'status' => 'paid',
            'payment_method' => $method,
            'paid_at' => now(),
            'balance' => 0,
            'collected_by_user_id' => $user->id,
        ]);

        return $receivable->fresh();
    }
}
