<?php

namespace App\Console\Commands;

use App\Models\BusinessStoreImage;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductAvailability;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mueve las imagenes ya subidas de un disco a otro (tipico: `public` del
 * droplet -> `s3` de DigitalOcean Spaces).
 *
 * Cada fila recuerda EN QUE DISCO se guardo (`product_images.disk`,
 * `business_store_images.disk`, `business_store_settings.disk`), asi que
 * cambiar `PRODUCT_IMAGES_DISK` solo manda las fotos NUEVAS al disco nuevo y
 * las viejas se siguen viendo. Este comando es la otra mitad: se lleva las
 * viejas, cuando se quiera y sin apuro.
 *
 * Se puede correr con la tienda arriba y a mitad de camino: copia, verifica
 * que llego, y RECIEN ahi mueve la fila al disco nuevo. Una foto siempre
 * esta entera en algun disco -- nunca en ninguno.
 *
 * Se puede volver a correr cuantas veces haga falta: lo ya movido no vuelve
 * a aparecer porque el filtro es por el disco de origen.
 *
 * El original NO se borra por defecto. Borrarlo es la operacion que no tiene
 * vuelta atras, y conviene hacerla despues de comprobar con los ojos que la
 * tienda se ve bien desde el disco nuevo.
 */
class MoveImagesToDisk extends Command
{
    protected $signature = 'images:move-disk
        {--from=public : Disco de origen}
        {--to= : Disco de destino (ej. s3). Por defecto, el configurado en filesystems.product_images_disk}
        {--delete-source : Borra el archivo del disco viejo despues de verificar que llego al nuevo}
        {--dry-run : Solo dice que haria}';

    protected $description = 'Mueve las imagenes ya subidas de un disco a otro (p.ej. del droplet a Spaces)';

    private int $movidas = 0;

    private int $fallidas = 0;

    public function handle(): int
    {
        $origen = (string) $this->option('from');
        $destino = (string) ($this->option('to') ?: config('filesystems.product_images_disk'));
        $ensayo = (bool) $this->option('dry-run');

        if ($origen === $destino) {
            $this->error("El origen y el destino son el mismo disco ('{$origen}').");

            return self::FAILURE;
        }

        foreach ([$origen, $destino] as $disco) {
            if (! is_array(config("filesystems.disks.{$disco}"))) {
                $this->error("El disco '{$disco}' no existe en config/filesystems.php.");

                return self::FAILURE;
            }
        }

        $this->info(($ensayo ? '[ENSAYO] ' : '')."Moviendo imagenes de '{$origen}' a '{$destino}'.");

        // Fotos de producto: dos archivos por fila (grande y miniatura).
        $this->migrar(
            ProductImage::withoutGlobalScopes()->where('disk', $origen),
            fn (ProductImage $fila) => [$fila->path, $fila->thumbnail_path],
            $origen, $destino, $ensayo,
        );

        // Biblioteca de imagenes del home de la tienda.
        $this->migrar(
            BusinessStoreImage::withoutGlobalScopes()->where('disk', $origen),
            fn (BusinessStoreImage $fila) => [$fila->path, $fila->thumbnail_path],
            $origen, $destino, $ensayo,
        );

        // Logo y banner: no son una tabla de imagenes, son dos columnas de la
        // configuracion de la tienda, con un solo `disk` para las dos.
        $this->migrar(
            BusinessStoreSettings::withoutGlobalScopes()->where('disk', $origen),
            fn (BusinessStoreSettings $fila) => [$fila->logo_path, $fila->banner_path],
            $origen, $destino, $ensayo,
        );

        if (! $ensayo && $this->movidas > 0) {
            $this->resincronizarUrlsDenormalizadas();
        }

        $this->newLine();
        $this->info("Listo: {$this->movidas} movidas, {$this->fallidas} con problemas.");

        if ($this->fallidas > 0) {
            $this->warn('Las que fallaron se quedaron en su disco original y se siguen viendo. Volver a correr el comando las reintenta.');
        }

        return $this->fallidas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Builder<covariant Model>  $consulta
     * @param  callable(Model): list<?string>  $rutas
     */
    private function migrar($consulta, callable $rutas, string $origen, string $destino, bool $ensayo): void
    {
        $consulta->chunkById(100, function ($filas) use ($rutas, $origen, $destino, $ensayo) {
            foreach ($filas as $fila) {
                $archivos = array_values(array_filter($rutas($fila)));

                if ($archivos === []) {
                    // Fila con `disk` puesto pero sin ningun archivo (una
                    // tienda que nunca subio logo ni banner). Se lleva igual
                    // al disco nuevo para que no quede apuntando a uno que
                    // quiza se apague.
                    if (! $ensayo) {
                        $fila->forceFill(['disk' => $destino])->save();
                    }

                    continue;
                }

                if ($ensayo) {
                    $this->line('  '.class_basename($fila)." #{$fila->id}: ".implode(', ', $archivos));
                    $this->movidas++;

                    continue;
                }

                if ($this->copiar($archivos, $origen, $destino, $fila)) {
                    $fila->forceFill(['disk' => $destino])->save();
                    $this->movidas++;

                    if ($this->option('delete-source')) {
                        Storage::disk($origen)->delete($archivos);
                    }
                }
            }
        });
    }

    /**
     * Copia y COMPRUEBA que llego. Sin la comprobacion, un disco mal
     * configurado (`throw => false` devuelve false en silencio) dejaria la
     * fila apuntando a un destino vacio: la foto desapareceria de la tienda
     * y el comando diria que todo salio bien.
     *
     * @param  list<string>  $archivos
     */
    private function copiar(array $archivos, string $origen, string $destino, Model $fila): bool
    {
        try {
            foreach ($archivos as $archivo) {
                if (Storage::disk($destino)->fileExists($archivo)) {
                    continue; // Ya estaba: una corrida anterior que se corto a medias.
                }

                $contenido = Storage::disk($origen)->get($archivo);
                if ($contenido === null) {
                    throw new \RuntimeException("El archivo no existe en '{$origen}'.");
                }

                Storage::disk($destino)->put($archivo, $contenido, 'public');

                if (! Storage::disk($destino)->fileExists($archivo)) {
                    throw new \RuntimeException("Se copio pero no aparece en '{$destino}'.");
                }
            }

            return true;
        } catch (Throwable $e) {
            $this->fallidas++;
            $this->warn('  '.class_basename($fila)." #{$fila->id}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * `products.image` guarda la URL ABSOLUTA de la foto principal, no una
     * ruta (ver ProductImageService::syncPrimaryImage). Cambiar el disco de
     * una foto cambia su URL, asi que sin esto el catalogo del POS y la
     * tienda seguirian pidiendo la direccion vieja -- que despues de
     * --delete-source ya no responde.
     *
     * Es el unico sitio donde una URL quedo desnormalizada; el resto se
     * arman al vuelo desde `disk` + `path`.
     */
    private function resincronizarUrlsDenormalizadas(): void
    {
        $this->info('Actualizando la URL de la foto principal de cada producto...');

        $negocios = [];

        Product::withoutGlobalScopes()
            ->whereNotNull('image')
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->chunkById(200, function ($productos) use (&$negocios) {
                foreach ($productos as $producto) {
                    $url = $producto->images->first()?->url();

                    if ($url !== $producto->image) {
                        $producto->forceFill(['image' => $url])->save();
                        $negocios[$producto->business_id] = true;
                    }
                }
            });

        // El catalogo de Vender lee `products.image` desde un cache por
        // negocio: sin esto la foto vieja sigue saliendo hasta que venza.
        foreach (array_keys($negocios) as $businessId) {
            ProductAvailability::clearCache($businessId);
        }
    }
}
