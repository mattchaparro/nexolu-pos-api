<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu {{ $is_paid_subscription ? 'suscripcion' : 'prueba gratuita' }} vence pronto</title>
</head>
<body style="margin:0;padding:0;background:#fffbeb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fffbeb;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #fde68a;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#d97706;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">
                            {{ $is_paid_subscription ? 'Suscripcion por vencer' : 'Prueba gratuita por vencer' }}
                        </p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#b45309;font-weight:600;">Hola, {{ $owner_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            @if($is_paid_subscription)
                                Tu suscripcion de <strong>{{ $business_name }}</strong> vence el
                                <strong>{{ $expires_at->translatedFormat('d \d\e F \d\e Y') }}</strong>.
                                Renuevala para que tu negocio no pierda acceso al POS.
                            @else
                                Tu periodo de prueba de <strong>{{ $business_name }}</strong> vence el
                                <strong>{{ $expires_at->translatedFormat('d \d\e F \d\e Y') }}</strong>.
                                Activa un plan para seguir usando Nexolú sin interrupciones.
                            @endif
                        </p>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:#d97706;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ir a mi POS
                        </a>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#b45309'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
