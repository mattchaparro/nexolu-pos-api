<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Una foto ya procesada de un producto o de una de sus variantes. La sube y
 * la ordena el comerciante desde Catalogo; la consume el POS y, sobre todo,
 * la tienda online.
 *
 * Ver ProductImageService: el archivo original nunca se guarda tal cual, se
 * reescala y se convierte a WebP antes de tocar el disco.
 */
#[Fillable(['product_id', 'product_variant_id', 'business_id', 'disk', 'path', 'thumbnail_path', 'alt', 'sort_order'])]
class ProductImage extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * Tope de fotos por producto. No es una restriccion tecnica sino de
     * producto: una ficha con veinte fotos no ayuda a vender y multiplica el
     * costo de almacenamiento y de transferencia del catalogo publico.
     */
    public const MAX_PER_PRODUCT = 8;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Se resuelve contra el disco guardado en la fila, no contra el disco
     * configurado hoy: al mover el almacenamiento a Spaces, las fotos viejas
     * tienen que seguir apuntando a donde de verdad estan.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbnailUrl(): string
    {
        return Storage::disk($this->disk)->url($this->thumbnail_path);
    }
}
