<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClientRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'identification' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Client $client */
            $client = $this->route('client');
            // sometimes: si el campo no viene en el payload, el nombre/
            // telefono resultante sigue siendo el que ya tenia - hay que
            // compararlo igual, no solo lo que llego en este request.
            $name = trim((string) $this->input('name', $client->name));
            $phone = trim((string) $this->input('phone', $client->phone ?? ''));

            if ($name === '' || $phone === '') {
                return;
            }

            $exists = Client::where('business_id', $this->user()?->business_id)
                ->where('id', '!=', $client->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('phone', $phone)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'Ya existe un cliente con ese nombre y teléfono.');
            }
        });
    }
}
