<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerta de inventario bajo - {{ $business_name }}</title>
</head>
<body style="margin:0;padding:0;background:#fef2f2;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fef2f2;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #fecaca;">

                {{-- Header --}}
                <tr>
                    <td style="padding:28px 28px 24px;background:#dc2626;color:#ffffff;">
                        <p style="margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;font-weight:600;">Inventario bajo</p>
                        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;font-weight:800;">{{ $business_name }}</h1>
                        <p style="margin:8px 0 0;font-size:13px;opacity:.9;">
                            {{ $items->count() }} {{ $items->count() === 1 ? 'producto esta' : 'productos estan' }} por debajo del umbral configurado.
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:24px 28px 8px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                            <tr>
                                <td style="padding:6px 0;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;">Producto</td>
                                <td style="padding:6px 0;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;text-align:right;">Stock</td>
                                <td style="padding:6px 0;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;text-align:right;">Umbral</td>
                            </tr>
                            @foreach($items as $item)
                            <tr>
                                <td style="padding:8px 0;font-size:14px;color:#374151;border-bottom:1px solid #f1f5f9;">{{ $item['name'] }}</td>
                                <td style="padding:8px 0;font-size:14px;color:#dc2626;font-weight:700;border-bottom:1px solid #f1f5f9;text-align:right;">{{ rtrim(rtrim(number_format($item['stock'], 2), '0'), '.') }}{{ !empty($item['unit']) ? ' '.$item['unit'] : '' }}</td>
                                <td style="padding:8px 0;font-size:14px;color:#6b7280;border-bottom:1px solid #f1f5f9;text-align:right;">{{ $item['threshold'] }}</td>
                            </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:20px 28px 8px;text-align:center;">
                        <a href="{{ $app_url }}"
                           style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 28px;border-radius:8px;">
                            Ir a mi POS
                        </a>
                    </td>
                </tr>

                {{-- Silenciar --}}
                <tr>
                    <td style="padding:16px 28px 24px;text-align:center;">
                        <p style="margin:0 0 8px;font-size:12px;color:#94a3b8;">¿Ya lo tienes controlado? Silenciar estas alertas:</p>
                        @foreach($snooze_links as $days => $url)
                        <a href="{{ $url }}" style="display:inline-block;margin:0 4px;color:#64748b;text-decoration:underline;font-size:12px;">{{ $days }} {{ $days === 1 ? 'dia' : 'dias' }}</a>@if(!$loop->last)&middot;@endif
                        @endforeach
                    </td>
                </tr>

                @include('emails.partials.footer', ['footer_text' => null, 'accent' => '#b91c1c'])

            </table>
        </td>
    </tr>
</table>
</body>
</html>
