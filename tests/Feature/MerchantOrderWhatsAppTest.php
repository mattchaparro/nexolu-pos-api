<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Aviso por WhatsApp al COMERCIANTE cuando entra un pedido.
 *
 * Era el unico aviso que no tenia canal: al comerciante solo le llegaba
 * correo, que es justo el que no mira quien esta atendiendo el mostrador. Un
 * pedido del que se entera tarde es un pedido que se despacha tarde.
 *
 * Lo que estas pruebas defienden ademas: una plantilla declarada pero SIN
 * aprobar en Meta no se intenta enviar. Sin esa guarda, cada pedido
 * generaria un envio fallido contra Meta.
 */
class MerchantOrderWhatsAppTest extends TestCase
{
    use DatabaseTransactions;

    private function tienda(string $telefono = '3195244852'): Business
    {
        $business = Business::factory()->create([
            'feature_flags' => ['online_store' => true],
            'whatsapp_number' => $telefono,
            'phone' => null,
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        return $business;
    }

    private function pedido(Business $business): Order
    {
        return Order::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'number' => 7,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 20000,
            'shipping_fee' => 0,
            'total' => 20000,
            'customer_name' => 'Compradora',
            'customer_phone' => '3001112233',
            'is_pickup' => true,
            'public_token' => Str::random(40),
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    public function test_le_avisa_al_comerciante_con_numero_pedido_total_y_comprador(): void
    {
        config(['services.whatsapp.templates.pedido_nuevo_comercio' => [
            'name' => 'pedido_nuevo_comercio',
            'lang' => 'es_CO',
        ]]);

        $capturado = [];
        $canal = Mockery::mock(MessagingChannel::class);
        $canal->shouldReceive('sendTemplate')->once()
            ->andReturnUsing(function (...$args) use (&$capturado) {
                $capturado = $args;

                return true;
            });
        $this->app->instance(MessagingChannel::class, $canal);

        $business = $this->tienda();
        $this->assertTrue(app(OrderService::class)->notifyMerchantOnWhatsApp($this->pedido($business)));

        // Colombia-only: el numero local se normaliza con indicativo.
        $this->assertSame('573195244852', $capturado[0]);
        $this->assertSame('pedido_nuevo_comercio', $capturado[1]);

        $textos = array_column($capturado[3][0]['parameters'], 'text');
        $this->assertSame(['7', '$20.000', 'Compradora'], $textos);
    }

    /**
     * Sin esta guarda, cada pedido dispara un envio contra una plantilla que
     * Meta no conoce.
     */
    public function test_no_intenta_enviar_una_plantilla_sin_aprobar(): void
    {
        config(['services.whatsapp.templates.pedido_nuevo_comercio' => [
            'name' => 'pedido_nuevo_comercio',
            'lang' => 'es_CO',
            'pending_approval' => true,
        ]]);

        $canal = Mockery::mock(MessagingChannel::class);
        $canal->shouldNotReceive('sendTemplate');
        $this->app->instance(MessagingChannel::class, $canal);

        $business = $this->tienda();
        $this->assertFalse(app(OrderService::class)->notifyMerchantOnWhatsApp($this->pedido($business)));
    }

    /** Un negocio sin telefono no tiene a donde recibir el aviso. */
    public function test_sin_telefono_no_manda_nada(): void
    {
        config(['services.whatsapp.templates.pedido_nuevo_comercio' => [
            'name' => 'pedido_nuevo_comercio',
            'lang' => 'es_CO',
        ]]);

        $canal = Mockery::mock(MessagingChannel::class);
        $canal->shouldNotReceive('sendTemplate');
        $this->app->instance(MessagingChannel::class, $canal);

        $business = $this->tienda('');
        User::withoutGlobalScopes()->where('business_id', $business->id)->update(['cellphone' => null]);

        $this->assertFalse(app(OrderService::class)->notifyMerchantOnWhatsApp($this->pedido($business)));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
