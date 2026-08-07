<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateSupportTicketStatusRequest;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupportTicketController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupportTicketResource::collection(
            SupportTicket::with(['business', 'user'])->latest()->paginate(25)->withQueryString()
        );
    }

    public function show(SupportTicket $ticket): SupportTicketResource
    {
        return new SupportTicketResource($ticket->load('business', 'user'));
    }

    public function updateStatus(UpdateSupportTicketStatusRequest $request, SupportTicket $ticket): SupportTicketResource
    {
        $ticket->update($request->validated());

        return new SupportTicketResource($ticket->fresh()->load('business', 'user'));
    }
}
