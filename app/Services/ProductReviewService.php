<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de las reseñas de la tienda online.
 *
 * Todo el control de acceso vive aca y no en el controlador porque la
 * pregunta "¿esta persona puede reseñar este producto?" tiene UNA respuesta
 * en todo el sistema: solo si el producto esta en el pedido cuyo token trae.
 */
class ProductReviewService
{
    /**
     * Crea una reseña a partir del pedido que la habilita.
     *
     * @param  array{product_id: int, rating: int, comment?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function createFromOrder(Order $order, array $data): ProductReview
    {
        $productId = (int) $data['product_id'];

        // La atadura del sistema: se reseña lo que se compro, no lo que se
        // pide por parametro. Sin esto el token de UN pedido serviria para
        // reseñar el catalogo entero.
        $comprado = $order->items()->where('product_id', $productId)->exists();

        if (! $comprado) {
            throw ValidationException::withMessages([
                'product_id' => 'Solo puedes calificar productos de este pedido.',
            ]);
        }

        // Reseñar antes de recibir el pedido no dice nada del producto. Se
        // exige que el comerciante lo haya dado por entregado.
        if (! $this->isReviewable($order)) {
            throw ValidationException::withMessages([
                'product_id' => 'Vas a poder calificar cuando la tienda marque tu pedido como entregado.',
            ]);
        }

        try {
            return ProductReview::create([
                'business_id' => $order->business_id,
                'product_id' => $productId,
                'order_id' => $order->id,
                'rating' => (int) $data['rating'],
                'comment' => $data['comment'] ?? null,
                'author_name' => $order->customer_name,
            ]);
        } catch (QueryException $e) {
            // El indice unico (order_id, product_id) es el que manda. Se
            // traduce a un 422 con mensaje en vez de dejar salir un 500: dos
            // envios del mismo formulario no son un error del servidor.
            if ($this->isDuplicate($e)) {
                throw ValidationException::withMessages([
                    'product_id' => 'Ya calificaste este producto en este pedido.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Un pedido habilita reseñas cuando ya se entrego. Antes de eso el
     * comprador todavia no conoce el producto.
     */
    public function isReviewable(Order $order): bool
    {
        return $order->status === Order::STATUS_DELIVERED;
    }

    /**
     * Promedio y conteo por producto, en UNA consulta para toda una pagina de
     * catalogo (si no, seria una consulta por card).
     *
     * @param  list<int>  $productIds
     * @return array<int, array{average: float, count: int}>
     */
    public function summaryFor(Business $business, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductReview::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->approved()
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->select('product_id', DB::raw('AVG(rating) as average'), DB::raw('COUNT(*) as total'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => [
                'average' => round((float) $row->average, 1),
                'count' => (int) $row->total,
            ]])
            ->all();
    }

    /**
     * Aprobar u ocultar, desde el POS. Deja quien y cuando.
     *
     * Asignacion directa y no `update()`: `status`, `moderated_at` y
     * `moderated_by` NO estan en el Fillable del modelo a proposito, para que
     * ningun payload pueda colar un "ya vengo aprobada". Un `update()` con
     * esos campos los descartaba en silencio y la moderacion no hacia nada.
     */
    public function moderate(ProductReview $review, string $status, User $user): ProductReview
    {
        $review->status = $status;
        $review->moderated_at = now();
        $review->moderated_by = $user->id;
        $review->save();

        return $review;
    }

    /**
     * Productos de un pedido que todavia se pueden calificar, para que la
     * pagina del pedido muestre el formulario solo donde corresponde.
     *
     * @return list<int>
     */
    public function pendingProductIds(Order $order): array
    {
        if (! $this->isReviewable($order)) {
            return [];
        }

        $yaReseñados = ProductReview::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->pluck('product_id')
            ->all();

        return $order->items()
            ->pluck('product_id')
            ->filter(fn ($id) => $id !== null && ! in_array((int) $id, $yaReseñados, true))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
