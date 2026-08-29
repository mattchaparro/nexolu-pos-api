<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Biblioteca de imagenes de la tienda: las que usan los bloques del home.
 *
 * Existe porque un bloque repetible no puede usar ranuras fijas -- si el
 * comerciante pone tres galerias, no hay un `gallery_image_path` que
 * alcance. Los bloques guardan `image_id` y resuelven la URL desde aca.
 *
 * El logo y el banner siguen siendo ranuras en `business_store_settings`:
 * de esos hay uno y solo uno, y meterlos aca solo agregaria indireccion.
 */
#[Fillable(['business_id', 'disk', 'path', 'thumbnail_path', 'alt'])]
class BusinessStoreImage extends Model
{
    use BelongsToBusiness, HasFactory;

    public function url(): ?string
    {
        return $this->path ? Storage::disk($this->disk)->url($this->path) : null;
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? Storage::disk($this->disk)->url($this->thumbnail_path) : $this->url();
    }
}
