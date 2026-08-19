<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Sale;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CloseOpenTabRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Que payment_method sea uno de los medios configurados del negocio, y
     * que los montos de un pago dividido cuadren con el total, lo valida
     * OpenTabService::close() - depende de si hay abonos y de cual es el
     * saldo pendiente, algo que esta request no puede saber sin duplicar esa
     * logica.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:50'],
            'payment_splits' => ['sometimes', 'nullable', 'array'],
            'payment_splits.*.method' => ['required_with:payment_splits', 'string', 'max:50'],
            'payment_splits.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payment_splits.*.label' => ['nullable', 'string', 'max:120'],
            'is_non_revenue' => ['sometimes', 'boolean'],
            'non_revenue_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'client_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('clients', $this->user()?->business_id)],
            'apply_service_charge' => ['sometimes', 'boolean'],
            'apply_ipoconsumo' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->boolean('is_non_revenue')) {
                return;
            }

            // El fiado solo es alcanzable por el payment_method "plano", sin
            // pago dividido ni abonos previos - OpenTabService::close()
            // fuerza forbidCredit=true en cualquier otro camino (splits,
            // abonos, saldo restante). Mismo bug real que StoreSaleRequest
            // (venta directa) ya tenia y se corrigio ahi: una cuenta se
            // podia cerrar como fiado sin ningun dato de cliente, dejando
            // una deuda sin forma de cobrarla despues.
            $hasSplits = is_array($this->input('payment_splits')) && count($this->input('payment_splits')) >= 2;
            $sale = $this->route('sale');
            $hasPartials = $sale instanceof Sale && $sale->partialPayments()->exists();

            if ($hasSplits || $hasPartials || ! $this->filled('payment_method')) {
                return;
            }

            $business = $this->user()?->business;
            $isCredit = (bool) $business?->isCreditPaymentMethod($this->input('payment_method'));

            if ($isCredit
                && ! $this->filled('customer_name')
                && ! $this->filled('customer_phone')
                && ! $this->filled('customer_identification')) {
                $validator->errors()->add(
                    'customer_name',
                    'Un fiado necesita al menos un dato del cliente (nombre, telefono o cedula) para poder cobrarlo despues.'
                );
            }
        });
    }
}
