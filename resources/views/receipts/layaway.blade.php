<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    @include('receipts.partials.styles')
</head>
<body>
@include('receipts.partials.header')

<div class="title">Comprobante de apartado</div>
<table>
    <tr>
        <td>Apartado: {{ $invoiceNumber }}</td>
        <td class="right">{{ $issuedAt }}</td>
    </tr>
</table>

<div class="divider"></div>
<div>Cliente: {{ $layaway->customer_name ?: 'Consumidor final' }}</div>
@if ($layaway->customer_phone)
    <div>Teléfono: {{ $layaway->customer_phone }}</div>
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
        @foreach ($layaway->items as $item)
            <tr>
                <td>{{ $item->product?->name ?: 'Producto eliminado' }}</td>
                <td class="right">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }}</td>
                <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $item->quantity * (float) $item->unit_price) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="divider"></div>
<table class="totals-table">
    <tr>
        <td>Total</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $layaway->total) }}</td>
    </tr>
    <tr>
        <td>Abonado</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $layaway->paid) }}</td>
    </tr>
    <tr class="grand-total">
        <td>SALDO PENDIENTE</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $layaway->balance) }}</td>
    </tr>
</table>

@if ($layaway->payments->isNotEmpty())
    <div class="divider"></div>
    <div class="bold">Abonos</div>
    <table class="items-table">
        <tbody>
            @foreach ($layaway->payments as $payment)
                <tr>
                    <td>{{ $payment->created_at->format('d/m/Y') }}</td>
                    <td>{{ \App\Support\ReceiptFormatter::paymentMethodLabel($business, $payment->payment_method) }}</td>
                    <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $payment->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@include('receipts.partials.footer')
</body>
</html>
