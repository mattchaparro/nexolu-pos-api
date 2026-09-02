<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessWhatsAppInbound;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * Que recibe quien escribe al WhatsApp del POS sin tener el numero vinculado.
 *
 * Lo que defiende esta prueba no es el copy: es que NO se le conteste la
 * pregunta. Un numero de WhatsApp por si solo no dice a que negocio
 * pertenece, y este asistente consulta ventas, caja e inventario. Responderle
 * a un numero sin vincular seria abrirle los datos de un negocio a cualquiera
 * que consiga el telefono.
 */
class ProcessWhatsAppInboundOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_un_numero_sin_vincular_recibe_el_instructivo_y_no_una_respuesta(): void
    {
        $enviados = [];

        $canal = Mockery::mock(MessagingChannel::class);
        $canal->shouldReceive('sendText')
            ->once()
            ->andReturnUsing(function (string $to, string $body, ?int $businessId, string $type) use (&$enviados) {
                $enviados[] = compact('to', 'body', 'type');

                return true;
            });
        // Si el asistente contestara, tendria que marcar leido primero. No
        // debe pasar: el numero no esta vinculado.
        $canal->shouldNotReceive('markAsReadWithTyping');
        $this->app->instance(MessagingChannel::class, $canal);

        dispatch_sync(new ProcessWhatsAppInbound('573000000000', '¿cuánto vendí hoy?'));

        $this->assertCount(1, $enviados);
        $this->assertSame('ai_chat_onboarding', $enviados[0]['type']);

        $texto = $enviados[0]['body'];
        // Dice QUE hacer y DONDE.
        $this->assertStringContainsString('Asistente', $texto);
        // Y que el codigo llega por aca: sin eso la gente sale a buscar un
        // SMS que nunca va a llegar.
        $this->assertStringContainsString('código', $texto);
        // Nunca la respuesta a lo que pregunto.
        $this->assertStringNotContainsString('vendí', $texto);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
