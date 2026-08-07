<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StoreAnnouncementRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateAnnouncementRequest;
use App\Http\Resources\Api\V1\SuperAdmin\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AnnouncementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(Announcement::latest()->get());
    }

    public function store(StoreAnnouncementRequest $request): AnnouncementResource
    {
        return new AnnouncementResource(Announcement::create($request->validated()));
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        $announcement->update($request->validated());

        return new AnnouncementResource($announcement->fresh());
    }

    public function destroy(Announcement $announcement): Response
    {
        $announcement->delete();

        return response()->noContent();
    }
}
