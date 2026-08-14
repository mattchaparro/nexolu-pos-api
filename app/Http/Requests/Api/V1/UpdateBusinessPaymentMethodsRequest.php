<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessPaymentMethodsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user?->business_id && ($user->is_business_owner || $user->hasRole('admin'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'methods' => ['required', 'array', 'min:1'],
            'methods.*.pos_payment_method_id' => ['required', 'integer', 'exists:pos_payment_methods,id'],
            'methods.*.is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Un negocio nunca puede quedar sin ningun medio de pago habilitado -
     * puede deshabilitar los que no use, pero siempre debe poder cobrar de
     * alguna forma. El payload puede ser parcial (solo los medios que el
     * admin toco en esta pantalla), asi que la validacion mira el estado
     * FINAL: los medios ya habilitados que este payload no menciona siguen
     * contando, igual que hace syncWithoutDetaching() al guardar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $submitted = collect($this->input('methods', []));
            if ($submitted->isEmpty()) {
                return;
            }

            $submittedIds = $submitted->pluck('pos_payment_method_id')->all();
            $business = $this->user()?->business;
            $business?->loadMissing('posPaymentMethods');

            $untouchedStillEnabled = $business
                ? $business->posPaymentMethods
                    ->filter(fn ($method) => (bool) $method->pivot->is_enabled)
                    ->pluck('id')
                    ->diff($submittedIds)
                    ->isNotEmpty()
                : false;

            $submittedEnabled = $submitted->contains(fn (array $method) => (bool) ($method['is_enabled'] ?? false));

            if (! $untouchedStillEnabled && ! $submittedEnabled) {
                $validator->errors()->add('methods', 'Debes dejar al menos un medio de pago habilitado.');
            }
        });
    }
}
