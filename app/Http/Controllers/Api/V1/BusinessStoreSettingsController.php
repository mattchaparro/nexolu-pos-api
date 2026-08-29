<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBusinessStoreSettingsRequest;
use App\Http\Resources\Api\V1\BusinessStoreSettingsResource;
use App\Models\BusinessStoreImage;
use App\Models\BusinessStoreSettings;
use App\Services\ImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuracion de la tienda online, del lado del COMERCIANTE (el catalogo
 * publico que ve el comprador vive en Api\V1\Storefront).
 *
 * `show` crea la fila si no existe en vez de devolver 404: para el
 * comerciante la tienda "existe" desde que SuperAdmin le habilita el modulo,
 * solo que apagada. Devolver 404 obligaria al frontend a distinguir entre
 * "no configurada todavia" y "no tienes el modulo", que ya resuelve el
 * middleware `feature:online_store`.
 */
class BusinessStoreSettingsController extends Controller
{
    /**
     * Imagenes de la tienda y su tamaño maximo. Cada una se usa a un solo
     * tamaño (no hay galeria que miniaturizar), y el ancho refleja como se
     * pinta: el banner ocupa el ancho completo, el logo entra en 40px.
     *
     * @var array<string, array{field: string, max: int}>
     */
    private const IMAGE_SLOTS = [
        'logo' => ['field' => 'logo_path', 'max' => 512],
        'banner' => ['field' => 'banner_path', 'max' => 2000],
        'hero' => ['field' => 'hero_image_path', 'max' => 1600],
        'story' => ['field' => 'story_image_path', 'max' => 1400],
    ];

    public function __construct(private ImageProcessor $images) {}

    /**
     * Siempre 200, nunca 201: JsonResource devuelve 201 cuando el modelo
     * subyacente acaba de crearse, y aca `firstOrCreate` lo crea la primera
     * vez - un GET respondiendo "Created" confunde a cualquier cliente.
     */
    public function show(Request $request): JsonResponse
    {
        return (new BusinessStoreSettingsResource($this->settingsFor($request)))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdateBusinessStoreSettingsRequest $request): BusinessStoreSettingsResource
    {
        $settings = $this->settingsFor($request);
        $settings->update($request->validated());

        return new BusinessStoreSettingsResource($settings->fresh());
    }

    /**
     * Sube (o reemplaza) una de las imagenes de la tienda. Reemplazar borra
     * la anterior: nadie va a limpiar el disco a mano, y son archivos que se
     * pisan cada vez que el comerciante prueba un logo nuevo.
     */
    /**
     * Biblioteca de imagenes del home: las que usan los bloques.
     *
     * Aparte de las ranuras fijas (logo/banner) porque un bloque repetible
     * no puede tener ranura: si hay tres galerias, no hay un campo que
     * alcance.
     */
    public function images(Request $request): JsonResponse
    {
        $images = BusinessStoreImage::latest('id')->get();

        return response()->json([
            'images' => $images->map(fn (BusinessStoreImage $image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'thumbnail_url' => $image->thumbnailUrl(),
                'alt' => $image->alt,
            ])->values(),
        ]);
    }

    public function storeImage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'max:8192'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        $business = $request->user()->business;
        $stored = $this->images->store($data['image'], "stores/{$business->id}/home", maxDimension: 1600);

        $image = BusinessStoreImage::create([
            'business_id' => $business->id,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'] ?? null,
            'alt' => $data['alt'] ?? null,
        ]);

        return response()->json([
            'id' => $image->id,
            'url' => $image->url(),
            'thumbnail_url' => $image->thumbnailUrl(),
            'alt' => $image->alt,
        ], 201);
    }

    public function destroyImage(BusinessStoreImage $image): JsonResponse
    {
        // Los archivos se borran; los bloques que la referenciaban quedan
        // apuntando a un id que ya no existe y el storefront simplemente no
        // pinta imagen. Es preferible a bloquear el borrado: el comerciante
        // no deberia tener que cazar donde uso una foto para poder tirarla.
        $this->images->delete($image->disk, array_filter([$image->path, $image->thumbnail_path]));
        $image->delete();

        return response()->json(['deleted' => true]);
    }

    public function uploadImage(Request $request, string $slot): JsonResponse
    {
        abort_unless(array_key_exists($slot, self::IMAGE_SLOTS), 404);

        $request->validate([
            // `image` obliga a que sea decodificable de verdad; SVG queda
            // fuera porque admite scripts y esto se sirve en un dominio publico.
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ]);

        $settings = $this->settingsFor($request);
        $config = self::IMAGE_SLOTS[$slot];
        $field = $config['field'];

        $stored = $this->images->store(
            $request->file('image'),
            "stores/{$settings->business_id}",
            maxDimension: $config['max'],
            thumbnailDimension: null,
        );

        $this->images->delete($settings->disk, [$settings->{$field}]);

        $settings->update([
            'disk' => $stored['disk'],
            $field => $stored['path'],
        ]);

        return (new BusinessStoreSettingsResource($settings->fresh()))->response()->setStatusCode(200);
    }

    public function deleteImage(Request $request, string $slot): JsonResponse
    {
        abort_unless(array_key_exists($slot, self::IMAGE_SLOTS), 404);

        $settings = $this->settingsFor($request);
        $field = self::IMAGE_SLOTS[$slot]['field'];

        $this->images->delete($settings->disk, [$settings->{$field}]);
        $settings->update([$field => null]);

        return (new BusinessStoreSettingsResource($settings->fresh()))->response()->setStatusCode(200);
    }

    private function settingsFor(Request $request): BusinessStoreSettings
    {
        $businessId = $request->user()->business_id;

        return BusinessStoreSettings::firstOrCreate(
            ['business_id' => $businessId],
            ['is_active' => false],
        );
    }
}
