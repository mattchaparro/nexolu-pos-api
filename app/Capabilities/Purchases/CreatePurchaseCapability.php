<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\NameMatcher;
use Illuminate\Validation\ValidationException;

/**
 * Tool: crear_compra (escritura). Registra una compra a proveedor de un solo
 * articulo, que es lo que se puede dictar sin equivocarse por chat.
 *
 * Suma al stock y recalcula el costo promedio igual que la compra manual del
 * POS, porque delega en el mismo PurchaseService.
 */
class CreatePurchaseCapability implements Capability
{
    use ResolvesProductByName;

    public function __construct(private PurchaseService $purchaseService) {}

    public function requiredPermission(): ?string
    {
        return 'purchases.manage';
    }

    public function requiredFeature(): ?string
    {
        // Se decide por articulo dentro de execute(): una compra puede ser de
        // un producto (feature inventory) o de un insumo (feature ingredients).
        return null;
    }

    public function rules(): array
    {
        return [
            'articulo' => ['required', 'string', 'max:200'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'valor_total' => ['required', 'numeric', 'gt:0'],
            'proveedor' => ['sometimes', 'nullable', 'string', 'max:150'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $line = $this->resolveItem($business, (string) $arguments['articulo']);
        $line['quantity'] = (float) $arguments['cantidad'];
        $line['line_total_cop'] = (float) $arguments['valor_total'];
        $line['notes'] = $arguments['nota'] ?? null;

        $purchase = $this->purchaseService->registerPurchase($user, [
            'supplier_id' => $this->resolveSupplier($arguments['proveedor'] ?? null),
            // Por chat la compra es siempre de HOY: no es un dato que el
            // usuario dicte, es una constante del canal.
            'purchased_at' => now()->toDateString(),
            'notes' => $arguments['nota'] ?? null,
            'lines' => [$line],
        ]);

        return [
            'id' => $purchase->id,
            'fecha' => substr((string) $purchase->purchased_at, 0, 10),
            'total' => round((float) $purchase->total, 2),
            'estado_pago' => $purchase->payment_status,
        ];
    }

    /** @return array{product_id?: int, ingredient_id?: int} */
    private function resolveItem(Business $business, string $name): array
    {
        // El producto manda sobre el insumo: si el negocio vende "Queso" y
        // ademas lo usa de insumo, comprar "queso" casi siempre es reponer lo
        // que se vende. Si solo existe como insumo, cae en el catch.
        if ($business->hasFeature('inventory')) {
            try {
                return ['product_id' => $this->resolveProductByName($name)->id];
            } catch (ValidationException $e) {
                if (! $business->hasFeature('ingredients')) {
                    throw $e;
                }
            }
        }

        if (! $business->hasFeature('ingredients')) {
            throw ValidationException::withMessages([
                'articulo' => 'Este negocio no tiene habilitado el modulo de inventario.',
            ]);
        }

        return ['ingredient_id' => $this->resolveIngredientByName($name)->id];
    }

    private function resolveSupplier(?string $name): ?int
    {
        // El proveedor es opcional a proposito: obligarlo convierte "compre 20
        // gaseosas" en un interrogatorio. Una compra sin proveedor es valida
        // en el POS.
        if ($name === null || trim($name) === '') {
            return null;
        }

        $matches = NameMatcher::filter(
            Supplier::orderBy('name')->get(['id', 'name']),
            $name,
            fn (Supplier $supplier) => (string) $supplier->name
        );

        if (count($matches) === 1) {
            return $matches[0]->id;
        }

        if (count($matches) > 1) {
            throw ValidationException::withMessages([
                'proveedor' => "\"{$name}\" coincide con varios proveedores: "
                    .implode(', ', array_map(fn (Supplier $s) => (string) $s->name, $matches))
                    .'. Preguntale al usuario a cual se refiere.',
            ]);
        }

        throw ValidationException::withMessages([
            'proveedor' => "No hay ningun proveedor registrado que coincida con \"{$name}\". "
                .'Registralo primero en Compras, o deja la compra sin proveedor.',
        ]);
    }
}
