<?php

namespace App\Services;

use App\Models\Expense;

/**
 * Unico punto de creacion/edicion de gastos. Extraido de ExpenseController
 * para que App\Capabilities\Expenses\CreateExpenseCapability (invocada por
 * el Nexolu IA Core) reutilice exactamente la misma logica que el endpoint
 * HTTP normal, en vez de reimplementarla.
 */
class ExpenseService
{
    /**
     * @param  array<string, mixed>  $data  ya validado, con type_id resuelto (no un
     *                                      nombre de tipo suelto - ver CreateExpenseCapability)
     */
    public function create(array $data): Expense
    {
        return Expense::create([
            'scope' => 'operacional',
            'payment_method' => Expense::PAYMENT_METHODS[0],
            ...$data,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Expense $expense, array $data): Expense
    {
        $expense->update($data);

        return $expense->fresh()->load('type');
    }
}
