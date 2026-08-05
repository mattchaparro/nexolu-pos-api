<?php

namespace App\Capabilities\Expenses;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Support\Str;

/**
 * Tool: crear_gasto (escritura). El Nexolu IA Core ya obtuvo la confirmacion
 * humana explicita antes de llamar este endpoint (ver README de ese repo,
 * seccion "Las escrituras nunca se ejecutan solas": el modelo arma un
 * borrador, el usuario lo confirma en POST /v1/drafts/{id}/confirm, y solo
 * entonces el IA Core llama aca). Esta API no guarda su propio estado de
 * "pendiente" - esa responsabilidad vive enteramente del lado del IA Core.
 */
class CreateExpenseCapability implements Capability
{
    public function __construct(private ExpenseService $expenseService) {}

    public function requiredPermission(): ?string
    {
        return 'expenses.create';
    }

    public function requiredFeature(): ?string
    {
        return 'expenses';
    }

    public function rules(): array
    {
        return [
            'concepto' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:100'],
            'tipo_gasto' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fecha' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $type = $this->resolveExpenseType($business, $arguments['tipo_gasto'] ?? null);

        $expense = $this->expenseService->create([
            'date' => $arguments['fecha'] ?? now()->toDateString(),
            'description' => $arguments['concepto'],
            'value' => $arguments['monto'],
            'type_id' => $type->id,
        ]);

        return [
            'id' => $expense->id,
            'concepto' => $expense->description,
            'monto' => (float) $expense->value,
            'tipo_gasto' => $type->name,
            'fecha' => $expense->date->toDateString(),
        ];
    }

    /**
     * El IA Core manda un nombre de tipo suelto (texto libre del chat), no un
     * type_id: se busca uno existente del negocio (o global, business_id
     * null - ver comentario en ExpenseType) y si no hay coincidencia se crea
     * uno nuevo para este negocio.
     */
    private function resolveExpenseType(Business $business, ?string $name): ExpenseType
    {
        $name = trim($name !== null && $name !== '' ? $name : 'Varios');

        $type = ExpenseType::where(fn ($q) => $q->where('business_id', $business->id)->orWhereNull('business_id'))
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($type) {
            return $type;
        }

        return ExpenseType::create([
            'business_id' => $business->id,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
