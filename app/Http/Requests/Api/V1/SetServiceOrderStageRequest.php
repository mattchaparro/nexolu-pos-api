<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetServiceOrderStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Existencia validada aca; que pertenezca al workflow asignado
            // a ESTE negocio lo revalida ServiceOrderService::setStage()
            // (una etapa de otro negocio existe en la tabla igual).
            'stage_id' => ['required', 'integer', 'exists:service_workflow_stages,id'],
        ];
    }
}
