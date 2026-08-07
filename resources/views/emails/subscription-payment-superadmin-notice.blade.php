<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $succeeded ? 'Pago aprobado' : 'Pago fallido' }}</title>
</head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f9fafb;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">

                {{-- Header --}}
                <tr>
                    <td style="padding:20px 24px;background:{{ $succeeded ? '#059669' : '#dc2626' }};color:#ffffff;">
                        <p style="margin:0;font-size:18px;font-weight:800;">
                            {{ $succeeded ? '✅ Pago aprobado y suscripcion activada' : '❌ Pago '.($failure_status ?? 'fallido') }}
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:20px 24px;">
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <tr><td style="padding:5px 0;color:#6b7280;width:160px;">Negocio</td><td style="font-weight:700;">{{ $business_name }} <span style="color:#9ca3af;font-weight:400;">(ID {{ $business_id }})</span></td></tr>
                            <tr><td style="padding:5px 0;color:#6b7280;">Referencia</td><td style="font-weight:600;">{{ $reference }}</td></tr>
                            <tr><td style="padding:5px 0;color:#6b7280;">Monto</td><td style="font-weight:600;">${{ number_format($amount_cop, 0, ',', '.') }} COP</td></tr>
                            @if($provider_transaction_id)
                            <tr><td style="padding:5px 0;color:#6b7280;">ID transaccion</td><td style="font-size:11px;">{{ $provider_transaction_id }}</td></tr>
                            @endif
                            @if($succeeded && $days_granted)
                            <tr><td style="padding:5px 0;color:#6b7280;">Dias activados</td><td style="font-weight:600;">{{ $days_granted }} dias</td></tr>
                            @endif
                        </table>
                        <div style="margin-top:16px;">
                            <a href="{{ $app_url }}"
                               style="display:inline-block;background:#1e293b;color:#fff;text-decoration:none;font-size:13px;font-weight:700;padding:10px 20px;border-radius:8px;">
                                Ver panel de SuperAdmin
                            </a>
                        </div>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
