<?php

namespace App\Http\Requests\Api\V1\Storefront;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Que `product_id` exista no se valida aca sino en ProductReviewService: la
 * regla real no es "el producto existe" sino "el producto esta en ESTE
 * pedido", y eso necesita el pedido, que se resuelve por token en el
 * controlador.
 */
class StoreStorefrontReviewRequest extends FormRequest
{
    /** Ruta publica: la autorizacion es el token del pedido. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'min:'.ProductReview::MIN_RATING, 'max:'.ProductReview::MAX_RATING],
            // Se puede calificar sin escribir. Obligar a redactar algo baja
            // muchisimo la cantidad de calificaciones.
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Elige cuántas estrellas le das.',
            'rating.min' => 'La calificación va de 1 a 5 estrellas.',
            'rating.max' => 'La calificación va de 1 a 5 estrellas.',
            'comment.max' => 'El comentario no puede pasar de 1000 caracteres.',
        ];
    }
}
