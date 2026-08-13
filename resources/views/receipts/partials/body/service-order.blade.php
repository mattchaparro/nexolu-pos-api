@include('receipts.partials.header')

<div class="title">Comprobante de orden de servicio</div>
<table>
    <tr>
        <td>Orden: {{ $invoiceNumber }}</td>
        <td class="right">{{ $issuedAt }}</td>
    </tr>
</table>

<div class="divider"></div>
<div><span class="bold">Servicio:</span> {{ $serviceOrder->service_name }}</div>
@if ($serviceOrder->client)
    <div>Cliente: {{ $serviceOrder->client->name }}</div>
    @if ($serviceOrder->client->phone)
        <div>Teléfono: {{ $serviceOrder->client->phone }}</div>
    @endif
@endif

@if ($serviceOrder->items->isNotEmpty())
    <div class="divider"></div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 50%;">Ítem</th>
                <th style="width: 15%;" class="right">Cant.</th>
                <th style="width: 35%;" class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($serviceOrder->items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td class="right">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }}</td>
                    <td class="right">{{ \App\Support\ReceiptFormatter::money((float) $item->subtotal) }}</td>
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
