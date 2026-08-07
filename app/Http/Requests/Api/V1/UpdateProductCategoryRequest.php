<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductCategory;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductCategoryRequest extends FormRequest
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
                'sometimes',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')
                    ->where('business_id', $businessId)
                    ->ignore($this->route('product_category')),
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
     * Un solo nivel de subcategorias (ver docblock de ProductCategory):
     * - no puede ser subcategoria de si misma.
     * - el padre elegido no puede ser a su vez una subcategoria.
     * - si esta categoria ya tiene hijas propias, no puede convertirse en
     *   subcategoria de otra (las dejaria a 2 niveles del raiz).
     */
    private function validateSingleLevelNesting(Validator $v): void
    {
        if (! $this->has('parent_id') || ! $this->input('parent_id')) {
            return;
        }

        $parentId = (int) $this->input('parent_id');
        $category = $this->route('product_category');

        if ($category instanceof ProductCategory && $parentId === $category->id) {
            $v->errors()->add('parent_id', 'Una categoría no puede ser subcategoría de sí misma.');

            return;
        }

        $parent = ProductCategory::where('business_id', $this->user()?->business_id)->find($parentId);
        if ($parent && $parent->isSubcategory()) {
            $v->errors()->add(
                'parent_id',
                'Solo se permite un nivel de subcategorías: elige una categoría que no sea ella misma una subcategoría.'
            );

            return;
        }

        if ($category instanceof ProductCategory && $category->children()->exists()) {
            $v->errors()->add(
                'parent_id',
                'Esta categoría ya tiene subcategorías propias y no puede convertirse en subcategoría de otra.'
            );
        }
    }
}
