<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas silenciadas - {{ $business_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:48px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;text-align:center;">
                <tr>
                    <td style="padding:36px 28px;">
                        <p style="margin:0 0 12px;font-size:40px;">🔕</p>
                        <h1 style="margin:0 0 8px;font-size:20px;font-weight:800;">Alertas silenciadas</h1>
                        <p style="margin:0;font-size:14px;color:#475569;">
                            No te avisaremos de inventario bajo en <strong>{{ $business_name }}</strong>
                            durante {{ $days }} {{ $days === 1 ? 'dia' : 'dias' }}, hasta el {{ $until }}.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
