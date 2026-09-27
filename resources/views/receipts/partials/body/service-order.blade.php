@include('receipts.partials.header')

<div class="title">Comprobante de orden de servicio</div>
<table>
    <tr>
        <td>Orden</td>
        <td class="right">{{ $invoiceNumber }}</td>
    </tr>
    <tr>
        <td>Fecha</td>
        <td class="right">{{ $issuedAt }}</td>
    </tr>
</table>

<div class="divider"></div>
<div><span class="bold">Servicio:</span> {{ $serviceOrder->service_name }}</div>
@php
    // Texto de la orden; las migradas del legacy solo tienen la ficha.
    $clientName = $serviceOrder->client_name ?? $serviceOrder->client?->name;
    $clientPhone = $serviceOrder->client_phone ?? $serviceOrder->client?->phone;
@endphp
@if ($clientName)
    <div>Cliente: {{ $clientName }}</div>
    @if ($clientPhone)
        <div>Teléfono: {{ $clientPhone }}</div>
    @endif
@endif

@if ($serviceOrder->items->isNotEmpty())
    <div class="divider"></div>
    <table class="items-table">
        <tbody>
            @foreach ($serviceOrder->items as $item)
                <tr>
                    <td colspan="2" class="item-name">{{ $item->name }}</td>
                </tr>
                <tr>
                    <td class="item-detail">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }} x {{ \App\Support\ReceiptFormatter::money((float) $item->unit_price) }}</td>
                    <td class="item-detail right">{{ \App\Support\ReceiptFormatter::money((float) $item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="divider"></div>
<table class="totals-table">
    <tr>
        <td>Total</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $serviceOrder->total) }}</td>
    </tr>
    <tr>
        <td>Abonado</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $serviceOrder->amount_paid) }}</td>
    </tr>
    <tr class="grand-total">
        <td>SALDO PENDIENTE</td>
        <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $serviceOrder->balance) }}</td>
    </tr>
</table>

@if ($serviceOrder->payments->isNotEmpty())
    <div class="divider"></div>
    <div class="bold">Abonos</div>
    <table class="items-table">
        <tbody>
            @foreach ($serviceOrder->payments as $payment)
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
