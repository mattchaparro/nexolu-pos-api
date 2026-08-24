<style>
    {{--
        size acepta <length>{1,2} o la palabra clave "auto" sola, nunca una
        medida combinada con "auto" (ver CSS Paged Media Module Level 3) -
        "Nmm auto" es sintaxis invalida, el navegador la descarta entera y
        cae al tamaño de pagina por defecto del sistema (Carta), que es
        exactamente el bug reportado: el ancho configurado (58/80mm) se
        ignoraba al imprimir de verdad, no solo en la vista previa en
        pantalla. 297mm (altura generosa, igual que ya usa styles.blade.php
        para el PDF descargable) evita la palabra "auto" sin asumir un alto
        real - el recibo real es mucho mas corto y termina bien antes de
        esa altura, no genera una segunda pagina en blanco.
    --}}
    @page {
        margin: 0;
        size: {{ $paperWidthMm }}mm 297mm;
    }

    * {
        box-sizing: border-box;
    }

    html {
        background: #e2e8f0;
    }

    body {
        font-family: 'DejaVu Sans', 'Segoe UI', sans-serif;
        font-size: 13px;
        color: #141414;
        margin: 0 auto;
        padding: 6mm 4mm;
        max-width: {{ $paperWidthMm }}mm;
        background: #fff;
    }

    .center {
        text-align: center;
    }

    .right {
        text-align: right;
    }

    .bold {
        font-weight: bold;
    }

    .muted {
        color: #888;
    }

    .business-logo {
        display: block;
        max-width: 28mm;
        max-height: 18mm;
        margin: 0 auto 2mm;
    }

    .business-name {
        font-size: 16px;
        font-weight: bold;
    }

    .business-meta {
        font-size: 11px;
        color: #555;
        line-height: 1.4;
    }

    .divider {
        border-top: 1px dashed #999;
        margin: 3mm 0;
    }

    .title {
        font-size: 13px;
        font-weight: bold;
        text-align: center;
        margin: 2mm 0;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    .items-table th {
        font-size: 11px;
        text-align: left;
        border-bottom: 1px solid #ccc;
        padding: 1.5mm 0;
    }

    .items-table td {
        font-size: 12px;
        padding: 1.5mm 0;
        vertical-align: top;
    }

    .totals-table td {
        font-size: 12px;
        padding: 0.8mm 0;
    }

    .totals-table .grand-total td {
        font-size: 14px;
        font-weight: bold;
        border-top: 1px solid #333;
        padding-top: 2mm;
    }

    .footer-text {
        font-size: 11px;
        color: #666;
        margin-top: 2mm;
    }

    .branding {
        font-size: 10px;
        color: #aaa;
        margin-top: 3mm;
    }

    .print-actions {
        display: flex;
        flex-direction: column;
        gap: 2mm;
        margin-top: 5mm;
    }

    .print-actions button {
        font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        font-size: 13px;
        font-weight: 600;
        padding: 3mm 4mm;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }

    .print-actions .btn-print {
        background: #4338ca;
        color: #fff;
    }

    .print-actions .btn-close {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    @media print {
        html {
            background: #fff;
        }

        body {
            margin: 0;
        }

        .no-print {
            display: none !important;
        }
    }
</style>
