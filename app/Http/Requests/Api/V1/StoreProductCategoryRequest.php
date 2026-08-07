<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductCategory;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductCategoryRequest extends FormRequest
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
        $businessId = $this->user()?->business_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')->where('business_id', $businessId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icon' => ['sometimes', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                BusinessScopedExists::for('product_categories', $businessId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una categoría con ese nombre.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->validateSingleLevelNesting($v);
        });
    }

    /**
     * Un solo nivel de subcategorias (ver docblock de ProductCategory): el
     * padre elegido no puede ser a su vez una subcategoria.
     */
    private function validateSingleLevelNesting(Validator $v): void
    {
        $parentId = $this->input('parent_id');
        if (! $parentId) {
            return;
        }

        $parent = ProductCategory::where('business_id', $this->user()?->business_id)->find($parentId);
        if ($parent && $parent->isSubcategory()) {
            $v->errors()->add(
                'parent_id',
                'Solo se permite un nivel de subcategorías: elige una categoría que no sea ella misma una subcategoría.'
            );
        }
    }
}
