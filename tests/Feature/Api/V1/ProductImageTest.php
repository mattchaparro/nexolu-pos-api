<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // online_store encendido: el modulo de fotos vive detras de ese flag
        // y no viene con ningun plan (ver BusinessFeaturePresets::OPT_IN_ONLY).
        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        $this->admin = User::factory()->create(['business_id' => $this->business->id]);
        $this->admin->assignRole('admin');
    }

    private function product(): Product
    {
        return Product::factory()->create(['business_id' => $this->business->id]);
    }

    /** Un JPEG real, para que el pipeline de Intervention se ejecute de verdad. */
    private function photo(int $width = 2400, int $height = 1800): UploadedFile
    {
        return UploadedFile::fake()->image('foto.jpg', $width, $height);
    }

    public function test_uploading_a_photo_stores_both_sizes_and_returns_them(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
            ->assertCreated();

        $image = ProductImage::findOrFail($response->json('id'));

        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertExists($image->thumbnail_path);
        $this->assertSame(0, $image->sort_order);
        $this->assertSame($this->business->id, $image->business_id);
    }

    public function test_the_stored_photo_is_webp_and_scaled_down(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(2400, 1800)])
            ->assertCreated();

        $image = ProductImage::where('product_id', $product->id)->firstOrFail();
        $manager = new ImageManager(new Driver);

        $stored = $manager->decodeBinary(Storage::disk('public')->get($image->path));
        $this->assertSame(1600, $stored->width());

        $thumbnail = $manager->decodeBinary(Storage::disk('public')->get($image->thumbnail_path));
        $this->assertSame(400, $thumbnail->width());
    }

    public function test_the_first_photo_becomes_the_denormalised_primary_image(): void
    {
        $product = $this->product();
        $this->assertNull($product->image);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
            ->assertCreated();

        $image = ProductImage::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($image->url(), $product->fresh()->image);
    }

    public function test_deleting_the_primary_photo_promotes_the_next_one(): void
    {
        $product = $this->product();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->admin, 'sanctum')
                ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
                ->assertCreated();
        }

        [$first, $second] = ProductImage::where('product_id', $product->id)->orderBy('sort_order')->get()->all();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}/images/{$first->id}")
            ->assertNoContent();

        Storage::disk('public')->assertMissing($first->path);
        Storage::disk('public')->assertMissing($first->thumbnail_path);
        $this->assertSame($second->url(), $product->fresh()->image);
    }

    public function test_deleting_the_last_photo_clears_the_primary_image(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
            ->assertCreated();

        $image = ProductImage::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}/images/{$image->id}")
            ->assertNoContent();

        $this->assertNull($product->fresh()->image);
    }

    public function test_reordering_changes_the_primary_image(): void
    {
        $product = $this->product();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->admin, 'sanctum')
                ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
                ->assertCreated();
        }

        $ids = ProductImage::where('product_id', $product->id)->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/images/order", ['image_ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('0.id', $reversed[0]);

        $this->assertSame(ProductImage::find($reversed[0])->url(), $product->fresh()->image);
    }

    public function test_reordering_rejects_an_incomplete_list(): void
    {
        $product = $this->product();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->admin, 'sanctum')
                ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
                ->assertCreated();
        }

        $ids = ProductImage::where('product_id', $product->id)->pluck('id')->all();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}/images/order", ['image_ids' => [$ids[0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_ids');
    }

    public function test_a_product_cannot_exceed_the_photo_limit(): void
    {
        $product = $this->product();

        foreach (range(1, ProductImage::MAX_PER_PRODUCT) as $ignored) {
            $this->actingAs($this->admin, 'sanctum')
                ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
                ->assertCreated();
        }

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", [
                'image' => UploadedFile::fake()->create('malicioso.php', 20, 'application/x-php'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_svg_is_rejected_because_it_can_carry_scripts(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", [
                'image' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    // -----------------------------------------------------------------
    // Aislamiento entre negocios
    // -----------------------------------------------------------------

    public function test_cannot_upload_a_photo_to_a_product_of_another_business(): void
    {
        $foreignProduct = Product::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$foreignProduct->id}/images", ['image' => $this->photo()])
            ->assertNotFound();
    }

    public function test_cannot_delete_a_photo_that_belongs_to_another_product(): void
    {
        $mine = $this->product();
        $other = $this->product();

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$other->id}/images", ['image' => $this->photo()])
            ->assertCreated();
        $foreignImage = ProductImage::where('product_id', $other->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/products/{$mine->id}/images/{$foreignImage->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $foreignImage->id]);
    }

    public function test_a_photo_can_be_reassigned_to_a_variant(): void
    {
        $product = $this->product();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
            ->assertCreated();
        $image = ProductImage::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/images/{$image->id}", [
                'product_variant_id' => $variant->id,
            ])
            ->assertOk()
            ->assertJsonPath('product_variant_id', $variant->id);

        // Y de vuelta al producto.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/images/{$image->id}", ['product_variant_id' => null])
            ->assertOk()
            ->assertJsonPath('product_variant_id', null);
    }

    public function test_a_photo_cannot_be_assigned_to_a_variant_of_another_product(): void
    {
        $product = $this->product();
        $otherProduct = $this->product();
        $foreignVariant = ProductVariant::factory()->create([
            'product_id' => $otherProduct->id,
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
            ->assertCreated();
        $image = ProductImage::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}/images/{$image->id}", [
                'product_variant_id' => $foreignVariant->id,
            ])
            ->assertNotFound();
    }

    public function test_a_business_without_the_online_store_module_cannot_use_photos_at_all(): void
    {
        // Las fotos existen para publicar el catalogo: sin tienda online el
        // modulo entero desaparece, igual que ingredients o variants.
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => false],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/images")
            ->assertForbidden();
    }

    public function test_creating_a_product_returns_its_variants_so_photos_can_be_attached(): void
    {
        // El formulario manda las variantes anidadas y sin id; sin esto no
        // podria saber a que variante subirle cada foto recien creada.
        $attribute = ProductAttribute::factory()->create(['business_id' => $this->business->id]);
        $value = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'business_id' => $this->business->id,
        ]);
        $category = ProductCategory::factory()->create(['business_id' => $this->business->id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Camiseta',
                'category_id' => $category->id,
                'price' => 50000,
                'stock' => 0,
                'variants' => [
                    ['sku' => 'CAM-S', 'price' => 50000, 'stock' => 3, 'attribute_value_ids' => [$value->id]],
                ],
            ])
            ->assertCreated();

        $this->assertCount(1, $response->json('variants'));
        $this->assertNotNull($response->json('variants.0.id'));
        $this->assertSame($value->id, $response->json('variants.0.attribute_values.0.product_attribute_value_id'));
    }

    public function test_an_employee_without_inventory_permission_cannot_upload(): void
    {
        $employee = User::factory()->create(['business_id' => $this->business->id]);
        $employee->assignRole('employee');
        $product = $this->product();

        $this->actingAs($employee, 'sanctum')
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo()])
            ->assertForbidden();
    }

    public function test_listing_photos_returns_them_in_order(): void
    {
        $product = $this->product();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->admin, 'sanctum')
                ->post("/api/v1/products/{$product->id}/images", ['image' => $this->photo(400, 400)])
                ->assertCreated();
        }

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/images")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.sort_order', 0)
            ->assertJsonPath('1.sort_order', 1);
    }
}
