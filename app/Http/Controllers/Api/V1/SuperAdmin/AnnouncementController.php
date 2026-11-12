<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StoreAnnouncementRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateAnnouncementRequest;
use App\Http\Resources\Api\V1\SuperAdmin\AnnouncementResource;
use App\Jobs\SendAnnouncementWhatsApp;
use App\Models\Announcement;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnnouncementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(Announcement::withCount('dismissals')->latest()->get());
    }

    public function store(StoreAnnouncementRequest $request): AnnouncementResource
    {
        $data = collect($request->validated())->except('send_whatsapp')->all();
        $sendWhatsApp = $request->boolean('send_whatsapp');

        if ($sendWhatsApp && (string) config('services.whatsapp.templates.novedades.name') === '') {
            throw ValidationException::withMessages([
                'send_whatsapp' => 'La plantilla de novedades de WhatsApp no está configurada todavía.',
            ]);
        }

        $announcement = Announcement::create([
            ...$data,
            'created_by_user_id' => $request->user()->id,
            'whatsapp_requested_at' => $sendWhatsApp ? now() : null,
        ]);

        if ($sendWhatsApp) {
            SendAnnouncementWhatsApp::dispatch($announcement->id);
        }

        return new AnnouncementResource($announcement->loadCount('dismissals'));
    }

    /**
     * A cuantos negocios llega un alcance, y cuantos tienen WhatsApp: el
     * superadmin lo ve antes de mandar.
     */
    public function audience(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_scope' => ['required', 'string', Rule::in(['in_use', 'active', 'selected'])],
            'business_ids' => ['sometimes', 'array'],
            'business_ids.*' => ['integer'],
        ]);

        $businesses = Announcement::businessesFor($validated['business_scope'], $validated['business_ids'] ?? []);

        return response()->json([
            'businesses' => $businesses->count(),
            'with_whatsapp' => $businesses->filter(fn ($b) => $b->whatsapp_number && ChannelPhone::normalize($b->whatsapp_number))->count(),
            'names' => $businesses->pluck('name')->take(30)->values(),
        ]);
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
