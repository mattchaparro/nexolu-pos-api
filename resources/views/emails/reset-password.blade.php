<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablece tu contraseña</title>
</head>
<body style="margin:0;padding:0;background:#eef2ff;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2ff;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #c7d2fe;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#4f46e5;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Seguridad de tu cuenta</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">Restablece tu contraseña</h1>
                    </td>
                </tr>

                {{-- Greeting --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#4338ca;font-weight:600;">Hola, {{ $user_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Recibimos una solicitud para restablecer tu contraseña en Nexolú. Si fuiste tú,
                            haz clic en el botón de abajo para elegir una nueva.
                        </p>
                        <p style="margin:12px 0 0;font-size:13px;color:#6b7280;line-height:1.6;">
                            Si no pediste esto, puedes ignorar este correo - tu contraseña actual sigue funcionando.
                        </p>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 8px;text-align:center;">
                        <a href="{{ $reset_url }}"
                           style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Restablecer contraseña
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 28px 24px;text-align:center;">
                        <p style="margin:0;font-size:12px;color:#6b7280;">Este enlace expira en {{ $expire_minutes }} minutos.</p>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#4f46e5'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
