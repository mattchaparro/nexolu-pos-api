<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de servicio {{ $invoiceNumber }}</title>
    @include('receipts.partials.print-styles')
</head>
<body>
@include('receipts.partials.body.service-order')

<div class="no-print print-actions">
    <button type="button" class="btn-print" onclick="window.print()">Imprimir</button>
    <button type="button" class="btn-close" onclick="window.close()">Cerrar</button>
</div>

@if ($autoPrint)
    <script>window.print();</script>
@endif
</body>
</html>
