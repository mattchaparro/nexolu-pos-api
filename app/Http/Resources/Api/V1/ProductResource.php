<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ProductAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'description' => $this->description,
            'how_to_use' => $this->how_to_use,
            'price' => $this->price,
            // Oculto a quien no tenga inventory.view: /products/sellable y
            // /product-categories (index/show) estan abiertos a cualquier
            // empleado que vende (ver routes/api.php), pero eso no debe
            // filtrar el margen del negocio a un cajero sin ese permiso.
            'cost_price' => $this->when($request->user()?->hasBusinessPermission('inventory.view') === true, $this->cost_price),
            'stock' => $this->displayStock(),
            'low_stock_alert_threshold' => $this->low_stock_alert_threshold,
            'track_stock' => $this->track_stock,
            'is_single_sale' => $this->is_single_sale,
            'is_service' => $this->is_service,
            'price_varies_at_sale' => $this->price_varies_at_sale,
            'duration_minutes' => $this->duration_minutes,
            'sku' => $this->sku,
            'image' => $this->image,
            'is_active' => $this->is_active,
            'ingredients' => IngredientResource::collection($this->whenLoaded('ingredients')),
            'has_recipe' => $this->hasRecipe(),
            // Puerto de Admin\InventoryController::buildIndexProps() del legacy
            // (can_manage_stock ahi): un producto de venta unica o con receta
            // no tiene un numero de stock que un humano pueda escribir a mano -
            // el de venta unica siempre es 1/0, y el de receta sale de sus
            // ingredientes (ver displayStock()) - el frontend usa esto para
            // ocultar Agregar/Retirar/Ajustar en vez de duplicar la regla.
            'can_manage_stock' => ! $this->is_single_sale && ! $this->hasRecipe(),
        ];
    }

    /**
     * Igual chequeo que Product::isStockManagedByIngredientsRecipe(), pero
     * sin su fallback de ir a buscar el negocio a la BD cuando la relacion
     * no esta cargada - aca `ingredients` solo se carga cuando el feature ya
     * esta activo (ver ProductController), asi que relationLoaded() alcanza
     * y evita un N+1 por fila en el listado.
     */
    private function hasRecipe(): bool
    {
        return $this->relationLoaded('ingredients') && $this->ingredients->isNotEmpty();
    }

    /**
     * products.stock es una columna "fantasma" para un producto con receta
     * (nunca se decrementa, ver ProductAvailability) - lo que se muestra ahi
     * es cuantas unidades se pueden preparar ahora mismo con el stock actual
     * de sus ingredientes, no el valor crudo de la columna.
     */
    private function displayStock(): int|string
    {
        $computed = ProductAvailability::effectiveStock($this->resource, $this->hasRecipe());

        return is_finite($computed) ? (int) $computed : $this->stock;
    }
}
