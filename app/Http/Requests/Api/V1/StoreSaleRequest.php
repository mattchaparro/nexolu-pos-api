<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    use ValidatesSaleItems;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;
        $business = $this->user()?->business;

        return [
            ...$this->saleItemRules(),
            'payment_method' => ['nullable', 'string', 'max:50', Rule::in($business?->allowedPaymentMethodIds() ?? [])],
            // Cobro ya aprobado en un datafono. El monto y la validez se
            // comprueban en el servidor: el cliente solo manda la referencia.
            'terminal_charge_reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'payment_splits' => ['sometimes', 'nullable', 'array'],
            'payment_splits.*.method' => ['required_with:payment_splits', 'string', 'max:50'],
            'payment_splits.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payment_splits.*.label' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'client_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('clients', $businessId)],
            'is_delivery' => ['sometimes', 'boolean'],
            'is_non_revenue' => ['sometimes', 'boolean'],
            'non_revenue_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cart_discount_id' => [
                'nullable',
                'integer',
                BusinessScopedExists::for('discounts', $businessId, ['scope' => 'cart']),
            ],
            // Los montos de los cargos los calcula el servidor desde la config
            // del negocio; el cliente solo puede renunciar a un cargo habilitado.
            'apply_service_charge' => ['sometimes', 'boolean'],
            'apply_ipoconsumo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.in' => 'Metodo de pago no valido.',
            'items.required' => 'Agrega al menos un producto.',
            'items.min' => 'Agrega al menos un producto.',
            'items.*.product_id.exists' => 'Producto no encontrado.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $isNonRevenue = $this->boolean('is_non_revenue');
            $hasSplits = is_array($this->input('payment_splits')) && count($this->input('payment_splits')) >= 2;

            if (! $isNonRevenue && ! $hasSplits && ! $this->filled('payment_method')) {
                $validator->errors()->add('payment_method', 'Selecciona un metodo de pago.');
            }

            // Bug real del legacy (SalesTerminal.vue): un fiado se podia
            // registrar sin ningun dato del cliente, dejando una deuda
            // sin forma de cobrarla despues. Se corrige acá, no solo se
            // traslada - ver docs/BACKEND_READINESS.md del frontend. Un pago
            // dividido nunca es fiado (SaleService::applyMixedPaymentRows
            // fuerza forbidCredit=true en cada linea), asi que $hasSplits no
            // necesita chequeo de cliente aca.
            $business = $this->user()?->business;
            $isCredit = ! $isNonRevenue && ! $hasSplits && $business?->isCreditPaymentMethod($this->input('payment_method'));
            if ($isCredit
                && ! $this->filled('customer_name')
                && ! $this->filled('customer_phone')
                && ! $this->filled('customer_identification')) {
                $validator->errors()->add(
                    'customer_name',
                    'Un fiado necesita al menos un dato del cliente (nombre, telefono o cedula) para poder cobrarlo despues.'
                );
            }

            if ($this->filled('cart_discount_id') && ! ($this->user()?->hasBusinessPermission('discounts.apply') ?? false)) {
                $validator->errors()->add('cart_discount_id', 'No tienes permiso para aplicar descuentos.');
            }

            $this->validateSaleItemLines($validator);
        });
    }
}
