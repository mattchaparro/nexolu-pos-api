<?php

namespace Tests\Feature\Mail;

use App\Mail\NewOnlineOrderMail;
use App\Models\Business;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El correo sale por el Communications Core, no por SMTP.
 *
 * Se prueba sobre un Mailable REAL y sin tocarlo: la gracia del transporte
 * es justamente que los 13 Mailables del repo no cambian.
 */
class CommsTransportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'comms',
            'services.comms_core.base_url' => 'http://comms.test',
            'services.comms_core.api_key' => 'comms-key',
        ]);
    }

    private function order(): Order
    {
        $business = Business::factory()->create(['name' => 'Café Altura']);

        return Order::create([
            'business_id' => $business->id,
            'number' => 7,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 45000,
            'shipping_fee' => 5000,
            'total' => 50000,
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'public_token' => 'tok'.uniqid(),
        ]);
    }

    public function test_an_email_goes_out_through_the_communications_core(): void
    {
        Http::fake(['http://comms.test/*' => Http::response(['results' => []], 200)]);
        $order = $this->order();

        Mail::to('duena@negocio.test')->send(new NewOnlineOrderMail($order->business, $order));

        Http::assertSent(function ($request) use ($order) {
            return str_contains($request->url(), '/v1/notifications/send')
                && $request['channels'] === ['email']
                && $request['to'] === ['email' => 'duena@negocio.test']
                && str_contains((string) $request['subject'], 'Nuevo pedido #7')
                // El HTML del Mailable viaja tal cual: el Core no lo arma.
                && str_contains((string) $request['html'], 'Café Altura')
                // Los headers que ya ponian los Mailables mapean al Core sin
                // tocar una sola clase.
                && $request['business_id'] === (string) $order->business_id
                && $request['reference'] === 'new_online_order';
        });
    }

    /**
     * Un correo que no sale nunca puede tumbar la operacion que lo disparo
     * (una venta, un alta de empleado). Se registra y sigue.
     */
    public function test_a_failure_in_the_core_does_not_break_the_caller(): void
    {
        Http::fake(['http://comms.test/*' => Http::response(['detail' => 'sin credito'], 502)]);
        $order = $this->order();

        Mail::to('duena@negocio.test')->send(new NewOnlineOrderMail($order->business, $order));

        $this->assertTrue(true, 'El envio no relanza');
    }

    public function test_mail_fake_still_works_so_the_rest_of_the_suite_is_unaffected(): void
    {
        Mail::fake();
        $order = $this->order();

        Mail::to('duena@negocio.test')->send(new NewOnlineOrderMail($order->business, $order));

        Mail::assertSent(NewOnlineOrderMail::class);
    }
}
