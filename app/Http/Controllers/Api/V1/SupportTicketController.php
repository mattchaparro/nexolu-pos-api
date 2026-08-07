<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupportTicketRequest;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupportTicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = SupportTicket::where('business_id', $request->user()->business_id)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return SupportTicketResource::collection($tickets);
    }

    public function store(StoreSupportTicketRequest $request): SupportTicketResource
    {
        $ticket = SupportTicket::create([
            ...$request->validated(),
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'status' => 'open',
        ]);

        return new SupportTicketResource($ticket);
    }
}
