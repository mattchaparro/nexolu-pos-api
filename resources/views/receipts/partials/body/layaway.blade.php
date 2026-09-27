@include('receipts.partials.header')

<div class="title">Comprobante de apartado</div>
<table>
    <tr>
        <td>Apartado</td>
        <td class="right">{{ $invoiceNumber }}</td>
    </tr>
    <tr>
        <td>Fecha</td>
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
    <tbody>
        @foreach ($layaway->items as $item)
            <tr>
                <td colspan="2" class="item-name">{{ $item->product?->name ?: 'Producto eliminado' }}</td>
            </tr>
            <tr>
                <td class="item-detail">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }} x {{ \App\Support\ReceiptFormatter::money((float) $item->unit_price) }}</td>
                <td class="item-detail right">{{ \App\Support\ReceiptFormatter::money((float) $item->quantity * (float) $item->unit_price) }}</td>
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
