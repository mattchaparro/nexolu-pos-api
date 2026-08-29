<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderProductImagesRequest extends FormRequest
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
            // Que los ids sean de este producto lo verifica
            // ProductImageService::reorder(), que ya tiene cargadas sus fotos.
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['required', 'integer'],
        ];
    }
}
