<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    @include('receipts.partials.styles')
</head>
<body>
@include('receipts.partials.header')

<div class="title">Recibo de venta</div>
<table>
    <tr>
        <td>Factura: {{ $invoiceNumber }}</td>
        <td class="right">{{ $issuedAt }}</td>
    </tr>
</table>

@if ($sale->customer_name || $sale->customer_phone)
    <div class="divider"></div>
    <div>Cliente: {{ $sale->customer_name ?: 'Consumidor final' }}</div>
    @if ($sale->customer_phone)
        <div>Teléfono: {{ $sale->customer_phone }}</div>
    @endif
@endif

<div class="divider"></div>
<table class="items-table">
    <thead>
        <tr>
            <th style="width: 50%;">Producto</th>
            <th style="width: 15%;" class="right">Cant.</th>
            <th style="width: 35%;" class="right">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sale->items as $item)
            <tr>
                <td>{{ $item->product?->name ?: 'Producto eliminado' }}</td>
                <td class="right">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }}</td>
                <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $item->subtotal) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="divider"></div>
<table class="totals-table">
    @if ((float) $sale->cart_discount_amount > 0)
        <tr>
            <td>Descuento</td>
            <td class="right">-{{ \App\Support\ReceiptFormatter::money((float) $sale->cart_discount_amount) }}</td>
        </tr>
    @endif
    @if ((float) $sale->service_charge_amount > 0)
        <tr>
            <td>Servicio</td>
            <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $sale->service_charge_amount) }}</td>
        </tr>
    @endif
    @if ((float) $sale->ipoconsumo_amount > 0)
        <tr>
            <td>Impoconsumo</td>
            <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $sale->ipoconsumo_amount) }}</td>
        </tr>
    @endif
    @if ($sale->is_delivery && (float) $sale->delivery_fee > 0)
        <tr>
            <td>Domicilio</td>
            <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $sale->delivery_fee) }}</td>
        </tr>
    @endif
    <tr>
        <td>Medio de pago</td>
        <td class="right">
            {{ $sale->is_non_revenue ? 'Cortesía' : \App\Support\ReceiptFormatter::paymentMethodLabel($business, $sale->payment_method) }}
        </td>
    </tr>
    <tr class="grand-total">
        <td>TOTAL</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $sale->total) }}</td>
    </tr>
</table>

@include('receipts.partials.footer')
</body>
</html>
