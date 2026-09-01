<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * `is_main` no se acepta: la sede principal nace con el negocio y se
     * cambia con su propia accion, no colandola en el alta de otra.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('branches', 'code')
                    ->where('business_id', $this->user()?->business_id)
                    ->whereNull('deleted_at'),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'string', 'max:255'],
            // El prefijo de factura de esta sede. Null hereda el del negocio.
            'invoice_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'ticket_paper_width' => ['nullable', Rule::in(['58', '80'])],
            'ticket_header_tagline' => ['nullable', 'string', 'max:500'],
            'ticket_thanks_message' => ['nullable', 'string', 'max:500'],
            'ticket_footer_text' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
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
