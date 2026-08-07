<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Recordatorio de que el cliente tiene una cita al dia siguiente. Enviado por
 * el comando appointments:send-reminders. Los headers X-Nexolu-Business-Id /
 * X-Nexolu-Email-Type hacen que LogSentEmail clasifique este envio
 * automaticamente en el historial de correos del panel de SuperAdmin.
 */
class AppointmentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
    ) {}

    public function envelope(): Envelope
    {
        $business = $this->appointment->business;
        $businessName = $business?->name ?? config('app.name');

        return new Envelope(
            subject: "Recordatorio: tu cita es mañana en {$businessName}",
        );
    }

    public function content(): Content
    {
        $appointment = $this->appointment;
        $business = $appointment->business;
        $starts = $appointment->starts_at->timezone('America/Bogota');
        $ends = $appointment->ends_at->timezone('America/Bogota');

        return new Content(
            view: 'emails.appointment-reminder',
            with: [
                'business_name' => $business?->name ?? config('app.name'),
                'business_address' => $business?->address,
                'business_phone' => $business?->phone,
                'business_whatsapp' => $business?->whatsapp_number,
                'show_whatsapp_cta' => (bool) $business?->email_whatsapp_cta,
                'client_name' => $appointment->client_name,
                'service_name' => $appointment->service?->name ?? 'Servicio',
                'date_label' => $starts->translatedFormat('l, j \d\e F \d\e Y'),
                'time_label' => $starts->format('g:i a').' - '.$ends->format('g:i a'),
                'staff_name' => $appointment->staff?->name,
                'notes' => $appointment->notes,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->appointment->business_id,
                'X-Nexolu-Email-Type' => 'appointment_reminder',
            ],
        );
    }
}
