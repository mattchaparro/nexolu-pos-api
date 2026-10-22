<?php

namespace Tests\Feature\Api\V1;

use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\ProductAvailability;
use App\Support\SellableProductPayload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * /products/sellable dejo de usar ProductResource por rendimiento (ver
 * SellableProductPayload). El contrato tiene que seguir siendo identico
 * byte a byte: Vender, cuentas abiertas y apartados leen ese JSON, y un
 * campo que cambie de forma o desaparezca rompe el POS en mostrador.
 */
class SellableCatalogContractTest extends TestCase
{
    use DatabaseTransactions;

    /** Todas las formas de producto juntas, para que la comparacion no se quede corta. */
    private function catalog(Business $business): void
    {
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        Product::factory()->create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'name' => 'Simple', 'price' => 12000, 'stock' => 7, 'track_stock' => true,
        ]);
        Product::factory()->create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'name' => 'Servicio sin stock', 'track_stock' => false, 'is_service' => true,
        ]);
        Product::factory()->create([
            'business_id' => $business->id, 'name' => 'Precio variable',
            'price_varies_at_sale' => true, 'cost_price' => 3000,
        ]);
        Product::factory()->create([
            'business_id' => $business->id, 'name' => 'Venta unica',
            'is_single_sale' => true, 'stock' => 1,
        ]);

        $combo = Product::factory()->create(['business_id' => $business->id, 'name' => 'Combo', 'track_stock' => false]);
        $piece = Product::factory()->create(['business_id' => $business->id, 'name' => 'Pieza', 'track_stock' => true, 'stock' => 9]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 500]);
        $combo->components()->createMany([
            ['business_id' => $business->id, 'component_product_id' => $piece->id, 'quantity' => 2, 'sort_order' => 0],
            ['business_id' => $business->id, 'ingredient_id' => $ingredient->id, 'quantity' => 50, 'sort_order' => 1],
        ]);

        $withRecipe = Product::factory()->create(['business_id' => $business->id, 'name' => 'Con receta']);
        $withRecipe->ingredients()->attach($ingredient->id, ['quantity' => 25]);
    }

    /**
     * Diferencias legibles entre los dos payloads: con mil productos, el diff
     * crudo de PHPUnit es ilegible.
     *
     * @return list<string>
     */
    private function differences(Business $business, User $user): array
    {
        // El resolver se pone DESPUES de instance(): al rebindear 'request',
        // Laravel instala el suyo (el del guard) y pisaria este.
        $request = Request::create('/api/v1/products/sellable');
        app()->instance('request', $request);
        $request->setUserResolver(fn () => $user);

        // Si esto falla, el montaje de la prueba no refleja una peticion real
        // y la comparacion de abajo no probaria nada.
        $this->assertSame($user->id, $request->user()?->id, 'el request de prueba tiene que traer al usuario');

        $products = ProductAvailability::forBusiness($business);

        $old = json_decode(json_encode(ProductResource::collection($products)->toArray($request)), true);
        $new = json_decode(json_encode(SellableProductPayload::forCatalog($products, $business, $user)), true);

        $differences = [];

        if (count($old) !== count($new)) {
            return ['cantidad de productos: '.count($old).' vs '.count($new)];
        }

        foreach ($old as $i => $before) {
            $after = $new[$i];
            $name = $before['name'] ?? "#{$i}";

            foreach (array_diff(array_keys($before), array_keys($after)) as $missing) {
                $differences[] = "{$name}: falta la clave '{$missing}'";
            }

            foreach (array_diff(array_keys($after), array_keys($before)) as $extra) {
                $differences[] = "{$name}: sobra la clave '{$extra}'";
            }

            if (array_keys($before) !== array_keys($after)) {
                $differences[] = "{$name}: las claves vienen en otro orden";
            }

            foreach ($before as $key => $value) {
                if (array_key_exists($key, $after) && $value !== $after[$key]) {
                    $differences[] = "{$name}.{$key}: ".json_encode($value).' vs '.json_encode($after[$key]);
                }
            }
        }

        return $differences;
    }

    public function test_the_payload_is_identical_with_every_feature_on(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => array_merge(BusinessFeaturePresets::full(), [
                'ingredients' => true, 'variants' => true, 'product_options' => true,
            ]),
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $this->catalog($business);

        $this->assertSame([], $this->differences($business, $user));
    }

    /** El caso de Central Cell: sin recetas, variantes ni opciones, o sea el camino cacheado. */
    public function test_the_payload_is_identical_on_the_cached_path(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => array_merge(BusinessFeaturePresets::full(), [
                'ingredients' => false, 'variants' => false, 'product_options' => false,
            ]),
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $this->catalog($business);

        $this->assertSame([], $this->differences($business, $user));
    }

    /** cost_price solo debe venir con inventory.view, igual que antes. */
    public function test_the_payload_is_identical_for_a_cashier_without_inventory_view(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create(['business_id' => $business->id]);
        $cashier->assignRole('employee');
        $this->catalog($business);

        $this->assertSame([], $this->differences($business, $cashier));
    }

    public function test_the_endpoint_still_answers_the_same_shape(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $this->catalog($business);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products/sellable')
            ->assertOk()
            ->assertJsonStructure([['id', 'name', 'price', 'stock', 'track_stock', 'is_active', 'has_variants', 'can_manage_stock', 'combo_stock']]);
    }
}
