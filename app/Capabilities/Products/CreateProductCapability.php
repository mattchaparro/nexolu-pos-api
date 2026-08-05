<?php

namespace App\Capabilities\Products;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Str;

/**
 * Tool: crear_producto (escritura). Igual que CreateExpenseCapability: el
 * IA Core solo llama aca despues de que el usuario confirmo el borrador.
 */
class CreateProductCapability implements Capability
{
    public function __construct(private ProductService $productService) {}

    public function requiredPermission(): ?string
    {
        return 'inventory.add';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'precio' => ['required', 'numeric', 'min:0'],
            'costo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $category = $this->resolveCategory($arguments['categoria'] ?? null);

        $product = $this->productService->create([
            'name' => $arguments['nombre'],
            'price' => $arguments['precio'],
            'cost_price' => $arguments['costo'] ?? 0,
            'category_id' => $category->id,
        ]);

        return [
            'id' => $product->id,
            'nombre' => $product->name,
            'precio' => (float) $product->price,
            'costo' => (float) $product->cost_price,
            'categoria' => $category->name,
        ];
    }

    /**
     * El IA Core manda un nombre de categoria suelto, no un category_id:
     * ProductCategory ya esta scopeada al negocio via BelongsToBusiness, asi
     * que solo hace falta buscar por nombre o crearla.
     */
    private function resolveCategory(?string $name): ProductCategory
    {
        $name = trim($name !== null && $name !== '' ? $name : 'General');

        return ProductCategory::whereRaw('LOWER(name) = ?', [Str::lower($name)])->first()
            ?? ProductCategory::create(['name' => $name]);
    }
}
