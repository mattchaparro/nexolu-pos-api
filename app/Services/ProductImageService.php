<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\ProductAvailability;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unico punto por el que entran y salen fotos del catalogo.
 *
 * El archivo que sube el comerciante NUNCA se guarda tal cual: viene del
 * celular, pesa varios megas y trae metadatos EXIF. Se reescala, se pasa a
 * WebP y se le quitan los metadatos antes de tocar el disco. Lo del EXIF no
 * es cosmetico: las fotos de celular incluyen las coordenadas GPS de donde
 * se tomaron, y el catalogo va a ser publico - subir el original publicaria
 * la ubicacion del negocio (o de la casa del dueño) sin que nadie lo pida.
 */
class ProductImageService
{
    public function __construct(private ImageProcessor $images) {}

    /**
     * Lado mayor de la foto grande. 1600px cubre una ficha de producto a
     * pantalla completa en retina sin que cada imagen pese como una pagina
     * entera - el catalogo publico se ve mayoritariamente en celular y con
     * datos moviles.
     */
    private const MAX_DIMENSION = 1600;

    private const THUMBNAIL_DIMENSION = 400;

    private const QUALITY = 82;

    private const THUMBNAIL_QUALITY = 75;

    public function store(Product $product, UploadedFile $file, ?ProductVariant $variant = null, ?string $alt = null): ProductImage
    {
        $this->assertRoomForAnotherImage($product);

        $stored = $this->images->store(
            $file,
            "products/{$product->business_id}/{$product->id}",
            maxDimension: self::MAX_DIMENSION,
            quality: self::QUALITY,
            thumbnailDimension: self::THUMBNAIL_DIMENSION,
            thumbnailQuality: self::THUMBNAIL_QUALITY,
        );

        $image = ProductImage::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'business_id' => $product->business_id,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'],
            'alt' => $alt,
            'sort_order' => $this->nextSortOrder($product),
        ]);

        $this->syncPrimaryImage($product);

        return $image;
    }

    public function delete(ProductImage $image): void
    {
        $product = $image->product;

        $this->images->delete($image->disk, [$image->path, $image->thumbnail_path]);
        $image->delete();

        if ($product) {
            $this->syncPrimaryImage($product);
        }
    }

    /**
     * Reordena las fotos de un producto segun la lista de ids recibida.
     *
     * @param  list<int>  $imageIds
     */
    public function reorder(Product $product, array $imageIds): void
    {
        $images = $product->images()->get()->keyBy('id');

        // La lista tiene que ser exactamente las fotos del producto: aceptar
        // una parcial dejaria huecos en el orden, y aceptar ids ajenos seria
        // una via para reordenar (y por tanto descubrir) fotos de otro negocio.
        if (count($imageIds) !== $images->count() || $images->keys()->diff($imageIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'image_ids' => 'La lista debe contener exactamente las fotos de este producto.',
            ]);
        }

        DB::transaction(function () use ($imageIds, $images) {
            foreach ($imageIds as $position => $id) {
                $images[$id]->update(['sort_order' => $position]);
            }
        });

        $this->syncPrimaryImage($product);
    }

    /**
     * Copia la URL de la primera foto a `products.image`.
     *
     * Esa columna existia desde el legacy sin que nada la llenara. En vez de
     * jubilarla se reaprovecha como cache desnormalizada de la foto
     * principal: ProductResource y el catalogo cacheado de Vender
     * (ProductAvailability::forBusiness) ya la leen, asi que mostrar la foto
     * en el POS no cuesta un join ni invalidar el cache de otra manera.
     */
    public function syncPrimaryImage(Product $product): void
    {
        $primary = $product->images()->orderBy('sort_order')->orderBy('id')->first();

        $product->forceFill(['image' => $primary?->url()])->save();

        ProductAvailability::clearCache($product->business_id);
    }

    private function nextSortOrder(Product $product): int
    {
        $highest = $product->images()->max('sort_order');

        return $highest === null ? 0 : (int) $highest + 1;
    }

    private function assertRoomForAnotherImage(Product $product): void
    {
        if ($product->images()->count() >= ProductImage::MAX_PER_PRODUCT) {
            throw ValidationException::withMessages([
                'image' => 'Este producto ya tiene el maximo de '.ProductImage::MAX_PER_PRODUCT.' fotos.',
            ]);
        }
    }
}
