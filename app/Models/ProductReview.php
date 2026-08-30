<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calificacion y comentario de un comprador sobre un producto.
 *
 * Solo existe atada a un pedido (ver la migracion): quien la escribe demostro
 * haber comprado ese producto al abrir el enlace de su pedido.
 *
 * `status`, `moderated_at` y `moderated_by` quedan FUERA del Fillable a
 * proposito: son la decision del comerciante, no un dato del formulario. Se
 * escriben por asignacion directa en ProductReviewService::moderate, que es
 * el unico camino. No agregarlos aca -- seria dejar que un payload publique
 * su propia resena.
 */
#[Fillable(['business_id', 'product_id', 'order_id', 'rating', 'comment', 'author_name'])]
class ProductReview extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_HIDDEN = 'hidden';

    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    /** Lo unico que puede ver un comprador anonimo. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
