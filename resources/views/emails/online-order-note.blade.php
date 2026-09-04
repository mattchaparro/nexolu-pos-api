<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sobre tu pedido #{{ $order->number }} - {{ $store_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">

                <tr>
                    <td style="padding:28px 28px 24px;background:#0f172a;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.75;font-weight:600;">Mensaje de la tienda</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">Sobre tu pedido #{{ $order->number }}</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.85;">{{ $store_name }} te escribió.</p>
                    </td>
                </tr>

                {{-- El texto es de una persona, no del sistema: se muestra tal
                     cual lo escribió, respetando sus saltos de línea. --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <p style="margin:0;font-size:15px;line-height:1.6;color:#334155;white-space:pre-line;">{{ $body }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 28px 24px;text-align:center;">
                        <a href="{{ $tracking_url }}"
                           style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ver mi pedido
                        </a>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#0f172a'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
