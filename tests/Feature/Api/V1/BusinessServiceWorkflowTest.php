<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessServiceWorkflow;
use App\Models\ServiceWorkflow;
use App\Models\ServiceWorkflowStage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessServiceWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returns_null_when_the_business_has_no_workflow_assigned(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/service-workflow')
            ->assertOk()
            ->assertContent('null');
    }

    public function test_returns_the_assigned_workflow_with_its_stages(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $workflow = ServiceWorkflow::factory()->create(['name' => 'Taller']);
        $stage = ServiceWorkflowStage::factory()->create(['workflow_id' => $workflow->id, 'label' => 'Recibido', 'is_initial' => true]);
        BusinessServiceWorkflow::create(['business_id' => $business->id, 'workflow_id' => $workflow->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/service-workflow')
            ->assertOk()
            ->assertJsonPath('id', $workflow->id)
            ->assertJsonPath('name', 'Taller')
            ->assertJsonPath('stages.0.id', $stage->id)
            ->assertJsonPath('stages.0.label', 'Recibido')
            ->assertJsonMissingPath('stages.0.actions');
    }

    public function test_only_returns_the_workflow_assigned_to_the_authenticated_business(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $workflow = ServiceWorkflow::factory()->create();
        BusinessServiceWorkflow::create(['business_id' => $otherBusiness->id, 'workflow_id' => $workflow->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/service-workflow')
            ->assertOk()
            ->assertContent('null');
    }
}
