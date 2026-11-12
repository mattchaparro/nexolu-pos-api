<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lo que ve el usuario del POS de las difusiones del superadmin: la mas
 * reciente que le aplica y que todavia no cerro. Una a la vez, a proposito:
 * tres ventanas seguidas al entrar es la forma segura de que no lea ninguna.
 */
class AnnouncementController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->business;

        if (! $business) {
            return response()->json(['data' => null]);
        }

        $announcement = Announcement::query()
            ->where('active', true)
            ->whereDoesntHave('dismissals', fn ($q) => $q->where('user_id', $user->id))
            // Lo que se publico antes de que existiera la cuenta no es novedad para ella.
            ->where('created_at', '>=', $user->created_at ?? now()->subYears(5))
            ->latest('id')
            ->limit(20)
            ->get()
            ->first(fn (Announcement $a) => $a->reachesBusiness($business) && $a->reachesRole($user));

        return response()->json(['data' => $announcement ? [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'cta_label' => $announcement->cta_label,
            'cta_url' => $announcement->cta_url,
        ] : null]);
    }

    public function dismiss(Request $request, Announcement $announcement): Response
    {
        AnnouncementDismissal::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['dismissed_at' => now()],
        );

        return response()->noContent();
    }
}
