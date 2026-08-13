<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $business_name }}</title>
</head>
<body style="margin:0;padding:0;background:#eef2ff;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2ff;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #c7d2fe;">

                <tr>
                    <td style="padding:28px 28px 24px;background:#4338ca;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Nexolú</p>
                        <h1 style="margin:8px 0 0;font-size:22px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 24px;">
                        <p style="margin:0;font-size:14px;color:#374151;line-height:1.7;">{!! nl2br(e($body)) !!}</p>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#4338ca'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
