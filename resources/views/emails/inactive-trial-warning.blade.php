<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu cuenta de Nexolú POS será eliminada pronto</title>
</head>
<body style="margin:0;padding:0;background:#fef2f2;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fef2f2;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #fecaca;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#dc2626;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Cuenta inactiva</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#b91c1c;font-weight:600;">Hola, {{ $owner_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Tu periodo de prueba de <strong>{{ $business_name }}</strong> venció el
                            <strong>{{ $trial_expired_at }}</strong> y no has activado un plan pago. Por
                            política de retención de datos, tu cuenta y toda su información
                            (productos, ventas, clientes) será eliminada pronto si no la reactivas.
                        </p>
                        <p style="margin:12px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Si quieres seguir usando Nexolú, activa un plan ahora mismo o escríbenos
                            por WhatsApp y te ayudamos.
                        </p>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;margin:0 6px 8px;">
                            Activar mi plan
                        </a>
                        <a href="{{ $whatsapp_url }}"
                           style="display:inline-block;background:#ffffff;color:#b91c1c;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;border:1px solid #fecaca;margin:0 6px 8px;">
                            Escribir por WhatsApp
                        </a>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#b91c1c'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
