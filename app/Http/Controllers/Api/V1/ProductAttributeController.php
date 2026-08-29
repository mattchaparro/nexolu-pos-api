<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductAttributeRequest;
use App\Http\Requests\Api\V1\UpdateProductAttributeRequest;
use App\Http\Resources\Api\V1\ProductAttributeResource;
use App\Models\ProductAttribute;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del catalogo de atributos combinables (Talla, Color, ...) de un
 * negocio, reutilizables por cualquier producto - ver ProductAttribute/
 * ProductAttributeValue y ProductVariant::attributeValues(). Sin Service
 * dedicado, igual que ProductCategoryController: la logica es igual de
 * simple.
 */
class ProductAttributeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductAttributeResource::collection(
            ProductAttribute::with('values')->orderBy('name')->get()
        );
    }

    public function store(StoreProductAttributeRequest $request): ProductAttributeResource
    {
        $attribute = DB::transaction(function () use ($request) {
            $attribute = ProductAttribute::create(['name' => $request->validated('name')]);
            $this->syncValues($attribute, $request->validated('values'));

            return $attribute;
        });

        return new ProductAttributeResource($attribute->load('values'));
    }

    public function show(ProductAttribute $productAttribute): ProductAttributeResource
    {
        return new ProductAttributeResource($productAttribute->load('values'));
    }

    public function update(UpdateProductAttributeRequest $request, ProductAttribute $productAttribute): ProductAttributeResource
    {
        DB::transaction(function () use ($request, $productAttribute) {
            if ($request->has('name')) {
                $productAttribute->update(['name' => $request->validated('name')]);
            }

            // Omitir 'values' deja los valores existentes intactos, igual
            // que 'ingredients' en UpdateProductRequest.
            if ($request->has('values')) {
                $this->syncValues($productAttribute, $request->validated('values'));
            }
        });

        return new ProductAttributeResource($productAttribute->fresh()->load('values'));
    }

    public function destroy(ProductAttribute $productAttribute): Response
    {
        if ($this->hasVariantsUsingAnyValue($productAttribute)) {
            throw ValidationException::withMessages([
                'attribute' => 'No se puede eliminar: hay variantes de producto usando alguno de sus valores.',
            ]);
        }

        $productAttribute->delete();

        return response()->noContent();
    }

    /**
     * Upsert por id (fila con id existente -> update(), sin id -> create());
     * los valores cuyo id no vino en $values se borran, salvo que esten en
     * uso por alguna variante (guard explicito, igual criterio que
     * ProductCategoryController::destroy() con categorias con productos).
     *
     * @param  list<array{id?: int, value: string, sort_order?: int}>  $values
     */
    private function syncValues(ProductAttribute $attribute, array $values): void
    {
        $keepIds = [];

        foreach ($values as $i => $row) {
            $id = $row['id'] ?? null;
            $payload = [
                'value' => $row['value'],
                'sort_order' => $row['sort_order'] ?? $i,
                'business_id' => $attribute->business_id,
            ];

            $value = $id ? $attribute->values()->find($id) : null;
            if ($value) {
                $value->update($payload);
            } else {
                $value = $attribute->values()->create($payload);
            }

            $keepIds[] = $value->id;
        }

        $removedIds = $attribute->values()->whereNotIn('id', $keepIds)->pluck('id');

        if ($removedIds->isNotEmpty()
            && DB::table('product_variant_attribute_value')->whereIn('product_attribute_value_id', $removedIds)->exists()) {
            throw ValidationException::withMessages([
                'values' => 'No se puede quitar un valor que ya está en uso por una variante.',
            ]);
        }

        $attribute->values()->whereIn('id', $removedIds)->delete();
    }

    private function hasVariantsUsingAnyValue(ProductAttribute $attribute): bool
    {
        return DB::table('product_variant_attribute_value')
            ->where('product_attribute_id', $attribute->id)
            ->exists();
    }
}
