<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreImage;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mudanza de imagenes entre discos (droplet -> Spaces, el dia que toque).
 *
 * Lo que estas pruebas defienden es que la mudanza se pueda hacer con la
 * tienda ARRIBA: que ninguna foto quede en el limbo, que las que ya se
 * movieron se sigan viendo, y que una que falle se quede donde estaba en vez
 * de perderse.
 */
class MoveImagesToDiskTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Con su propia URL base: dos discos falsos sin esto sirven desde la
        // misma direccion, y la prueba de la URL desnormalizada pasaria sola
        // sin comprobar nada. En produccion Spaces tiene otro dominio.
        Storage::fake('s3', ['url' => 'https://cdn.test']);

        $this->business = Business::factory()->create();
    }

    private function foto(array $attrs = []): ProductImage
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        Storage::disk('public')->put('products/foto.webp', 'contenido');
        Storage::disk('public')->put('products/foto_thumb.webp', 'mini');

        $image = ProductImage::withoutGlobalScopes()->create([
            'product_id' => $product->id,
            'business_id' => $this->business->id,
            'disk' => 'public',
            'path' => 'products/foto.webp',
            'thumbnail_path' => 'products/foto_thumb.webp',
            'sort_order' => 0,
            ...$attrs,
        ]);

        $product->forceFill(['image' => $image->url()])->save();

        return $image;
    }

    public function test_mueve_los_archivos_y_apunta_la_fila_al_disco_nuevo(): void
    {
        $image = $this->foto();

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])
            ->assertSuccessful();

        Storage::disk('s3')->assertExists('products/foto.webp');
        Storage::disk('s3')->assertExists('products/foto_thumb.webp');
        $this->assertSame('s3', $image->fresh()->disk);
    }

    /**
     * El original se conserva salvo que se pida borrarlo: es la unica parte
     * sin vuelta atras, y se hace despues de mirar la tienda con los ojos.
     */
    public function test_por_defecto_no_borra_el_original(): void
    {
        $this->foto();

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])
            ->assertSuccessful();

        Storage::disk('public')->assertExists('products/foto.webp');

        $this->artisan('images:move-disk', ['--from' => 's3', '--to' => 'public', '--delete-source' => true])
            ->assertSuccessful();

        Storage::disk('s3')->assertMissing('products/foto.webp');
    }

    /**
     * El caso que de verdad importa para migrar sin apagar la tienda:
     * `products.image` guarda la URL ABSOLUTA, asi que si no se re-sincroniza,
     * el catalogo del POS sigue pidiendo la direccion del disco viejo.
     */
    public function test_actualiza_la_url_desnormalizada_de_la_foto_principal(): void
    {
        $image = $this->foto();
        $product = $image->product;
        $urlVieja = $product->image;

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])
            ->assertSuccessful();

        $urlNueva = $product->fresh()->image;

        $this->assertNotSame($urlVieja, $urlNueva, 'La URL guardada tiene que cambiar de disco.');
        $this->assertSame($image->fresh()->url(), $urlNueva);
    }

    /** Un ensayo no toca nada. */
    public function test_el_ensayo_no_mueve_nada(): void
    {
        $image = $this->foto();

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3', '--dry-run' => true])
            ->assertSuccessful();

        Storage::disk('s3')->assertMissing('products/foto.webp');
        $this->assertSame('public', $image->fresh()->disk);
    }

    /**
     * Si la copia no llega, la fila NO se mueve: la foto se sigue viendo
     * desde donde estaba. Lo contrario seria perderla en silencio.
     */
    public function test_una_copia_que_falla_deja_la_foto_donde_estaba(): void
    {
        $image = $this->foto();
        Storage::disk('public')->delete('products/foto.webp');

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])
            ->assertFailed();

        $this->assertSame('public', $image->fresh()->disk);
    }

    /** Correrlo dos veces no duplica ni rompe lo ya movido. */
    public function test_se_puede_volver_a_correr(): void
    {
        $image = $this->foto();

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])->assertSuccessful();
        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])->assertSuccessful();

        $this->assertSame('s3', $image->fresh()->disk);
        Storage::disk('s3')->assertExists('products/foto.webp');
    }

    /** El logo y el banner de la tienda viajan igual que las fotos. */
    public function test_tambien_mueve_el_logo_y_el_banner_de_la_tienda(): void
    {
        Storage::disk('public')->put('store/logo.webp', 'logo');

        $settings = BusinessStoreSettings::factory()->create([
            'business_id' => $this->business->id,
            'disk' => 'public',
            'logo_path' => 'store/logo.webp',
            'banner_path' => null,
        ]);

        Storage::disk('public')->put('store/lib.webp', 'lib');
        $libreria = BusinessStoreImage::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'disk' => 'public',
            'path' => 'store/lib.webp',
            'thumbnail_path' => null,
        ]);

        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 's3'])
            ->assertSuccessful();

        Storage::disk('s3')->assertExists('store/logo.webp');
        Storage::disk('s3')->assertExists('store/lib.webp');
        $this->assertSame('s3', $settings->fresh()->disk);
        $this->assertSame('s3', $libreria->fresh()->disk);
    }

    public function test_rechaza_mover_al_mismo_disco(): void
    {
        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 'public'])->assertFailed();
    }

    public function test_rechaza_un_disco_que_no_existe(): void
    {
        $this->artisan('images:move-disk', ['--from' => 'public', '--to' => 'inventado'])->assertFailed();
    }
}
