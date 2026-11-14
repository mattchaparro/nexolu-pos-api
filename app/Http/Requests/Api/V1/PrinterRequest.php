<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Printer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edicion de una impresora. En la edicion todo es `sometimes`: la
 * pantalla puede mandar solo lo que cambio (apagarla, cambiarle el rol).
 */
class PrinterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'role' => [$required, Rule::in(Printer::ROLES)],
            'connection' => [$required, Rule::in(Printer::CONNECTIONS)],
            'address' => [$required, 'string', 'max:191'],
            'paper_width' => ['sometimes', 'integer', Rule::in(Printer::PAPER_WIDTHS)],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'print_agent_id' => [$required, 'integer', Rule::exists('print_agents', 'id')->where('business_id', $businessId)],
        ];
    }

    /**
     * Una impresora de red necesita ip:puerto; sin puerto se asume el 9100,
     * el de casi todas las termicas.
     */
    protected function prepareForValidation(): void
    {
        $connection = $this->input('connection', $this->route('printer')?->connection);
        $address = trim((string) $this->input('address', ''));
        if ($connection === Printer::CONNECTION_NETWORK && $address !== '' && ! str_contains($address, ':')) {
            $this->merge(['address' => $address.':9100']);
        }
    }

    public function messages(): array
    {
        return [
            'print_agent_id.required' => 'Elige el computador que maneja esta impresora.',
            'print_agent_id.exists' => 'Ese computador no es de este negocio.',
            'role.in' => 'La impresora es de cocina o del mostrador.',
        ];
    }
}
