<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Storefront\StoreStorefrontOrderRequest;
use App\Http\Resources\Api\V1\Storefront\StorefrontOrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Checkout y seguimiento de pedidos, del lado del COMPRADOR ANONIMO.
 *
 * El negocio lo resuelve ResolveStorefrontTenant desde el slug, igual que en
 * el catalogo. El seguimiento va por `public_token` y no por id: un
 * comprador sin cuenta necesita una llave para volver a su pedido, y una
 * secuencia de ids seria una invitacion a leer los pedidos de los demas.
 */
class StorefrontOrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(StoreStorefrontOrderRequest $request): StorefrontOrderResource
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $order = $this->orders->createFromStorefront($business, $request->validated());

        return new StorefrontOrderResource($order);
    }

    public function show(Request $request): StorefrontOrderResource
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $order = Order::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('public_token', (string) $request->route('token'))
            ->with('items')
            ->first();

        abort_if($order === null, 404);

        return new StorefrontOrderResource($order);
    }
}
