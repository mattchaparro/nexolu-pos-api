<?php

namespace App\Jobs;

use App\Services\WhatsApp\NexoluCommsCatalogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retira UN producto del catalogo de Meta (via Connect). Job aparte del
 * sync porque el sync manda "lo publicable ahora" y no sabe que ACABA de
 * dejar de serlo - quien si lo sabe es el hook de Product (el interruptor
 * paso a false, o el producto se borro/desactivo), y despacha esto con el
 * retailer_id ya resuelto (escalar: el producto puede ya no existir cuando
 * el job corra).
 */
class RemoveWhatsAppCatalogItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $retailerId) {}

    public function handle(NexoluCommsCatalogClient $catalog): void
    {
        $catalog->sync([], [$this->retailerId]);
    }
}
