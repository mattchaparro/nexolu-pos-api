<?php

namespace App\Support\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * `Rule::exists($table, 'id')->where('business_id', $businessId)` apareaba
 * repetido en 15 sitios distintos (uno por cada FormRequest que valida un id
 * referenciado - producto, categoria, descuento, mesa, etc.). Nunca es la misma
 * lógica de negocio duplicada, pero es la misma expresión; un typo en una copia
 * (olvidar el ->where) reabre exactamente el hueco de fuga entre tenants que las
 * otras 14 cierran. Centralizarla hace que ese typo sea imposible de cometer de
 * nuevo.
 */
final class BusinessScopedExists
{
    /**
     * @param  array<string, mixed>  $extraWheres  columnas/valor adicionales, ej. ['scope' => 'cart']
     */
    public static function for(string $table, ?int $businessId, array $extraWheres = []): Exists
    {
        $rule = Rule::exists($table, 'id')->where('business_id', $businessId);

        foreach ($extraWheres as $column => $value) {
            $rule = $rule->where($column, $value);
        }

        return $rule;
    }

    /**
     * Para tablas donde business_id es nullable y una fila con business_id=null
     * es un registro global/compartido por todos los negocios (ej. expense_types).
     */
    public static function forOrGlobal(string $table, ?int $businessId): Exists
    {
        return Rule::exists($table, 'id')->where(
            fn ($query) => $query->where(
                fn ($sub) => $sub->where('business_id', $businessId)->orWhereNull('business_id')
            )
        );
    }
}
