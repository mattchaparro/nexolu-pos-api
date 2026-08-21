<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'identification' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = trim((string) $this->input('name', ''));
            $phone = trim((string) $this->input('phone', ''));

            // Solo nombre+telefono, no nombre solo - un nombre repetido sin
            // mas dato (ej. "Carlos") es comun y no implica que sea la
            // misma persona, ver como pidio el usuario.
            if ($name === '' || $phone === '') {
                return;
            }

            $exists = Client::where('business_id', $this->user()?->business_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('phone', $phone)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'Ya existe un cliente con ese nombre y teléfono.');
            }
        });
    }
}
