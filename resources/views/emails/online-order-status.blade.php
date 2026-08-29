<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $copy['subject'] }} - {{ $store_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">

                <tr>
                    <td style="padding:28px 28px 24px;background:#0f172a;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.75;font-weight:600;">{{ $copy['eyebrow'] }}</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $copy['headline'] }}</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.85;">{{ $copy['body'] }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 28px 6px;">
                        <p style="margin:0 0 10px;font-size:13px;color:#64748b;">Pedido <strong style="color:#0f172a;">#{{ $order->number }}</strong> en {{ $store_name }}</p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                            @foreach($items as $item)
                            <tr>
                                <td style="padding:8px 0;font-size:14px;color:#374151;border-bottom:1px solid #f1f5f9;">
                                    {{ $item->quantity }}× {{ $item->product_name }}@if($item->variant_label) <span style="color:#94a3b8;">({{ $item->variant_label }})</span>@endif
                                </td>
                                <td style="padding:8px 0;font-size:14px;color:#0f172a;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">
                                    ${{ number_format($item->subtotal, 0, ',', '.') }}
                                </td>
                            </tr>
                            @endforeach
                            <tr>
                                <td style="padding:8px 0;font-size:13px;color:#6b7280;">Envío</td>
                                <td style="padding:8px 0;font-size:13px;color:#6b7280;text-align:right;">
                                    {{ $order->shipping_fee > 0 ? '$'.number_format($order->shipping_fee, 0, ',', '.') : 'Gratis' }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;font-size:16px;font-weight:800;color:#0f172a;">Total</td>
                                <td style="padding:8px 0;font-size:16px;font-weight:800;color:#0f172a;text-align:right;">
                                    ${{ number_format($order->total, 0, ',', '.') }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px 4px;">
                        <p style="margin:0;font-size:14px;color:#6b7280;">
                            @if($order->is_pickup)
                                Recoges en la tienda.
                            @else
                                Entrega en {{ $order->shipping_address }}@if($order->shipping_city), {{ $order->shipping_city }}@endif
                            @endif
                        </p>
                    </td>
                </tr>

                {{-- El enlace de seguimiento es la única llave que tiene un
                     comprador sin cuenta para volver a su pedido. --}}
                <tr>
                    <td style="padding:20px 28px 24px;text-align:center;">
                        <a href="{{ $tracking_url }}"
                           style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ver mi pedido
                        </a>
                        <p style="margin:12px 0 0;font-size:12px;color:#94a3b8;">Guarda este enlace para consultarlo cuando quieras.</p>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#0f172a'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
