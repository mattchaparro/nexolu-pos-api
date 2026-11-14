<?php

namespace App\Services;

use App\Mail\NewOnlineOrderMail;
use App\Models\Business;
use App\Models\Client;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StoreCart;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use App\Support\ProductAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos de la tienda online: alta desde el checkout publico y avance de
 * estados desde el POS.
 *
 * Dos reglas gobiernan todo lo de aca:
 *
 * 1. NADA de lo que manda el comprador se cree. El navegador envia ids y
 *    cantidades; los precios, los nombres y la disponibilidad se releen de
 *    la base. Confiar en un precio que viaja por el cliente es regalar el
 *    catalogo.
 *
 * 2. La VENTA nace al confirmar, no al pedir. Un pedido pendiente solo
 *    retiene stock de forma blanda (`expires_at`); el `Sale` y sus
 *    `StockMovement` se crean cuando el comerciante confirma, que es cuando
 *    el compromiso existe de verdad.
 */
class OrderService
{
    /** Cuanto retiene stock un pedido sin confirmar. */
    public const RESERVATION_MINUTES = 60 * 24;

    public function __construct(
        private SaleService $sales,
    ) {}

    /**
     * Unidades ya comprometidas por pedidos pendientes vigentes, por
     * variante (o por producto cuando no tiene variantes).
     *
     * Es la reserva blanda: lo que la tienda publica como disponible es el
     * stock menos esto. No elimina la sobreventa al 100% -dos compradores
     * pueden pedir a la vez- pero cierra la ventana practica sin inventar un
     * sistema de holds con su propia tabla y su propia caducidad.
     *
     * @param  list<int>  $productIds
     * @return array{products: array<int, int>, variants: array<int, int>}
     */
    public function reservedUnits(int $businessId, array $productIds = []): array
    {
        $rows = OrderItem::query()
            ->withoutGlobalScopes()
            ->where('order_items.business_id', $businessId)
            ->when($productIds !== [], fn (Builder $q) => $q->whereIn('product_id', $productIds))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('orders')
                ->whereColumn('orders.id', 'order_items.order_id')
                ->whereIn('orders.status', Order::RESERVING_STATUSES)
                ->where(fn ($sub) => $sub->whereNull('orders.expires_at')->orWhere('orders.expires_at', '>', now()))
            )
            ->selectRaw('product_id, product_variant_id, SUM(quantity) as units')
            ->groupBy('product_id', 'product_variant_id')
            ->get();

        $products = [];
        $variants = [];
        foreach ($rows as $row) {
            if ($row->product_variant_id) {
                $variants[(int) $row->product_variant_id] = (int) $row->units;
            } else {
                $products[(int) $row->product_id] = (int) $row->units;
            }
        }

        return ['products' => $products, 'variants' => $variants];
    }

    /**
     * Resuelve el cupon que el comprador escribio.
     *
     * Un cupon invalido NO tumba la compra: se ignora y el pedido se crea
     * sin descuento. El checkout ya lo valida antes de enviar (ver
     * `POST /storefront/{slug}/coupons/validate`), asi que llegar aca con
     * uno malo significa que vencio o se agoto entre que lo escribio y
     * apreto comprar -- y perder la venta por eso seria peor que cobrarle el
     * precio de lista.
     *
     * @return array{0: ?Discount, 1: float}
     */
    private function resolveCoupon(Business $business, ?string $code, float $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return [null, 0.0];
        }

        $coupon = Discount::findCoupon($business->id, $code);
        if ($coupon === null || ! $coupon->isCoupon() || $coupon->rejectionReason($subtotal) !== null) {
            return [null, 0.0];
        }

        return [$coupon, $coupon->computeAmount($subtotal)];
    }

    /**
     * Crea un pedido desde el checkout publico.
     *
     * @param  array{items: list<array{product_id: int, product_variant_id?: ?int, quantity: int}>, customer_name: string, customer_phone: string, customer_email?: ?string, is_pickup?: bool, shipping_address?: ?string, shipping_city?: ?string, shipping_notes?: ?string, coupon_code?: ?string, cart_token?: ?string}  $data
     */
    public function createFromStorefront(Business $business, array $data): Order
    {
        return DB::transaction(function () use ($business, $data) {
            $isPickup = (bool) ($data['is_pickup'] ?? false);
            $settings = $business->storeSettings()->withoutGlobalScope('business')->first();

            // Modo menú: la tienda solo muestra la carta. Esconder el carrito en
            // la pantalla no basta, cualquiera puede llamar al endpoint.
            if ($settings?->menu_mode) {
                throw ValidationException::withMessages([
                    'items' => 'Este negocio muestra su menú pero todavía no recibe pedidos por aquí.',
                ]);
            }

            $lines = $this->resolveLines($business, $data['items']);
            $subtotal = array_sum(array_column($lines, 'subtotal'));

            $minimum = (float) ($settings?->min_order_amount ?? 0);
            if ($minimum > 0 && $subtotal < $minimum) {
                throw ValidationException::withMessages([
                    'items' => 'El pedido mínimo de esta tienda es de $'.number_format($minimum, 0, ',', '.').'.',
                ]);
            }

            // Envio, propina e impoconsumo salen de la configuracion del
            // negocio (la misma del POS), nunca del cliente: son importes que
            // el comprador podria poner en cero.
            $shipping = $isPickup ? 0.0 : $business->onlineOrderRates()['delivery_fee'];

            // El cupon se resuelve y se calcula ACA, contra la base. Del
            // comprador solo se acepta el codigo: aceptar el monto seria
            // dejar que se ponga el descuento que quiera.
            [$coupon, $discountAmount] = $this->resolveCoupon($business, $data['coupon_code'] ?? null, $subtotal);

            // Igual que en el POS: los cargos van sobre la base ya con el
            // descuento. La propina es sugerida y el comprador la puede quitar.
            [$serviceCharge, $ipoconsumo] = $this->sales->resolveCharges($business, $subtotal - $discountAmount, [
                'apply_service_charge' => (bool) ($data['include_service_charge'] ?? true),
            ]);

            $order = Order::create([
                'business_id' => $business->id,
                'number' => $this->nextNumber($business->id),
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'shipping_fee' => $shipping,
                'service_charge' => $serviceCharge,
                'ipoconsumo' => $ipoconsumo,
                'discount_id' => $coupon?->id,
                // El codigo se congela: el cupon puede desactivarse o
                // borrarse y el pedido tiene que seguir explicando su total.
                'coupon_code' => $coupon?->code,
                'discount_amount' => $discountAmount,
                'total' => round($subtotal - $discountAmount + $serviceCharge + $ipoconsumo + $shipping, 2),
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => trim($data['customer_phone']),
                'customer_email' => $data['customer_email'] ?? null,
                'is_pickup' => $isPickup,
                'shipping_address' => $isPickup ? null : ($data['shipping_address'] ?? null),
                'shipping_city' => $isPickup ? null : ($data['shipping_city'] ?? null),
                'shipping_notes' => $data['shipping_notes'] ?? null,
                'public_token' => Str::random(40),
                'expires_at' => now()->addMinutes(self::RESERVATION_MINUTES),
                'client_id' => $this->linkClient($business, $data)?->id,
            ]);

            foreach ($lines as $line) {
                OrderItem::create(['order_id' => $order->id, 'business_id' => $business->id, ...$line]);
            }

            $this->recordStatus($order, null, Order::STATUS_PENDING, null, 'Pedido recibido desde la tienda');

            // El carrito deja de estar abandonado: compro. Se enlaza en vez
            // de borrarse para poder medir cuantos se recuperaron.
            if (! empty($data['cart_token'])) {
                StoreCart::withoutGlobalScopes()
                    ->where('business_id', $business->id)
                    ->where('token', $data['cart_token'])
                    ->update(['order_id' => $order->id]);
            }

            // El uso se cuenta al CREAR el pedido, no al pagarlo. Contarlo
            // al pagar dejaria un cupon de un solo uso disponible para todo
            // el que estuviera pagando a la vez.
            if ($coupon !== null) {
                Discount::withoutGlobalScopes()->whereKey($coupon->id)->increment('used_count');
            }

            return $order->load('items');
        });
    }

    /**
     * Con receta, `products.stock` nunca se mueve (el descuento va a los
     * insumos): lo que se puede pedir sale del insumo mas escaso, igual que
     * en Vender. Sin receta, la columna de siempre.
     */
    private function productStock(Business $business, Product $product): float
    {
        if ($product->track_stock && $business->hasFeature('ingredients') && $product->ingredients()->exists()) {
            return ProductAvailability::effectiveStock($product, true);
        }

        return (float) $product->stock;
    }

    /**
     * Relee cada linea contra la base: precio, nombre y disponibilidad. Lo
     * unico que se respeta del cliente es QUE quiere y CUANTO.
     *
     * @param  list<array{product_id: int, product_variant_id?: ?int, quantity: int}>  $items
     * @return list<array<string, mixed>>
     */
    private function resolveLines(Business $business, array $items): array
    {
        $reserved = $this->reservedUnits($business->id);
        $lines = [];

        foreach ($items as $index => $item) {
            $product = Product::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('is_published', true)
                ->where('is_active', true)
                ->where('is_service', false)
                ->lockForUpdate()
                ->find($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}" => 'Uno de los productos ya no está disponible en la tienda.',
                ]);
            }

            $quantity = max(1, (int) $item['quantity']);
            $variant = null;

            $hasVariants = ProductVariant::withoutGlobalScopes()
                ->where('product_id', $product->id)->where('is_active', true)->exists();

            if ($hasVariants) {
                $variant = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find($item['product_variant_id'] ?? 0);

                if (! $variant) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => "Elige una opción de \"{$product->name}\".",
                    ]);
                }
            }

            $available = $variant
                ? (float) $variant->stock - ($reserved['variants'][$variant->id] ?? 0)
                : $this->productStock($business, $product) - ($reserved['products'][$product->id] ?? 0);

            if ($product->track_stock && $available < $quantity) {
                $label = $variant ? "{$product->name} ({$this->variantLabel($variant)})" : $product->name;
                throw ValidationException::withMessages([
                    "items.{$index}" => "No queda suficiente stock de \"{$label}\".",
                ]);
            }

            $unitPrice = (float) ($variant?->price ?? $product->price);

            $lines[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'variant_label' => $variant ? $this->variantLabel($variant) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $quantity, 2),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'El carrito está vacío.']);
        }

        return $lines;
    }

    private function variantLabel(ProductVariant $variant): string
    {
        return $variant->attributeValues()->pluck('value')->implode(' / ') ?: $variant->sku;
    }

    /**
     * Enlaza el pedido con la ficha de cliente del negocio, buscando por
     * telefono. Si no existe la crea, para que el comerciante vea a sus
     * compradores recurrentes en el directorio de siempre.
     *
     * @param  array<string, mixed>  $data
     */
    private function linkClient(Business $business, array $data): ?Client
    {
        if (! $business->hasFeature('clients')) {
            return null;
        }

        $phone = trim((string) $data['customer_phone']);
        $existing = Client::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('phone', $phone)
            ->first();

        if ($existing) {
            // La direccion habitual solo se llena si no tenia: si pidio a otra
            // parte, la del pedido queda en el pedido.
            if (blank($existing->address) && $this->clientAddress($data) !== null) {
                $existing->update(['address' => $this->clientAddress($data)]);
            }

            return $existing;
        }

        // El tope de clientes por negocio no puede tumbar una venta: si esta
        // lleno, el pedido sigue adelante sin ficha.
        $count = Client::withoutGlobalScopes()->where('business_id', $business->id)->count();
        if ($count >= $business->clientLimit()) {
            return null;
        }

        return Client::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'name' => trim((string) $data['customer_name']),
            'phone' => $phone,
            'email' => $data['customer_email'] ?? null,
            'address' => $this->clientAddress($data),
            'notes' => 'Creado desde la tienda online',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function clientAddress(array $data): ?string
    {
        if ((bool) ($data['is_pickup'] ?? false)) {
            return null;
        }
        $address = trim(trim((string) ($data['shipping_address'] ?? '')).', '.trim((string) ($data['shipping_city'] ?? '')), ', ');

        return $address !== '' ? mb_substr($address, 0, 255) : null;
    }

    private function nextNumber(int $businessId): int
    {
        $max = Order::withoutGlobalScopes()->where('business_id', $businessId)->max('number');

        return (int) $max + 1;
    }

    /**
     * Mueve un pedido de estado. Confirmar es especial: ahi se crea la venta
     * real con sus movimientos de stock.
     */
    public function transition(
        User $actor,
        Order $order,
        string $status,
        ?string $note = null,
        ?string $paymentMethod = null,
    ): Order {
        if (! $order->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => "Un pedido {$order->status} no puede pasar a {$status}.",
            ]);
        }

        return DB::transaction(function () use ($actor, $order, $status, $note, $paymentMethod) {
            $from = $order->status;

            if ($status === Order::STATUS_CONFIRMED) {
                // Un pedido online es un pedido, no una cuenta abierta: al
                // confirmarlo (lo pago la pasarela o el negocio verifico el pago)
                // nace su venta, y si el negocio usa comandera entra a cocina
                // desde la venta. Asi un pedido de otro canal (Rappi, etc.) puede
                // entrar por el mismo camino.
                $sale = $this->createSaleFor($actor, $order, $paymentMethod);
                $order->update([
                    'status' => $status,
                    'sale_id' => $sale->id,
                    'confirmed_at' => now(),
                    // Confirmado deja de reservar: el stock ya salio de verdad.
                    'expires_at' => null,
                ]);
            } else {
                $order->update(['status' => $status]);
            }

            $this->recordStatus($order, $from, $status, $actor->id, $note);

            return $order->fresh(['items', 'history']);
        });
    }

    /**
     * Avisa al comprador de un cambio de estado que le importa.
     *
     * Se llama DESPUES de que la transicion ya se guardo, y aparte de
     * `transition()`, para que un fallo de WhatsApp o de correo no deshaga
     * un cambio de estado que el comerciante ya dio por hecho.
     */
    public function notifyBuyer(Order $order, string $status): void
    {
        app(OnlineOrderNotifier::class)->sendForStatus($order, $status);
    }

    /**
     * Crea la venta del pedido confirmado.
     *
     * Va por SaleService y no escribiendo `sales` a mano, para que un pedido
     * online descuente stock, alimente reportes y cierre caja exactamente
     * igual que una venta de mostrador. `sales.user_id` es NOT NULL, asi que
     * el vendedor es quien confirma.
     *
     * El medio de pago lo elige quien confirma y no se fija por codigo:
     * mientras la tienda no cobre en linea (F2), el pago se coordina por
     * fuera - transferencia, Nequi, contraentrega - y solo el comerciante
     * sabe cual fue. Ademas cada negocio tiene su propio catalogo de medios
     * habilitados, asi que cualquier valor fijo aca falla en el primer
     * negocio que no lo tenga (asi se encontro este bug: 'transfer'
     * hardcodeado contra un negocio con cash/nequi/daviplata/credit).
     */
    private function createSaleFor(User $actor, Order $order, ?string $paymentMethod): Sale
    {
        $business = $order->business;
        $paidOnline = $order->paid_at !== null;
        $method = $paidOnline
            ? $business->onlinePaymentMethodFor($order->payment_provider)
            : ($paymentMethod ?? $business->allowedPaymentMethodIds()[0] ?? 'cash');

        // Fiar un pedido online no tiene sentido: no hay mostrador donde
        // fiarle a nadie ni cuenta de cliente que respalde la deuda.
        if (! $paidOnline) {
            $business->assertValidPaymentMethod($method, forbidCredit: true);
        }

        $items = $order->items->map(fn (OrderItem $item) => array_filter([
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
        ], fn ($value) => $value !== null))->all();

        $sale = $this->sales->createSale($actor, [
            'items' => $items,
            'payment_method' => $method,
            // El medio de la pasarela no tiene que estar en el catalogo del
            // negocio (el reservado "Pago en linea" no lo esta).
            'paid_online' => $paidOnline ? $method : null,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'client_id' => $order->client_id,
            'is_delivery' => ! $order->is_pickup,
            // Envio, propina e impoconsumo: los que se le cotizaron al
            // comprador con la configuracion del negocio. Se cobran esos
            // montos aunque la configuracion cambie antes de confirmar.
            'delivery_fee_override' => (float) $order->shipping_fee,
            'delivery_address' => $order->is_pickup ? null : trim($order->shipping_address.', '.$order->shipping_city, ', '),
            'charges_override' => [(float) $order->service_charge, (float) $order->ipoconsumo],
            // El cupon que el comprador redimio. Va el MONTO ademas del id
            // porque es el que ya pago: recalcularlo aqui haria que un cupon
            // editado despues moviera el total de una venta ya cobrada, y
            // dejaria la venta por encima de lo que entro en caja.
            'cart_discount_id' => $order->discount_id,
            'cart_discount_amount' => (float) $order->discount_amount,
        ]);

        // La nota es solo lo que escribio el comprador ("sin cebolla"): el
        // numero del pedido y la direccion van en sus propios campos, y la
        // comandera y el domiciliario los muestran aparte. sales.notes es
        // varchar(255).
        $sale->update([
            'notes' => filled($order->shipping_notes) ? mb_substr(trim((string) $order->shipping_notes), 0, 255) : null,
        ]);

        if ($business->hasFeature('kitchen_board')) {
            app(KitchenBoardService::class)->sendToKitchen($sale);
        }

        // Comanda impresa en cocina. Para recoger, la factura sale ya (va
        // con la bolsa); la de un domicilio sale cuando el pedido sale del
        // local, junto con el ticket del domiciliario. El pedido todavia no
        // tiene sale_id (se guarda al volver de aqui): se enlaza a mano para
        // que la comanda diga «Pedido online #N» y «PARA RECOGER».
        $sale->setRelation('onlineOrder', $order);
        app(KitchenPrintService::class)->sync($sale, $actor);
        if ($order->is_pickup) {
            app(PrintJobService::class)->enqueueReceipt($sale, $actor);
        }

        return $sale;
    }

    /**
     * Avisa al comerciante que entro un pedido.
     *
     * Se llama DESPUES de la transaccion y no dentro: un fallo del correo no
     * puede tumbar un pedido que el comprador ya dio por hecho. Va a la cola
     * por lo mismo.
     */
    public function notifyMerchant(Order $order): bool
    {
        $business = $order->business;
        $settings = $business?->storeSettings()->withoutGlobalScopes()->first();

        if ($business === null || $settings === null || ! $settings->order_email_enabled) {
            return false;
        }

        // Sin correo propio configurado, al del dueño: el que siempre existe.
        $to = $settings->order_email
            ?: $business->users()->where('is_business_owner', true)->value('email');

        if (! filter_var((string) $to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        Mail::to($to)->queue(new NewOnlineOrderMail($business, $order->loadMissing('items')));

        return true;
    }

    /**
     * Avisa al comerciante por WhatsApp que entro un pedido.
     *
     * Va aparte del correo y no lo reemplaza: el correo deja constancia y
     * WhatsApp es el que de verdad se ve. Quien esta atendiendo el mostrador
     * no revisa el correo, y un pedido que se entera tarde es un pedido que
     * se despacha tarde.
     *
     * No tumba nada si falla: el pedido ya existe y el correo ya salio.
     */
    public function notifyMerchantOnWhatsApp(Order $order): bool
    {
        $business = $order->business;
        if ($business === null) {
            return false;
        }

        $template = config('services.whatsapp.templates.pedido_nuevo_comercio');
        // Declarada pero sin aprobar en Meta: se sale en silencio en vez de
        // generar un envio fallido por cada pedido.
        if (! filled($template['name'] ?? null) || ($template['pending_approval'] ?? false)) {
            return false;
        }

        // En orden: el WhatsApp declarado del negocio, su telefono, y por
        // ultimo el celular del dueño. `whatsapp_number` va primero porque
        // es el unico de los tres que el comerciante puso SABIENDO que era
        // para WhatsApp -- los otros dos pueden ser un fijo.
        $phone = ChannelPhone::normalize((string) (
            $business->whatsapp_number
            ?: $business->phone
            ?: $business->users()->where('is_business_owner', true)->value('cellphone')
        ));

        if ($phone === null) {
            return false;
        }

        try {
            return app(MessagingChannel::class)->sendTemplate(
                $phone,
                (string) $template['name'],
                (string) ($template['lang'] ?? 'es_CO'),
                [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => (string) $order->number],
                    ['type' => 'text', 'text' => '$'.number_format((float) $order->total, 0, ',', '.')],
                    ['type' => 'text', 'text' => mb_substr((string) $order->customer_name, 0, 60)],
                ]]],
                $business->id,
                'pedido_nuevo',
            );
        } catch (\Throwable $e) {
            Log::warning('online_order.merchant_whatsapp_failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pide el link de pago de un pedido y lo guarda.
     *
     * Se hace DESPUES de crear el pedido y fuera de su transaccion: si la
     * pasarela esta caida, el pedido igual queda registrado y el
     * comerciante puede cobrarlo por fuera. Al reves -- perder el pedido
     * porque la pasarela no respondio -- seria perder la venta entera.
     *
     * Devuelve el pedido con `payment_url` si se pudo, sin el si no.
     */
    public function attachPaymentLink(Order $order): Order
    {
        $business = $order->business;
        $gateway = $business?->activePaymentGateway();
        if ($gateway === null) {
            return $order;
        }

        try {
            $link = app(PaymentsCoreService::class)
                ->usingGateway($gateway)
                ->createPaymentLink(
                    amountCop: (int) round((float) $order->total),
                    description: "Pedido #{$order->number} - ".($business->name ?? 'Tienda'),
                    customer: [
                        'email' => (string) ($order->customer_email ?? ''),
                        'full_name' => (string) $order->customer_name,
                    ],
                    redirectUrl: $this->trackingUrl($business, $order),
                    metadata: ['order_id' => $order->id, 'business_id' => $business->id],
                    // El link muere cuando muere la reserva de stock: dejarlo
                    // vivo mas tiempo permitiria pagar algo que ya se libero.
                    expiresInMinutes: self::RESERVATION_MINUTES,
                );
        } catch (\Throwable $e) {
            // No se propaga: el pedido ya existe y el comprador ya lo dio por
            // hecho. Queda sin link y el comerciante lo cobra por fuera.
            Log::warning('online_order.payment_link_failed', [
                'order_id' => $order->id,
                'provider' => $gateway->provider_slug,
                'message' => $e->getMessage(),
            ]);

            return $order;
        }

        $order->forceFill([
            'payment_provider' => $gateway->provider_slug,
            'payment_reference' => $link['reference'] ?? null,
            'payment_url' => $link['payment_url'] ?? null,
        ])->save();

        return $order;
    }

    /** A donde vuelve el comprador despues de pagar. */
    private function trackingUrl(Business $business, Order $order): string
    {
        $base = rtrim((string) config('app.storefront_url'), '/');

        return "{$base}/{$business->slug}/pedido/{$order->public_token}";
    }

    public function recordStatus(Order $order, ?string $from, string $to, ?int $userId, ?string $note): void
    {
        $order->history()->create([
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'note' => $note,
        ]);
    }

    /**
     * Vence los pedidos que nadie confirmo, liberando su reserva de stock.
     * Lo llama el scheduler.
     */
    public function expireStale(): int
    {
        $stale = Order::withoutGlobalScopes()
            ->whereIn('status', Order::RESERVING_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($stale as $order) {
            $from = $order->status;
            $order->update(['status' => Order::STATUS_EXPIRED]);
            $this->recordStatus($order, $from, Order::STATUS_EXPIRED, null, 'Venció sin confirmación');
        }

        return $stale->count();
    }
}
