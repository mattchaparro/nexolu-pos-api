<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;
        $business = $this->user()?->business;

        return [
            'payment_method' => ['nullable', 'string', 'max:50', Rule::in($business?->allowedPaymentMethodIds() ?? [])],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_delivery' => ['sometimes', 'boolean'],
            'is_non_revenue' => ['sometimes', 'boolean'],
            'non_revenue_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('business_id', $businessId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_id' => [
                'nullable',
                'integer',
                Rule::exists('discounts', 'id')->where('business_id', $businessId)->where('scope', 'item'),
            ],
            'cart_discount_id' => [
                'nullable',
                'integer',
                Rule::exists('discounts', 'id')->where('business_id', $businessId)->where('scope', 'cart'),
            ],
            'service_charge_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ipoconsumo_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
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
            if (! $isNonRevenue && ! $this->filled('payment_method')) {
                $validator->errors()->add('payment_method', 'Selecciona un metodo de pago.');
            }

            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }

            $products = Product::where('business_id', $this->user()?->business_id)
                ->whereIn('id', collect($items)->pluck('product_id')->filter()->values())
                ->get()
                ->keyBy('id');

            foreach ($items as $i => $item) {
                $product = $products->get($item['product_id'] ?? null);
                if (! $product) {
                    continue;
                }

                if ($product->price_varies_at_sale
                    && (! array_key_exists('unit_price', $item) || $item['unit_price'] === '' || $item['unit_price'] === null)) {
                    $validator->errors()->add("items.{$i}.unit_price", 'Indica el precio para «'.$product->name.'».');

                    continue;
                }

                $quantity = (int) ($item['quantity'] ?? 0);
                if ($product->track_stock && $quantity > $product->stock) {
                    $validator->errors()->add(
                        "items.{$i}.quantity",
                        'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $product->stock.').'
                    );
                }
            }
        });
    }
}
