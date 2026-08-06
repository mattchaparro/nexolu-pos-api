<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $succeeded ? 'Pago confirmado' : 'Pago no procesado' }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $succeeded ? '#f0fdf4' : '#fef2f2' }};font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $succeeded ? '#f0fdf4' : '#fef2f2' }};padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid {{ $succeeded ? '#bbf7d0' : '#fecaca' }};">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:{{ $succeeded ? '#059669' : '#dc2626' }};color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">
                            {{ $succeeded ? 'Pago confirmado' : 'Pago no procesado' }}
                        </p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:{{ $succeeded ? '#047857' : '#b91c1c' }};font-weight:600;">Hola, {{ $admin_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            @if($succeeded)
                                Tu pago de <strong>${{ number_format($amount_cop, 0, ',', '.') }} COP</strong> fue aprobado.
                                @if($days_granted)
                                    Tu suscripcion de <strong>{{ $business_name }}</strong> quedo activa por <strong>{{ $days_granted }} dias</strong> mas.
                                @endif
                            @else
                                El pago de <strong>${{ number_format($amount_cop, 0, ',', '.') }} COP</strong> no pudo procesarse{{ $failure_status ? ' ('.$failure_status.')' : '' }}.
                                Intenta de nuevo con otro medio de pago para no perder acceso a tu POS.
                            @endif
                        </p>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:{{ $succeeded ? '#059669' : '#dc2626' }};color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            {{ $succeeded ? 'Ver mi suscripcion' : 'Intentar de nuevo' }}
                        </a>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => $succeeded ? '#047857' : '#b91c1c'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
