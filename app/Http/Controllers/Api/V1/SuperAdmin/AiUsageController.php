<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\AiUsageReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel del Asistente de IA: cuanto se usa, cuanto cuesta y que preguntas
 * quedaron sin responder.
 *
 * La lista de preguntas sin responder es la razon original de esta pantalla.
 * Se registraban desde AiChatController pero no habia donde leerlas fuera de
 * `php artisan ai:unanswered` en el servidor, que es otra forma de perder la
 * señal - el chat del legacy murio con 24 de estas sin que nadie las viera.
 */
class AiUsageController extends Controller
{
    public function __construct(private AiUsageReportService $report) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'summary' => $this->report->summary(),
            'businesses' => $this->report->perBusiness(),
            'unanswered' => $this->report->unansweredQuestions(
                includeReviewed: $request->boolean('include_reviewed'),
            ),
        ]);
    }

    public function markQuestionReviewed(int $question): JsonResponse
    {
        $updated = $this->report->markQuestionReviewed($question);

        if ($updated === 0) {
            return response()->json(['message' => 'Esa pregunta ya no existe.'], 404);
        }

        return response()->json(['reviewed' => $updated]);
    }
}
