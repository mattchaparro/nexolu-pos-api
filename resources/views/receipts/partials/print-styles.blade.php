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

    {{--
        Tipografia del tiquete termico del legacy (invoices/thermal.blade.php
        en pos-saas), que es con lo que los negocios migrados venian
        imprimiendo: Courier en negrita y negro puro. La termica no tiene
        grises - un #888 sale punteado y tenue - y con 12-13px de peso normal
        el recibo quedaba ilegible (reporte de Central Cell al migrar).
    --}}
    @php($narrow = $paperWidthMm === 58)
    body {
        font-family: 'Courier New', monospace;
        font-size: 15px;
        font-weight: 600;
        line-height: 1.35;
        color: #000;
        margin: 0 auto;
        padding: 6mm {{ $narrow ? 3 : 4 }}mm;
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
        font-weight: 800;
    }

    .muted {
        color: #000;
    }

    .business-logo {
        display: block;
        max-width: {{ $narrow ? 30 : 44 }}mm;
        max-height: 22mm;
        margin: 0 auto 2mm;
    }

    .business-name {
        font-size: {{ $narrow ? 16 : 19 }}px;
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: 0.02em;
    }

    .business-meta {
        font-size: 14px;
        line-height: 1.35;
    }

    .divider {
        border-top: 1px dashed #000;
        margin: 9px 0;
    }

    .title {
        font-size: 15px;
        font-weight: 800;
        text-align: center;
        margin: 2mm 0;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    {{-- En 58mm caben ~23 caracteres a 14px: un precio de celular en las dos
         columnas ("1 x $1.250.000  $1.250.000") partia la fila; a 13px cabe,
         y el monto de la derecha nunca se parte. --}}
    td {
        font-size: {{ $narrow ? 13 : 14 }}px;
        vertical-align: top;
    }

    td.right {
        white-space: nowrap;
        width: 1%;
        padding-left: {{ $narrow ? 1 : 2 }}mm;
    }

    .items-table td {
        padding: 0.5mm 0;
    }

    .items-table .item-name {
        font-size: {{ $narrow ? 14 : 15 }}px;
        font-weight: 800;
        line-height: 1.25;
        padding-top: 1.5mm;
    }

    .items-table .item-detail {
        font-size: {{ $narrow ? 13 : 14 }}px;
    }

    .totals-table td {
        padding: 0.8mm 0;
    }

    .totals-table .grand-total td {
        font-size: {{ $narrow ? 18 : 20 }}px;
        font-weight: 800;
        letter-spacing: 0.02em;
        border-top: 1px solid #000;
        padding-top: 2mm;
    }

    .footer-text {
        font-size: {{ $narrow ? 12 : 13 }}px;
        line-height: 1.4;
        white-space: pre-wrap;
        margin-top: 2mm;
    }

    .branding {
        font-size: 11px;
        font-weight: normal;
        color: #555;
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
