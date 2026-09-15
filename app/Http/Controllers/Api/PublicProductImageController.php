<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Imagen publica y ESTABLE de un producto, para el catalogo de WhatsApp:
 * Meta cachea el image_link del items_batch, asi que no sirve una URL
 * firmada con vencimiento (el patron de los recibos). Sin auth a proposito
 * - una imagen de producto publicada en un catalogo publico no es un dato
 * privado, y el id no enumera nada mas que eso.
 */
class PublicProductImageController extends Controller
{
    public function show(Product $product): Response
    {
        $image = trim((string) $product->image);

        if ($image === '' || ! $product->is_active) {
            abort(404);
        }

        // El monolito legacy guarda URLs absolutas en algunos negocios:
        // se redirige tal cual (Meta sigue redirecciones al cachear).
        if (str_starts_with($image, 'https://') || str_starts_with($image, 'http://')) {
            return redirect()->away($image, 302);
        }

        $path = ltrim($image, '/');
        // Rutas relativas del estilo storage/xyz.jpg o xyz.jpg sobre el
        // disco publico.
        $path = str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path;

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')
            ->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
