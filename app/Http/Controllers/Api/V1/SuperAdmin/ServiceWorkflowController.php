<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\ReorderServiceWorkflowStagesRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreServiceWorkflowRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreServiceWorkflowStageRequest;
use App\Http\Resources\Api\V1\SuperAdmin\ServiceWorkflowResource;
use App\Models\Business;
use App\Models\BusinessServiceWorkflow;
use App\Models\ServiceWorkflow;
use App\Models\ServiceWorkflowStage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServiceWorkflowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceWorkflowResource::collection(
            ServiceWorkflow::withCount(['stages', 'businesses'])->orderBy('name')->get()
        );
    }

    public function store(StoreServiceWorkflowRequest $request): ServiceWorkflowResource
    {
        return new ServiceWorkflowResource(ServiceWorkflow::create($request->validated()));
    }

    public function show(ServiceWorkflow $serviceWorkflow): ServiceWorkflowResource
    {
        return new ServiceWorkflowResource($serviceWorkflow->load('stages')->loadCount('businesses'));
    }

    public function update(StoreServiceWorkflowRequest $request, ServiceWorkflow $serviceWorkflow): ServiceWorkflowResource
    {
        $serviceWorkflow->update($request->validated());

        return new ServiceWorkflowResource($serviceWorkflow->fresh());
    }

    public function destroy(ServiceWorkflow $serviceWorkflow): Response
    {
        $serviceWorkflow->delete();

        return response()->noContent();
    }

    public function storeStage(StoreServiceWorkflowStageRequest $request, ServiceWorkflow $serviceWorkflow): ServiceWorkflowResource
    {
        $maxOrder = $serviceWorkflow->stages()->max('sort_order') ?? -1;

        $serviceWorkflow->stages()->create([
            ...$request->validated(),
            'sort_order' => $maxOrder + 1,
        ]);

        return new ServiceWorkflowResource($serviceWorkflow->fresh()->load('stages'));
    }

    public function updateStage(StoreServiceWorkflowStageRequest $request, ServiceWorkflow $serviceWorkflow, ServiceWorkflowStage $stage): ServiceWorkflowResource
    {
        abort_unless($stage->workflow_id === $serviceWorkflow->id, 404);

        $stage->update($request->validated());

        return new ServiceWorkflowResource($serviceWorkflow->fresh()->load('stages'));
    }

    public function destroyStage(ServiceWorkflow $serviceWorkflow, ServiceWorkflowStage $stage): ServiceWorkflowResource
    {
        abort_unless($stage->workflow_id === $serviceWorkflow->id, 404);

        $stage->delete();

        $serviceWorkflow->stages()->get()->values()->each(fn ($s, $i) => $s->update(['sort_order' => $i]));

        return new ServiceWorkflowResource($serviceWorkflow->fresh()->load('stages'));
    }

    public function reorderStages(ReorderServiceWorkflowStagesRequest $request, ServiceWorkflow $serviceWorkflow): ServiceWorkflowResource
    {
        foreach ($request->validated('ids') as $order => $id) {
            $serviceWorkflow->stages()->where('id', $id)->update(['sort_order' => $order]);
        }

        return new ServiceWorkflowResource($serviceWorkflow->fresh()->load('stages'));
    }

    public function assignBusiness(ServiceWorkflow $serviceWorkflow, Business $business): Response
    {
        BusinessServiceWorkflow::where('business_id', $business->id)->delete();
        BusinessServiceWorkflow::create(['business_id' => $business->id, 'workflow_id' => $serviceWorkflow->id]);

        return response()->noContent();
    }

    public function unassignBusiness(ServiceWorkflow $serviceWorkflow, Business $business): Response
    {
        BusinessServiceWorkflow::where('business_id', $business->id)
            ->where('workflow_id', $serviceWorkflow->id)
            ->delete();

        return response()->noContent();
    }
}
