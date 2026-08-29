<?php

namespace App\Http\Requests\Api\V1\Storefront;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout publico: lo llena un comprador anonimo.
 *
 * Fijate en lo que NO se acepta: ni precios, ni subtotales, ni el costo de
 * envio. El navegador dice QUE quiere y CUANTO; el resto lo relee
 * OrderService contra la base. Aceptar un precio del cliente seria dejar que
 * cualquiera comprara a cero.
 */
class StoreStorefrontOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],

            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:150'],

            'is_pickup' => ['sometimes', 'boolean'],
            // La direccion solo es obligatoria si va a domicilio.
            'shipping_address' => ['required_if:is_pickup,false', 'nullable', 'string', 'max:200'],
            'shipping_city' => ['required_if:is_pickup,false', 'nullable', 'string', 'max:80'],
            'shipping_notes' => ['sometimes', 'nullable', 'string', 'max:400'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_name.required' => 'Necesitamos tu nombre para el pedido.',
            'customer_phone.required' => 'Necesitamos un teléfono para contactarte.',
            'shipping_address.required_if' => 'Escribe la dirección de entrega.',
            'shipping_city.required_if' => 'Escribe la ciudad.',
        ];
    }
}
