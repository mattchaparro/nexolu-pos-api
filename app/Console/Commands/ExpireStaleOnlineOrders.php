<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Vence los pedidos de la tienda que nadie confirmo y libera su reserva de
 * stock. Sin esto, un carrito abandonado retendria unidades para siempre y
 * la tienda dejaria de ofrecer algo que si tiene.
 */
class ExpireStaleOnlineOrders extends Command
{
    protected $signature = 'orders:expire-stale';

    protected $description = 'Vence pedidos online sin confirmar y libera su reserva de stock';

    public function handle(OrderService $orders): int
    {
        $count = $orders->expireStale();
        $this->info("Pedidos vencidos: {$count}");

        return self::SUCCESS;
    }
}
