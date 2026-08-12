<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\BusinessServiceWorkflow;
use App\Models\ServiceWorkflow;
use App\Models\ServiceWorkflowStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class ServiceWorkflowsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_full_workflow_and_stage_lifecycle(): void
    {
        $admin = $this->superadmin();

        $workflow = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/service-workflows', [
            'name' => 'Taller de reparaciones',
        ])->assertCreated()->json();

        $stage1 = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/service-workflows/{$workflow['id']}/stages", [
                'label' => 'Recibido', 'color' => '#3B82F6', 'is_initial' => true,
            ])->assertOk()->json();
        $stageId1 = $stage1['stages'][0]['id'];

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/service-workflows/{$workflow['id']}/stages", [
                'label' => 'Listo', 'color' => '#10B981',
            ])->assertOk();

        $show = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/service-workflows/{$workflow['id']}")
            ->assertOk();
        $this->assertCount(2, $show->json('stages'));

        $stageId2 = collect($show->json('stages'))->firstWhere('label', 'Listo')['id'];

        // Reordenar: "Listo" pasa a ser el primero.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/service-workflows/{$workflow['id']}/stages/reorder", [
                'ids' => [$stageId2, $stageId1],
            ])->assertOk();

        $this->assertDatabaseHas('service_workflow_stages', ['id' => $stageId2, 'sort_order' => 0]);
        $this->assertDatabaseHas('service_workflow_stages', ['id' => $stageId1, 'sort_order' => 1]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/service-workflows/{$workflow['id']}/stages/{$stageId1}")
            ->assertOk();
        $this->assertDatabaseMissing('service_workflow_stages', ['id' => $stageId1]);
    }

    public function test_a_business_can_only_have_one_workflow_assigned_at_a_time(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $workflowA = ServiceWorkflow::factory()->create();
        $workflowB = ServiceWorkflow::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/service-workflows/{$workflowA->id}/businesses/{$business->id}")
            ->assertNoContent();
        $this->assertDatabaseHas('business_service_workflows', ['business_id' => $business->id, 'workflow_id' => $workflowA->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/service-workflows/{$workflowB->id}/businesses/{$business->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('business_service_workflows', ['business_id' => $business->id, 'workflow_id' => $workflowA->id]);
        $this->assertDatabaseHas('business_service_workflows', ['business_id' => $business->id, 'workflow_id' => $workflowB->id]);
        $this->assertSame(1, BusinessServiceWorkflow::where('business_id', $business->id)->count());

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/service-workflows/{$workflowB->id}/businesses/{$business->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('business_service_workflows', ['business_id' => $business->id]);
    }

    public function test_show_lists_the_businesses_currently_assigned_to_the_workflow(): void
    {
        $admin = $this->superadmin();
        $workflow = ServiceWorkflow::factory()->create();
        $business = Business::factory()->create(['name' => 'Taller El Rayo']);
        BusinessServiceWorkflow::create(['business_id' => $business->id, 'workflow_id' => $workflow->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/superadmin/service-workflows/{$workflow->id}");

        $response->assertOk()
            ->assertJsonPath('businesses_count', 1)
            ->assertJsonPath('businesses.0.id', $business->id)
            ->assertJsonPath('businesses.0.name', 'Taller El Rayo');
    }

    public function test_index_reports_stage_and_business_counts(): void
    {
        $admin = $this->superadmin();
        $workflow = ServiceWorkflow::factory()->create();
        ServiceWorkflowStage::factory()->count(3)->create(['workflow_id' => $workflow->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/service-workflows');

        $response->assertOk()->assertJsonPath('0.stages_count', 3);
    }
}
