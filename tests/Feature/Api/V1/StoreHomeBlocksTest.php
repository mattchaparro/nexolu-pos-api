<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreImage;
use App\Models\BusinessStoreSettings;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\StoreHomeBlocks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El home como lista de bloques.
 *
 * Lo que se protege aca es la promesa del producto: el comerciante ordena y
 * repite bloques, pero nada de lo que escribe se convierte en HTML ni puede
 * escapar del tema.
 */
class StoreHomeBlocksTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
    }

    private function save(array $blocks): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['home_blocks' => $blocks]);
    }

    public function test_the_merchant_can_order_and_repeat_blocks(): void
    {
        $this->save([
            ['id' => 'b1', 'type' => StoreHomeBlocks::TYPE_HERO, 'title' => 'Café de finca'],
            ['id' => 'b2', 'type' => StoreHomeBlocks::TYPE_TEXT_IMAGE, 'title' => 'Quiénes somos', 'image_side' => 'left'],
            ['id' => 'b3', 'type' => StoreHomeBlocks::TYPE_TEXT_IMAGE, 'title' => 'Cómo tostamos', 'image_side' => 'right'],
            ['id' => 'b4', 'type' => StoreHomeBlocks::TYPE_FAQ, 'items' => [['question' => '¿Envían?', 'answer' => 'Sí.']]],
        ])->assertOk();

        $blocks = BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)->value('home_blocks');

        $this->assertSame(['b1', 'b2', 'b3', 'b4'], array_column($blocks, 'id'), 'El orden es el que mando');
        $this->assertSame('left', $blocks[1]['image_side']);
    }

    /** Dos portadas seguidas no es personalizacion, es una pagina rota. */
    public function test_only_one_hero_is_allowed(): void
    {
        $this->save([
            ['id' => 'b1', 'type' => StoreHomeBlocks::TYPE_HERO, 'title' => 'Uno'],
            ['id' => 'b2', 'type' => StoreHomeBlocks::TYPE_HERO, 'title' => 'Dos'],
        ])->assertUnprocessable()->assertJsonValidationErrors('home_blocks');
    }

    public function test_an_unknown_block_type_is_rejected(): void
    {
        $this->save([['id' => 'b1', 'type' => 'html_libre', 'content' => '<script>alert(1)</script>']])
            ->assertUnprocessable();
    }

    /**
     * La barrera contra que se cuelen campos que el tipo no declara y
     * queden guardados para siempre en el JSON.
     */
    public function test_fields_the_type_does_not_declare_are_dropped(): void
    {
        $this->save([[
            'id' => 'b1',
            'type' => StoreHomeBlocks::TYPE_CTA,
            'title' => 'Compra ya',
            'raw_html' => '<script>alert(1)</script>',
            'custom_css' => 'body{display:none}',
        ]])->assertOk();

        $block = BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)->value('home_blocks')[0];

        $this->assertArrayNotHasKey('raw_html', $block);
        $this->assertArrayNotHasKey('custom_css', $block);
        $this->assertSame('Compra ya', $block['title']);
    }

    public function test_block_fields_are_validated_by_type(): void
    {
        $this->save([[
            'id' => 'b1',
            'type' => StoreHomeBlocks::TYPE_FAQ,
            'items' => [['question' => '¿Y esto?']], // falta answer
        ]])->assertUnprocessable()->assertJsonValidationErrors('home_blocks.0.items.0.answer');
    }

    public function test_a_map_must_be_a_link_not_an_embed(): void
    {
        $this->save([[
            'id' => 'b1', 'type' => StoreHomeBlocks::TYPE_HOURS,
            'map_url' => '<iframe src="https://maps.google.com"></iframe>',
        ]])->assertUnprocessable();
    }

    // -----------------------------------------------------------------
    // Lo que ve el comprador
    // -----------------------------------------------------------------

    public function test_the_storefront_gets_blocks_with_images_already_resolved(): void
    {
        $image = BusinessStoreImage::create([
            'business_id' => $this->business->id,
            'disk' => 'public',
            'path' => 'stores/1/home/foto.webp',
            'thumbnail_path' => 'stores/1/home/foto_thumb.webp',
            'alt' => 'Nuestro local',
        ]);

        $this->save([
            ['id' => 'b1', 'type' => StoreHomeBlocks::TYPE_TEXT_IMAGE, 'title' => 'Quiénes somos', 'image_id' => $image->id],
            ['id' => 'b2', 'type' => StoreHomeBlocks::TYPE_GALLERY, 'image_ids' => [$image->id]],
        ])->assertOk();

        $response = $this->getJson("/api/v1/storefront/{$this->business->slug}")->assertOk();
        $blocks = $response->json('home_blocks');

        // La tienda no puede consultar la biblioteca de un comercio: recibe
        // las URLs ya resueltas.
        $this->assertStringContainsString('foto.webp', $blocks[0]['image_url']);
        $this->assertArrayNotHasKey('image_id', $blocks[0]);
        $this->assertStringContainsString('foto.webp', $blocks[1]['images'][0]['url']);
    }

    public function test_a_disabled_block_never_reaches_the_buyer(): void
    {
        $this->save([
            ['id' => 'b1', 'type' => StoreHomeBlocks::TYPE_CTA, 'title' => 'Visible'],
            ['id' => 'b2', 'type' => StoreHomeBlocks::TYPE_CTA, 'title' => 'Oculto', 'enabled' => false],
        ])->assertOk();

        $blocks = $this->getJson("/api/v1/storefront/{$this->business->slug}")->json('home_blocks');

        $this->assertCount(1, $blocks);
        $this->assertSame('Visible', $blocks[0]['title']);
    }

    /**
     * Borrar una foto de la biblioteca no puede romper la pagina: el bloque
     * simplemente deja de mostrarla.
     */
    public function test_a_deleted_image_leaves_the_block_without_a_hole(): void
    {
        $image = BusinessStoreImage::create([
            'business_id' => $this->business->id, 'disk' => 'public', 'path' => 'stores/1/home/x.webp',
        ]);
        $this->save([['id' => 'b1', 'type' => StoreHomeBlocks::TYPE_GALLERY, 'image_ids' => [$image->id, 999999]]])->assertOk();

        $blocks = $this->getJson("/api/v1/storefront/{$this->business->slug}")->json('home_blocks');

        $this->assertCount(1, $blocks[0]['images'], 'La imagen que no existe se descarta');
    }
}
