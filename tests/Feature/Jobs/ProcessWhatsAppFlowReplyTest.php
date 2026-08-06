<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessWhatsAppFlowReply;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\User;
use App\Services\AiDraftService;
use App\Services\WhatsApp\IdentityResolver;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessWhatsAppFlowReplyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.api_key' => 'test-ia-core-key',
            'services.ia_core.base_url' => 'http://ia-core.test',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
        ]);
    }

    private function linkedUser(): User
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        AiChannelIdentity::create([
            'business_id' => $business->id, 'user_id' => $user->id,
            'channel' => 'whatsapp', 'external_id' => '573001234567', 'verified_at' => now(),
        ]);

        return $user;
    }

    private function handle(string $from, array $response): void
    {
        (new ProcessWhatsAppFlowReply($from, $response))->handle(
            app(IdentityResolver::class),
            app(AiDraftService::class),
            app(WhatsAppCloudClient::class),
        );
    }

    public function test_does_nothing_for_an_unlinked_number(): void
    {
        Http::fake();

        $this->handle('573009999999', ['flow_token' => 'draft-1']);

        Http::assertNothingSent();
    }

    public function test_confirms_the_draft_with_the_flow_values_and_replies_with_success(): void
    {
        $this->linkedUser();
        Http::fake([
            'ia-core.test/*' => Http::response(['status' => 'confirmed', 'data' => ['id' => 1]], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
        ]);

        $this->handle('573001234567', ['flow_token' => 'draft-1', 'monto' => 25000, 'concepto' => 'Papeleria']);

        Http::assertSent(fn ($request) => $request->url() === 'http://ia-core.test/v1/drafts/draft-1/confirm'
            && $request['values'] === ['monto' => 25000, 'concepto' => 'Papeleria']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'] ?? '', 'Registrado'));
    }

    public function test_replies_that_the_draft_expired_when_the_core_returns_404(): void
    {
        $this->linkedUser();
        Http::fake([
            'ia-core.test/*' => Http::response(['detail' => 'Borrador no encontrado.'], 404),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
        ]);

        $this->handle('573001234567', ['flow_token' => 'draft-1']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'] ?? '', 'expirado'));
    }

    public function test_replies_that_it_was_already_registered_when_the_core_returns_409(): void
    {
        $this->linkedUser();
        Http::fake([
            'ia-core.test/*' => Http::response(['detail' => "El borrador ya esta en estado 'confirmed'."], 409),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
        ]);

        $this->handle('573001234567', ['flow_token' => 'draft-1']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'] ?? '', 'ya se habia registrado'));
    }

    public function test_does_nothing_when_the_flow_token_is_missing(): void
    {
        $this->linkedUser();
        Http::fake();

        $this->handle('573001234567', ['monto' => 25000]);

        Http::assertNothingSent();
    }
}
