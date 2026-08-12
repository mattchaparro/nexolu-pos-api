<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document_title }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:24px 28px 20px;background:#4f46e5;color:#ffffff;">
                        <h1 style="margin:0;font-size:20px;line-height:1.3;font-weight:800;">{{ $document_title }}</h1>
                        <p style="margin:6px 0 0;font-size:13px;opacity:.9;">{{ $business_name }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 28px;">
                        <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;">
                            Adjuntamos tu comprobante en PDF.
                        </p>
                    </td>
                </tr>
                @include('emails.partials.footer', ['accent' => '#4f46e5'])
            </table>
        </td>
    </tr>
</table>
</body>
</html>
