<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
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
            // `image` (y no solo el mime declarado) obliga a que el archivo
            // sea de verdad una imagen decodificable: un .php renombrado a
            // .jpg no pasa. Se excluye SVG a proposito - admite scripts
            // embebidos y estas fotos se sirven en un dominio publico.
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'La foto no puede pesar mas de 10 MB.',
            'image.mimes' => 'La foto debe ser JPG, PNG o WebP.',
        ];
    }
}
