<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dejaste algo en tu carrito - {{ $store_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">

                <tr>
                    <td style="padding:28px 28px 24px;background:#0f172a;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.75;font-weight:600;">Tu carrito te espera</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">@if($greeting_name){{ $greeting_name }}, dejaste algo pendiente @else Dejaste algo en tu carrito @endif</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.85;">Lo guardamos por ti. Puedes terminar tu compra cuando quieras.</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 28px 6px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                            @foreach($items as $item)
                            <tr>
                                <td style="padding:8px 0;font-size:14px;color:#374151;border-bottom:1px solid #f1f5f9;">
                                    {{ $item['quantity'] ?? 1 }}× {{ $item['name'] ?? 'Producto' }}
                                </td>
                                <td align="right" style="padding:8px 0;font-size:14px;color:#0f172a;border-bottom:1px solid #f1f5f9;white-space:nowrap;">
                                    ${{ number_format((float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 1), 0, ',', '.') }}
                                </td>
                            </tr>
                            @endforeach
                            <tr>
                                <td style="padding:12px 0 0;font-size:15px;font-weight:700;color:#0f172a;">Total</td>
                                <td align="right" style="padding:12px 0 0;font-size:15px;font-weight:700;color:#0f172a;">${{ number_format($subtotal, 0, ',', '.') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 28px 28px;" align="center">
                        <a href="{{ $recovery_url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:13px 28px;border-radius:10px;font-size:15px;font-weight:700;">Terminar mi compra</a>
                        {{-- El enlace vence: un correo se reenvia y se archiva, asi que
                             no puede llevar una llave permanente al carrito. --}}
                        <p style="margin:14px 0 0;font-size:11px;color:#94a3b8;">Este enlace funciona durante las próximas 48 horas.</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 28px 24px;">
                        <p style="margin:0;font-size:11px;color:#94a3b8;text-align:center;">Te escribimos una sola vez por este carrito. Si ya compraste o no te interesa, puedes ignorar este mensaje.</p>
                    </td>
                </tr>

            </table>
            <p style="margin:16px 0 0;font-size:11px;color:#94a3b8;">{{ $store_name }}</p>
        </td>
    </tr>
</table>
</body>
</html>
