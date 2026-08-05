{{-- Portado del legacy tal cual: el diseño ya esta validado con usuarios reales (verde Nexolu, tarjeta de credenciales, boton grande de CTA). --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu acceso al POS</title>
</head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #bbf7d0;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#16a34a;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Bienvenido al equipo</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.9;">Se ha creado un acceso para ti en el sistema POS.</p>
                    </td>
                </tr>

                {{-- Greeting --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#15803d;font-weight:600;">Hola, {{ $user_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            El administrador de <strong>{{ $business_name }}</strong> te ha creado un usuario en el POS.
                            Aqui estan tus credenciales de acceso:
                        </p>
                    </td>
                </tr>

                {{-- Credentials card --}}
                <tr>
                    <td style="padding:12px 28px 20px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                               style="background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0;overflow:hidden;">
                            <tr>
                                <td style="padding:14px 18px;border-bottom:1px solid #bbf7d0;">
                                    <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#15803d;font-weight:700;">Correo electronico</p>
                                    <p style="margin:4px 0 0;font-size:15px;font-weight:700;color:#0f172a;font-family:monospace;">{{ $user_email }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:14px 18px;border-bottom:1px solid #bbf7d0;">
                                    <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#15803d;font-weight:700;">Contrasena</p>
                                    <p style="margin:4px 0 0;font-size:18px;font-weight:800;color:#0f172a;font-family:monospace;letter-spacing:2px;">{{ $plain_password }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:14px 18px;">
                                    <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#15803d;font-weight:700;">Rol asignado</p>
                                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#0f172a;">{{ $role_label }}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:0 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ingresar al POS
                        </a>
                        <p style="margin:14px 0 0;font-size:12px;color:#6b7280;">Te recomendamos cambiar tu contrasena despues de ingresar por primera vez.</p>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#16a34a'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
