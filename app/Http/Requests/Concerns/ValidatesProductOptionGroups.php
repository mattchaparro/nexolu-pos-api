<?php

namespace App\Http\Requests\Concerns;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Reglas del payload `option_groups` al crear/editar un producto (salsas,
 * toppings). Se reemplaza el conjunto completo: lo que no venga se borra.
 */
trait ValidatesProductOptionGroups
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function optionGroupRules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'option_groups' => ['sometimes', 'array', 'max:20'],
            'option_groups.*.id' => ['nullable', 'integer'],
            'option_groups.*.name' => ['required', 'string', 'max:80'],
            'option_groups.*.min_choices' => ['required', 'integer', 'min:0', 'max:50'],
            'option_groups.*.max_choices' => ['required', 'integer', 'min:1', 'max:50'],
            'option_groups.*.options' => ['required', 'array', 'min:1', 'max:60'],
            'option_groups.*.options.*.id' => ['nullable', 'integer'],
            'option_groups.*.options.*.name' => ['required', 'string', 'max:80'],
            'option_groups.*.options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'option_groups.*.options.*.ingredient_id' => [
                'nullable',
                BusinessScopedExists::for('ingredients', $businessId),
            ],
            'option_groups.*.options.*.ingredient_quantity' => [
                'nullable',
                'required_with:option_groups.*.options.*.ingredient_id',
                'numeric',
                'min:0.001',
            ],
            'option_groups.*.options.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function validateOptionGroupsRules(Validator $validator): void
    {
        foreach ((array) $this->input('option_groups', []) as $i => $group) {
            $min = (int) ($group['min_choices'] ?? 0);
            $max = (int) ($group['max_choices'] ?? 1);
            $activeOptions = collect($group['options'] ?? [])
                ->filter(fn ($option) => ($option['is_active'] ?? true) !== false)
                ->count();

            if ($max < $min) {
                $validator->errors()->add("option_groups.{$i}.max_choices", 'El máximo no puede ser menor que el mínimo.');
            } elseif ($min > $activeOptions) {
                $validator->errors()->add("option_groups.{$i}.min_choices", 'El mínimo es mayor que las opciones activas del grupo.');
            }
        }
    }
}
