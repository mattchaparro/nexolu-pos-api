<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappOrder;
use App\Services\OpenTabService;
use Illuminate\Validation\ValidationException;

/**
 * Convierte un WhatsappOrder (el carrito crudo de Meta) en una venta
 * abierta del POS. Compartido por el camino automatico
 * (App\Jobs\ProcessWhatsAppOrder) y el manual (la bandeja de pedidos:
 * un empleado acepta un pending_review) - misma logica, mismo resultado.
 *
 * Reglas heredadas del resto del POS: el precio SIEMPRE es del servidor
 * (el item_price del catalogo de Meta se ignora; SaleLineUnitPrice
 * resuelve), y el stock lo valida SaleService::applyItems bajo
 * lockForUpdate, igual que en caja.
 */
class WhatsAppOrderConverter
{
    public function __construct(private OpenTabService $openTabs) {}

    /**
     * @throws ValidationException si el carrito no es convertible (productos
     *                             inexistentes, sin stock, vacio) - el caller
     *                             decide si eso es pending_review (job) o un
     *                             422 al empleado (bandeja)
     */
    public function convert(WhatsappOrder $whatsappOrder, User $user): Sale
    {
        $business = $whatsappOrder->business;

        [$items, $missing] = $this->mapItems($business, $whatsappOrder->raw_order);

        if ($missing !== [] || $items === []) {
            throw ValidationException::withMessages([
                'items' => $missing === []
                    ? ['El carrito llego vacio.']
                    : ['Productos que ya no estan en el catalogo: '.implode(', ', $missing)],
            ]);
        }

        $client = $this->resolveClient($business, $whatsappOrder->phone, $whatsappOrder->customer_name);

        $sale = $this->openTabs->openTab($user, [
            'items' => $items,
            'client_id' => $client?->id,
            'customer_name' => $client?->name ?? $whatsappOrder->customer_name,
            'customer_phone' => $whatsappOrder->phone,
        ]);

        $whatsappOrder->update([
            'status' => WhatsappOrder::STATUS_CREATED,
            'sale_id' => $sale->id,
            'error' => null,
        ]);

        return $sale;
    }

    /**
     * Mapea los product_items de Meta a lineas del POS por sku (el
     * retailer_id es b{business}-{sku}, ver Product::whatsappRetailerId()).
     *
     * @param  array<string, mixed>  $order
     * @return array{0: array<int, array{product_id: int, quantity: float}>, 1: array<int, string>}
     */
    public function mapItems(Business $business, array $order): array
    {
        $items = [];
        $missing = [];

        foreach ($order['product_items'] ?? [] as $line) {
            $retailerId = (string) ($line['product_retailer_id'] ?? '');
            $sku = preg_replace('/^b\d+-/', '', $retailerId);
            $quantity = (float) ($line['quantity'] ?? 1);

            $product = Product::query()
                ->where('business_id', $business->id)
                ->where('sku', $sku)
                ->where('is_active', true)
                ->first();

            if ($product === null) {
                $missing[] = $retailerId;

                continue;
            }

            // Sin unit_price a proposito: applyItems resuelve el precio del
            // servidor.
            $items[] = ['product_id' => $product->id, 'quantity' => max(1, $quantity)];
        }

        return [$items, $missing];
    }

    /**
     * Cliente del negocio por telefono, o uno nuevo con el nombre del
     * perfil de WhatsApp. clients.phone guarda lo que teclearon en caja
     * (espacios, +57, o sin indicativo) y el webhook trae 573001234567: los
     * ultimos 10 digitos son el movil colombiano en ambos formatos. El LIKE
     * no aprovecha todo el indice, pero el conjunto por negocio es chico.
     */
    public function resolveClient(Business $business, string $phone, ?string $name): ?Client
    {
        $last10 = substr($phone, -10);

        $client = Client::query()
            ->where('business_id', $business->id)
            ->whereNotNull('phone')
            ->where('phone', 'like', "%{$last10}")
            ->first();

        if ($client !== null) {
            return $client;
        }

        return Client::query()->create([
            'business_id' => $business->id,
            'name' => $name ?: 'Cliente WhatsApp '.$last10,
            'phone' => $phone,
        ]);
    }
}
