<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use App\Support\NameMatcher;
use Illuminate\Validation\ValidationException;

/**
 * Tool: crear_proveedor (escritura).
 *
 * Es el unico hueco de cobertura REAL que quedo registrado en
 * ai_unanswered_questions del chat del legacy: un dueño pidio dar de alta un
 * proveedor y el asistente no supo. Sin esta capacidad el flujo de compra por
 * chat se corta en seco - crear_compra exige un proveedor que ya exista, y la
 * unica salida era mandar al usuario a la pantalla de Compras.
 */
class CreateSupplierCapability implements Capability
{
    public function requiredPermission(): ?string
    {
        return 'purchases.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:40'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $name = trim((string) $arguments['nombre']);

        // Duplicar un proveedor parte su historial de compras en dos, y a
        // partir de ahi "cuanto le he comprado a X" responde mal para siempre.
        // Se compara por palabras y no por igualdad exacta: "Postobon SA" y
        // "postobon" son el mismo.
        $existing = NameMatcher::exact(
            Supplier::orderBy('name')->get(['id', 'name']),
            $name,
            fn (Supplier $supplier) => (string) $supplier->name
        );

        if ($existing !== []) {
            throw ValidationException::withMessages([
                'nombre' => "Ya existe un proveedor llamado \"{$existing[0]->name}\". "
                    .'Usa ese en vez de crear uno nuevo.',
            ]);
        }

        $supplier = Supplier::create([
            'name' => $name,
            'phone' => $arguments['telefono'] ?? null,
            'tax_id' => $arguments['nit'] ?? null,
            'address' => $arguments['direccion'] ?? null,
        ]);

        return [
            'id' => $supplier->id,
            'proveedor' => $supplier->name,
            'telefono' => $supplier->phone,
            'nit' => $supplier->tax_id,
        ];
    }
}
