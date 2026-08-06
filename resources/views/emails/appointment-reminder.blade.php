<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recordatorio de cita - {{ $business_name }}</title>
</head>
<body style="margin:0;padding:0;background:#eff6ff;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eff6ff;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #bfdbfe;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#2563eb;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Recordatorio de cita</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#1d4ed8;font-weight:600;">Hola, {{ $client_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Te recordamos tu cita de <strong>{{ $service_name }}</strong> mañana:
                        </p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:16px 0;background:#eff6ff;border-radius:10px;">
                            <tr>
                                <td style="padding:14px 16px;font-size:14px;color:#1e3a8a;line-height:1.8;">
                                    <strong>Fecha:</strong> {{ $date_label }}<br>
                                    <strong>Hora:</strong> {{ $time_label }}
                                    @if($staff_name)
                                        <br><strong>Atiende:</strong> {{ $staff_name }}
                                    @endif
                                </td>
                            </tr>
                        </table>

                        @if($notes)
                        <p style="margin:0 0 8px;font-size:13px;color:#374151;line-height:1.6;">
                            <strong>Notas:</strong> {{ $notes }}
                        </p>
                        @endif

                        @if($business_address || $business_phone)
                        <p style="margin:8px 0 0;font-size:13px;color:#6b7280;line-height:1.6;">
                            @if($business_address) {{ $business_address }} @endif
                            @if($business_phone) &middot; {{ $business_phone }} @endif
                        </p>
                        @endif
                    </td>
                </tr>

                @if($show_whatsapp_cta && $business_whatsapp)
                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 24px;text-align:center;">
                        <a href="https://wa.me/{{ $business_whatsapp }}"
                           style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Escribir por WhatsApp
                        </a>
                    </td>
                </tr>
                @endif

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#1d4ed8'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
