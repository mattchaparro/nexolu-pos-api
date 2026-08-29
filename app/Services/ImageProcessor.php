<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Procesa y guarda una imagen subida: reescala, pasa a WebP, le quita los
 * metadatos y la deja en el disco configurado.
 *
 * Extraido de ProductImageService cuando la tienda online necesito lo mismo
 * para logo, banner y las imagenes del home. La regla del EXIF es la razon
 * de que esto viva en un solo sitio: las fotos de celular traen las
 * coordenadas GPS de donde se tomaron, y todo esto termina publicado en
 * internet - una segunda copia de este codigo es una oportunidad de olvidar
 * el `strip`.
 */
class ImageProcessor
{
    /**
     * Guarda la imagen y devuelve donde quedo.
     *
     * @param  ?int  $thumbnailDimension  null = sin miniatura (logo, banner: se
     *                                    usan a un solo tamaño).
     * @return array{disk: string, path: string, thumbnail_path: ?string}
     */
    public function store(
        UploadedFile $file,
        string $directory,
        int $maxDimension = 1600,
        int $quality = 82,
        ?int $thumbnailDimension = 400,
        int $thumbnailQuality = 75,
    ): array {
        $disk = (string) config('filesystems.product_images_disk');
        $name = (string) Str::ulid();
        $manager = new ImageManager(new Driver);

        // orient() ANTES de descartar el EXIF: la rotacion de la camara vive
        // justamente ahi, y sin esto las fotos verticales salen acostadas.
        $full = $manager->decodePath($file->getRealPath())
            ->orient()
            ->scaleDown($maxDimension, $maxDimension)
            ->encode(new WebpEncoder(quality: $quality, strip: true));

        $path = "{$directory}/{$name}.webp";
        Storage::disk($disk)->put($path, (string) $full, 'public');

        $thumbnailPath = null;
        if ($thumbnailDimension !== null) {
            // La miniatura se saca de la grande ya codificada, no del original:
            // los modificadores de Intervention mutan la imagen y devuelven
            // $this, asi que reusar el objeto decodificado escalaria dos veces
            // sobre el mismo lienzo. Ademas evita decodificar el original dos veces.
            $thumbnail = $manager->decodeBinary((string) $full)
                ->scaleDown($thumbnailDimension, $thumbnailDimension)
                ->encode(new WebpEncoder(quality: $thumbnailQuality, strip: true));

            $thumbnailPath = "{$directory}/{$name}_thumb.webp";
            Storage::disk($disk)->put($thumbnailPath, (string) $thumbnail, 'public');
        }

        return ['disk' => $disk, 'path' => $path, 'thumbnail_path' => $thumbnailPath];
    }

    /** @param  list<?string>  $paths */
    public function delete(?string $disk, array $paths): void
    {
        $existing = array_values(array_filter($paths));
        if ($disk && $existing !== []) {
            Storage::disk($disk)->delete($existing);
        }
    }
}
