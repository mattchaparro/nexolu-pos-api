<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateEmailTemplateRequest;
use App\Http\Resources\Api\V1\SuperAdmin\EmailLogResource;
use App\Http\Resources\Api\V1\SuperAdmin\EmailTemplateResource;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Correos transaccionales: solo lectura de logs y edicion de plantillas
 * (contenido en BD, tabla email_templates). El envio real (SMTP) y el
 * render de preview no se portan aqui - requieren la infraestructura de
 * Mail/vistas del legacy, que esta API todavia no tiene.
 */
class EmailController extends Controller
{
    public function logs(Request $request): AnonymousResourceCollection
    {
        $query = EmailLog::query()->latest();

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->integer('business_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return EmailLogResource::collection($query->paginate(30)->withQueryString());
    }

    public function templates(): AnonymousResourceCollection
    {
        return EmailTemplateResource::collection(EmailTemplate::orderBy('type')->get());
    }

    public function updateTemplate(UpdateEmailTemplateRequest $request, string $type): EmailTemplateResource
    {
        $template = EmailTemplate::updateOrCreate(['type' => $type], $request->validated());

        return new EmailTemplateResource($template);
    }
}
