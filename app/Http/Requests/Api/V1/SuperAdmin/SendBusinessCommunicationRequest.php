<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendBusinessCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // El WhatsApp generico (plantilla 'recordatorio') tiene una unica
        // variable de texto libre truncada a 300 caracteres en el envio
        // (mismo limite que RemindersSendWhatsAppNotifications) - se valida
        // aca en vez de truncar en silencio, para que el superadmin sepa
        // que su mensaje no cabe antes de mandarlo.
        $maxLength = $this->input('channel') === 'whatsapp' ? 300 : 2000;

        return [
            'channel' => ['required', 'in:email,whatsapp'],
            'subject' => ['required_if:channel,email', 'nullable', 'string', 'max:255'],
            'message' => ['required', 'string', "max:{$maxLength}"],
        ];
    }
}
