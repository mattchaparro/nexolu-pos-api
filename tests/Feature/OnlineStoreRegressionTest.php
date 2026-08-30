<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Que la tienda online NO haya roto el POS ni la migracion.
 *
 * Todo lo de la tienda se construyo sobre un sistema con negocios reales
 * vendiendo y con una migracion desde el monolito a medio camino. Estas
 * pruebas son el candado de que nada de eso cambio de comportamiento:
 *
 *  - un negocio SIN tienda sigue vendiendo exactamente igual;
 *  - un negocio recien migrado no queda con su catalogo expuesto a internet
 *    sin haberlo pedido;
 *  - confirmar un pedido online mueve el stock una sola vez y por el mismo
 *    camino que el mostrador;
 *  - las tablas nuevas no se cuelan en el grafo que exporta la migracion.
 */
class OnlineStoreRegressionTest extends TestCase
{
    use DatabaseTransactions;

    // -----------------------------------------------------------------
    // El POS de siempre, intacto
    // -----------------------------------------------------------------

    /**
     * El caso mas comun de todos: un negocio que nunca va a abrir tienda.
     * Nada de lo nuevo debe aparecerle ni estorbarle.
     */
    public function test_un_negocio_sin_tienda_no_ve_nada_de_la_tienda(): void
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => false],
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        $this->assertFalse($business->hasFeature('online_store'));

        // Las pantallas de la tienda estan cerradas...
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/store-settings')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/store-reviews')->assertForbidden();

        // ...y su catalogo no es alcanzable desde internet.
        $this->getJson("/api/v1/storefront/{$business->slug}")->assertNotFound();
    }

    /**
     * Las ventas cruzadas NO van detras del feature de tienda: le sirven al
     * mostrador aunque el negocio nunca abra su tienda. Si algun dia alguien
     * las mete detras del flag, esto lo cacha.
     */
    public function test_las_ventas_cruzadas_funcionan_sin_tienda_online(): void
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => false],
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        $hamburguesa = Product::factory()->create(['business_id' => $business->id]);
        $papas = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/products/{$hamburguesa->id}/cross-sells", ['cross_sell_ids' => [$papas->id]])
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Migracion desde el monolito
    // -----------------------------------------------------------------

    /**
     * EL riesgo de la migracion. Un negocio que llega del legacy trae
     * `feature_flags` nulo, y `hasFeature()` tiene una rama permisiva que
     * devuelve true para negocios antiguos sin JSON.
     *
     * Si `online_store` cayera en esa rama, migrar un negocio publicaria su
     * catalogo en internet sin que nadie lo pidiera. Lo evita
     * BusinessFeaturePresets::OPT_IN_ONLY, y esta prueba es lo que impide que
     * alguien lo saque de esa lista sin darse cuenta.
     */
    public function test_un_negocio_migrado_no_queda_con_la_tienda_abierta(): void
    {
        foreach ([null, []] as $flags) {
            $business = Business::factory()->create(['feature_flags' => $flags]);

            $this->assertFalse(
                $business->hasFeature('online_store'),
                'Un negocio sin flags no puede quedar con la tienda encendida.',
            );

            // Aunque exista la fila de ajustes y este activa, sin el flag el
            // storefront no responde: hacen falta los dos interruptores.
            BusinessStoreSettings::factory()->create([
                'business_id' => $business->id,
                'is_active' => true,
            ]);

            $this->getJson("/api/v1/storefront/{$business->slug}")->assertNotFound();
        }

        // Y el resto de modulos SI sigue disponible para ese negocio antiguo:
        // la rama permisiva no se rompio, solo se acoto.
        $antiguo = Business::factory()->create(['feature_flags' => null]);
        $this->assertTrue($antiguo->hasFeature('clients'));
    }

    /**
     * El comando de migracion descubre las tablas del negocio recorriendo FKs
     * desde las que tienen `business_id` (ver docs/CUTOVER_PER_BUSINESS.md
     * § 4.2). Las tablas de la tienda tienen `business_id`, asi que ENTRARIAN
     * al grafo -- pero no existen en el legacy, que es el origen del export.
     *
     * Lo que esta prueba fija es lo unico que si importa del lado del
     * destino: que ninguna tabla nueva sea obligatoria para insertar un
     * negocio migrado. Si `products` exigiera una fila en `product_images`, o
     * `businesses` una en `business_store_settings`, la migracion fallaria a
     * mitad de camino.
     */
    public function test_las_tablas_de_la_tienda_no_son_obligatorias_para_un_negocio_nuevo(): void
    {
        $business = Business::factory()->create(['feature_flags' => null]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        // Un negocio con productos y sin NADA de la tienda es valido.
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        foreach (['business_store_settings', 'product_images', 'orders', 'product_reviews', 'product_cross_sells'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
            $this->assertSame(
                0,
                DB::table($tabla)->where('business_id', $business->id)->count(),
                "Un negocio nuevo no deberia nacer con filas en {$tabla}.",
            );
        }
    }

    /**
     * Toda tabla nueva lleva `business_id` propio. Es lo que hace que el
     * global scope aisle sin joins, y tambien lo que permite que el
     * descubrimiento del grafo de migracion las tome como raiz el dia que
     * haya que mover un negocio ya migrado.
     */
    public function test_las_tablas_nuevas_estan_atadas_al_negocio(): void
    {
        foreach ([
            'business_store_settings',
            'business_store_images',
            'business_store_interactions',
            'product_images',
            'orders',
            'order_items',
            'product_reviews',
            'product_cross_sells',
        ] as $tabla) {
            $this->assertTrue(
                Schema::hasColumn($tabla, 'business_id'),
                "{$tabla} no tiene business_id: quedaria fuera del aislamiento por negocio.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Integridad de los datos al confirmar un pedido
    // -----------------------------------------------------------------

    /**
     * Confirmar un pedido online tiene que mover el stock UNA vez y por el
     * mismo camino que el mostrador (SaleService/StockService), no escribir
     * `sales` a mano. Si alguien "optimiza" ese camino, aca se ve.
     */
    public function test_confirmar_un_pedido_descuenta_stock_una_sola_vez(): void
    {
        [$business, $owner] = $this->storeBusiness();

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'track_stock' => true,
            'stock' => 10,
            'price' => 5000,
        ]);

        $order = Order::factory()->create([
            'business_id' => $business->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 15000,
            'shipping_fee' => 0,
            'total' => 15000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 3,
            'unit_price' => 5000,
            'subtotal' => 15000,
        ]);

        $movimientosAntes = StockMovement::withoutGlobalScopes()->where('business_id', $business->id)->count();

        app(OrderService::class)->transition($owner, $order, Order::STATUS_CONFIRMED, null, 'cash');

        $order->refresh();
        $product->refresh();

        // Existe la venta, y el pedido apunta a ella.
        $this->assertNotNull($order->sale_id);
        $this->assertDatabaseHas('sales', ['id' => $order->sale_id, 'business_id' => $business->id]);

        // El stock bajo exactamente lo pedido, ni mas ni menos.
        $this->assertEqualsWithDelta(7, (float) $product->stock, 0.001);

        // Y quedo registrado: el inventario del POS se audita por movimientos.
        $this->assertGreaterThan(
            $movimientosAntes,
            StockMovement::withoutGlobalScopes()->where('business_id', $business->id)->count(),
        );

        // Confirmado deja de reservar: si siguiera reservando, la tienda
        // descontaria el stock dos veces al publicar disponibilidad.
        $this->assertNull($order->expires_at);
    }

    /** El total de la venta tiene que ser el del pedido, no recalculado aparte. */
    public function test_la_venta_creada_cobra_lo_que_decia_el_pedido(): void
    {
        [$business, $owner] = $this->storeBusiness();

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'track_stock' => false,
            'price' => 20000,
        ]);

        $order = Order::factory()->create([
            'business_id' => $business->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 40000,
            'shipping_fee' => 5000,
            'total' => 45000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 20000,
            'subtotal' => 40000,
        ]);

        app(OrderService::class)->transition($owner, $order, Order::STATUS_CONFIRMED, null, 'cash');

        $sale = Sale::withoutGlobalScopes()->find($order->fresh()->sale_id);

        $this->assertEqualsWithDelta(45000, (float) $sale->total, 0.01);
    }

    /** @return array{0: Business, 1: User} */
    private function storeBusiness(): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);

        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return [$business, $owner];
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }
}
