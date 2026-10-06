<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinancingCredit;
use App\Models\FinancingProvider;
use App\Models\Sale;
use App\Models\SalePaymentSplit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class FinancingService
{
    /**
     * Cobro de una venta financiada: la inicial en un solo medio y el resto
     * como linea "financiado", que no es plata en caja. Devuelve el
     * payment_method de la venta ('mixed', o 'financiado' si no hubo inicial).
     *
     * @param  array{financing_provider_id: int, amount: float|int|string, approval_number?: ?string}  $financing
     */
    public function applyToSale(Business $business, Sale $sale, array $financing, ?string $initialMethod, float $grandTotal): string
    {
        $provider = FinancingProvider::where('business_id', $business->id)
            ->where('is_active', true)
            ->find($financing['financing_provider_id']);

        if (! $provider) {
            throw ValidationException::withMessages(['financing.financing_provider_id' => 'Elige una financiadora activa.']);
        }

        $financed = round((float) $financing['amount'], 2);

        if ($financed <= 0 || $financed > round($grandTotal, 2) + 0.009) {
            throw ValidationException::withMessages(['financing.amount' => 'El valor financiado debe ser mayor a cero y no puede superar el total de la venta.']);
        }

        $initial = round($grandTotal - $financed, 2);
        $method = FinancingCredit::PAYMENT_METHOD;

        if ($initial > 0.009) {
            $initialMethod = strtolower(trim((string) $initialMethod));

            if ($initialMethod === '') {
                throw ValidationException::withMessages(['payment_method' => 'Selecciona con qué medio paga la inicial.']);
            }

            $business->assertValidPaymentMethod($initialMethod, forbidCredit: true);

            SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => $initialMethod, 'amount' => $initial, 'payer_label' => 'Inicial']);
            SalePaymentSplit::create(['sale_id' => $sale->id, 'payment_method' => $method, 'amount' => $financed, 'payer_label' => $provider->name]);
            $method = 'mixed';
        }

        FinancingCredit::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'financing_provider_id' => $provider->id,
            'amount' => $financed,
            'approval_number' => $financing['approval_number'] ?? null,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'customer_identification' => $sale->customer_identification,
            'status' => FinancingCredit::STATUS_PENDING,
            'expected_payout_date' => $provider->expected_payout_days !== null
                ? now()->addDays($provider->expected_payout_days)->toDateString()
                : null,
        ]);

        return $method;
    }

    /** La financiadora giro el credito, completo. */
    public function registerPayout(User $user, FinancingCredit $credit, array $data): FinancingCredit
    {
        if ($credit->status === FinancingCredit::STATUS_PAID) {
            throw ValidationException::withMessages(['credit' => 'Este crédito ya está marcado como pagado.']);
        }

        $credit->update([
            'status' => FinancingCredit::STATUS_PAID,
            'paid_at' => $data['paid_at'] ?? now(),
            'payout_method' => $data['payout_method'] ?? null,
            'payout_reference' => $data['payout_reference'] ?? null,
            'notes' => $data['notes'] ?? $credit->notes,
            'received_by_user_id' => $user->id,
        ]);

        return $credit->fresh(['provider', 'sale', 'receivedBy']);
    }

    /** Se marco pagado por error: vuelve a pendiente. */
    public function undoPayout(FinancingCredit $credit): FinancingCredit
    {
        $credit->update([
            'status' => FinancingCredit::STATUS_PENDING,
            'paid_at' => null,
            'payout_method' => null,
            'payout_reference' => null,
            'received_by_user_id' => null,
        ]);

        return $credit->fresh(['provider', 'sale', 'receivedBy']);
    }
}
