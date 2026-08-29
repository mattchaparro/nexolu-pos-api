<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo pedido #{{ $order->number }} - {{ $business_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #bbf7d0;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#15803d;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Tienda online</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">Pedido #{{ $order->number }}</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.9;">
                            {{ $order->customer_name }} pidió {{ $items->sum('quantity') }} {{ $items->sum('quantity') === 1 ? 'artículo' : 'artículos' }}.
                        </p>
                    </td>
                </tr>

                {{-- Articulos --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
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
                                <td style="padding:8px 0;font-size:16px;font-weight:800;color:#15803d;text-align:right;">
                                    ${{ number_format($order->total, 0, ',', '.') }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Entrega y contacto --}}
                <tr>
                    <td style="padding:8px 28px 4px;">
                        <p style="margin:0;font-size:14px;color:#374151;"><strong>{{ $order->customer_name }}</strong> &middot; {{ $order->customer_phone }}</p>
                        <p style="margin:4px 0 0;font-size:14px;color:#6b7280;">
                            @if($order->is_pickup)
                                Recoge en la tienda.
                            @else
                                {{ $order->shipping_address }}@if($order->shipping_city), {{ $order->shipping_city }}@endif
                            @endif
                        </p>
                        @if($order->shipping_notes)
                        <p style="margin:4px 0 0;font-size:13px;color:#94a3b8;font-style:italic;">“{{ $order->shipping_notes }}”</p>
                        @endif
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:20px 28px 24px;text-align:center;">
                        <a href="{{ $app_url }}/pedidos-online"
                           style="display:inline-block;background:#15803d;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ver el pedido
                        </a>
                        <p style="margin:12px 0 0;font-size:12px;color:#94a3b8;">
                            El pedido aparta el inventario hasta que lo confirmes o venza.
                        </p>
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#15803d'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
