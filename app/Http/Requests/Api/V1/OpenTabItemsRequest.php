<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Usada tanto para agregar items a una cuenta abierta (POST) como para
 * reemplazar su carrito completo (PUT) - la validacion de "items" es
 * identica, solo cambia que hace el controller con ella.
 */
class OpenTabItemsRequest extends FormRequest
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
        return $this->saleItemRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateSaleItemLines($validator));
    }
}
