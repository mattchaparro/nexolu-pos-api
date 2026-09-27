@include('receipts.partials.header')

<div class="title">Recibo de venta</div>
<table>
    <tr>
        <td>Factura</td>
        <td class="right">{{ $invoiceNumber }}</td>
    </tr>
    <tr>
        <td>Fecha</td>
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
{{-- Nombre en su propia fila y "cant x precio | subtotal" debajo, como el
     tiquete del legacy: con letra de tamaño legible una tabla de 3 columnas
     no cabe en 58mm (el precio de un celular parte la columna). --}}
<table class="items-table">
    <tbody>
        @foreach ($sale->items as $item)
            <tr>
                <td colspan="2" class="item-name">{{ $item->product?->name ?: 'Producto eliminado' }}</td>
            </tr>
            <tr>
                <td class="item-detail">{{ \App\Support\ReceiptFormatter::quantity((float) $item->quantity) }} x {{ \App\Support\ReceiptFormatter::money((float) $item->unit_price) }}</td>
                <td class="item-detail right">{{ \App\Support\ReceiptFormatter::money((float) $item->subtotal) }}</td>
            </tr>
            {{-- subtotal es bruto (cant x precio); el descuento de la linea va aparte y sin esta fila los items no cuadraban con el TOTAL. --}}
            @if ((float) $item->discount_amount > 0)
                <tr>
                    <td class="item-detail">Descuento</td>
                    <td class="item-detail right">-{{ \App\Support\ReceiptFormatter::money((float) $item->discount_amount) }}</td>
                </tr>
            @endif
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
        <td>Pago</td>
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
