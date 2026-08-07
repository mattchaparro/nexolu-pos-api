<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PostponeReminderRequest;
use App\Http\Requests\Api\V1\StoreReminderRequest;
use App\Http\Resources\Api\V1\ReminderResource;
use App\Models\Reminder;
use App\Services\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReminderController extends Controller
{
    public function __construct(private ReminderService $reminderService) {}

    /**
     * @return array<string, AnonymousResourceCollection>
     */
    public function index(): array
    {
        $pending = Reminder::query()
            ->where('status', Reminder::STATUS_PENDING)
            ->orderBy('due_date')
            ->get();

        $completed = Reminder::query()
            ->where('status', Reminder::STATUS_DONE)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        return [
            'pending' => ReminderResource::collection($pending),
            'completed' => ReminderResource::collection($completed),
        ];
    }

    public function store(StoreReminderRequest $request): ReminderResource
    {
        $reminder = $this->reminderService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return new ReminderResource($reminder);
    }

    public function destroy(Reminder $reminder): JsonResponse
    {
        $reminder->delete();

        return response()->json(status: 204);
    }

    public function complete(Reminder $reminder): ReminderResource
    {
        return new ReminderResource($this->reminderService->complete($reminder));
    }

    public function postpone(PostponeReminderRequest $request, Reminder $reminder): ReminderResource
    {
        return new ReminderResource($this->reminderService->postpone($reminder, $request->validated('due_date')));
    }
}
