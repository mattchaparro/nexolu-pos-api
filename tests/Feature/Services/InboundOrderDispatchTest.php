<?php

namespace Tests\Feature\Services;

use App\Jobs\ProcessWhatsAppOrder;
use App\Services\WhatsApp\InboundMessageDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El dispatcher entiende el webhook `order` de Meta: lo despacha con el
 * negocio resuelto por Connect (header) y el nombre del perfil, y la
 * deduplicacion por wamid tambien lo cubre.
 */
class InboundOrderDispatchTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, mixed>
     */
    private function orderEntries(string $wamid = 'wamid.order-abc'): array
    {
        return [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '999888'],
                    'contacts' => [['profile' => ['name' => 'Laura Cliente'], 'wa_id' => '573001112233']],
                    'messages' => [[
                        'from' => '573001112233',
                        'id' => $wamid,
                        'type' => 'order',
                        'order' => [
                            'catalog_id' => 'cat-1',
                            'product_items' => [
                                ['product_retailer_id' => 'b7-PROD-001', 'quantity' => 2, 'item_price' => 9000, 'currency' => 'COP'],
                            ],
                        ],
                    ]],
                ],
            ]],
        ]];
    }

    public function test_an_order_message_dispatches_the_order_job_with_context(): void
    {
        Queue::fake();

        app(InboundMessageDispatcher::class)->dispatch($this->orderEntries(), '7');

        Queue::assertPushed(ProcessWhatsAppOrder::class, function (ProcessWhatsAppOrder $job) {
            return $job->from === '573001112233'
                && $job->wamid === 'wamid.order-abc'
                && $job->businessId === '7'
                && $job->profileName === 'Laura Cliente'
                && ($job->order['product_items'][0]['product_retailer_id'] ?? null) === 'b7-PROD-001';
        });
    }

    public function test_the_same_wamid_is_not_dispatched_twice(): void
    {
        Queue::fake();
        $dispatcher = app(InboundMessageDispatcher::class);

        $dispatcher->dispatch($this->orderEntries('wamid.dup'));
        $dispatcher->dispatch($this->orderEntries('wamid.dup'));

        Queue::assertPushed(ProcessWhatsAppOrder::class, 1);
    }
}
