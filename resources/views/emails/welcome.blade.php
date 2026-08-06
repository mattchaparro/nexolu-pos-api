<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido a Nexolú</title>
</head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #bbf7d0;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#16a34a;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Cuenta creada</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">Bienvenido a Nexolú</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.9;">{{ $business_name }} ya esta lista para operar.</p>
                    </td>
                </tr>

                {{-- Greeting --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;color:#15803d;font-weight:600;">Hola, {{ $owner_name }}!</p>
                        <p style="margin:8px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Creaste tu cuenta en Nexolú para <strong>{{ $business_name }}</strong>. Ya puedes
                            registrar productos, hacer ventas y llevar el control de tu caja desde el POS.
                        </p>
                        @if($trial_ends_at)
                        <p style="margin:12px 0 0;font-size:14px;color:#374151;line-height:1.6;">
                            Tu periodo de prueba dura hasta el <strong>{{ $trial_ends_at->translatedFormat('d \d\e F \d\e Y') }}</strong>,
                            sin necesidad de tarjeta de credito.
                        </p>
                        @endif
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:12px 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ir a mi POS
                        </a>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#16a34a'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
