<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\AiUnansweredQuestion;
use App\Models\AiUsageDaily;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.api_key' => 'test-ia-core-key',
            'services.ia_core.base_url' => 'http://ia-core.test',
        ]);
    }

    private function fakeIaCoreUsage(array $byBusiness = [], float $totalCost = 0.0): void
    {
        Http::fake(['ia-core.test/v1/usage/summary*' => Http::response([
            'summary' => ['message_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => $totalCost],
            'by_business' => $byBusiness,
        ])]);
    }

    public function test_an_employee_cannot_reach_the_panel(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/superadmin/ai/usage')
            ->assertStatus(403);
    }

    public function test_index_reports_messages_per_business_with_the_real_cost_from_ia_core(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['name' => 'Panaderia Lucia']);

        AiUsageDaily::factory()->create([
            'business_id' => $business->id,
            'date' => now()->startOfMonth()->toDateString(),
            'messages_count' => 40,
        ]);
        AiUsageDaily::factory()->create([
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'messages_count' => 12,
        ]);

        $this->fakeIaCoreUsage(
            byBusiness: [['key' => (string) $business->id, 'message_count' => 52, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 1.3]],
            totalCost: 1.3,
        );

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/ai/usage')->assertOk();

        $row = collect($response->json('businesses'))->firstWhere('business_id', $business->id);
        $this->assertSame('Panaderia Lucia', $row['name']);
        $this->assertSame(52, $row['messages']);
        $this->assertSame(1.3, $row['cost_usd']);
        $this->assertSame(1.3, $response->json('summary.month_cost_usd'));
    }

    /**
     * El costo NO vive en pos-api: las columnas de tokens/costo de
     * ai_usage_daily vienen del esquema del legacy y nadie las escribe. Si el
     * IA Core no responde hay que decir "no se sabe", nunca "$0" - eso se lee
     * como "la IA no cuesta nada".
     */
    public function test_an_unreachable_ia_core_reports_unknown_cost_not_zero(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        AiUsageDaily::factory()->create([
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'messages_count' => 5,
        ]);

        Http::fake(['ia-core.test/*' => Http::response('boom', 500)]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/ai/usage')->assertOk();

        $this->assertNull($response->json('summary.month_cost_usd'));
        $this->assertNull($response->json('summary.cost_per_message_usd'));
        $this->assertNull(collect($response->json('businesses'))->firstWhere('business_id', $business->id)['cost_usd']);
        // Los mensajes salen de la base local: siguen ahi aunque el IA Core no.
        $this->assertSame(5, collect($response->json('businesses'))->firstWhere('business_id', $business->id)['messages']);
    }

    public function test_unanswered_questions_are_grouped_by_text_across_businesses(): void
    {
        $admin = $this->superadmin();
        $this->fakeIaCoreUsage();

        $first = Business::factory()->create();
        $second = Business::factory()->create();

        AiUnansweredQuestion::factory()->create(['business_id' => $first->id, 'pregunta' => '¿Que proveedores tengo?']);
        AiUnansweredQuestion::factory()->create(['business_id' => $second->id, 'pregunta' => '¿QUE PROVEEDORES TENGO?']);
        AiUnansweredQuestion::factory()->create(['business_id' => $first->id, 'pregunta' => 'Hola']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/ai/usage')->assertOk();

        $rows = collect($response->json('unanswered'));
        $suppliers = $rows->first();

        // La misma necesidad de dos negocios es UNA linea con su conteo: es lo
        // que permite priorizar cual herramienta escribir primero.
        $this->assertSame(2, $suppliers['times']);
        $this->assertSame(2, $suppliers['businesses']);
        $this->assertSame(1, $rows->firstWhere('question', 'Hola')['times']);
    }

    /**
     * La pantalla agrupa por texto: marcar solo la fila clickeada dejaria la
     * linea ahi con el conteo bajado en uno, que se lee como si no hubiera
     * funcionado.
     */
    public function test_marking_a_question_reviewed_hides_every_row_with_the_same_text(): void
    {
        $admin = $this->superadmin();
        $this->fakeIaCoreUsage();

        $business = Business::factory()->create();
        $rows = AiUnansweredQuestion::factory()->count(3)->create([
            'business_id' => $business->id,
            'pregunta' => '¿Que proveedores tengo?',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/ai/unanswered/{$rows->first()->id}/reviewed")
            ->assertOk()
            ->assertJson(['reviewed' => 3]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/ai/usage')->assertOk();
        $this->assertCount(0, $response->json('unanswered'));

        $withReviewed = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/ai/usage?include_reviewed=1')
            ->assertOk();
        $this->assertCount(1, $withReviewed->json('unanswered'));
    }
}
