<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    /**
     * Todo `sometimes`: la pantalla de sedes puede mandar solo lo que cambio
     * (apagarla, cambiarle el prefijo) sin tener que reenviar la ficha entera
     * y arriesgarse a borrar lo que no toco.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('branches', 'code')
                    ->where('business_id', $this->user()?->business_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('branch')),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_prefix' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'ticket_paper_width' => ['sometimes', 'nullable', Rule::in(['58', '80'])],
            'ticket_header_tagline' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_thanks_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_footer_text' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            // Cambiar cual es la principal: el modelo degrada sola a la
            // anterior (ver Branch::booted), asi que no hay que apagarla a
            // mano en dos pasos.
            'is_main' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_prefix.regex' => 'El prefijo de factura solo puede tener letras y numeros.',
            'code.unique' => 'Ya tienes una sede con ese codigo.',
        ];
    }
}
