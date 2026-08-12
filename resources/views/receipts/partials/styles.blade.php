<style>
    @page {
        margin: 0;
        size: {{ $paperWidthMm }}mm 297mm;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9px;
        color: #141414;
        margin: 0;
        padding: 6mm 4mm;
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
        font-size: 12px;
        font-weight: bold;
    }

    .business-meta {
        font-size: 8px;
        color: #555;
        line-height: 1.4;
    }

    .divider {
        border-top: 1px dashed #999;
        margin: 2.5mm 0;
    }

    .title {
        font-size: 10px;
        font-weight: bold;
        text-align: center;
        margin: 1.5mm 0;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    .items-table th {
        font-size: 8px;
        text-align: left;
        border-bottom: 1px solid #ccc;
        padding: 1mm 0;
    }

    .items-table td {
        font-size: 8.5px;
        padding: 1mm 0;
        vertical-align: top;
    }

    .totals-table td {
        font-size: 9px;
        padding: 0.5mm 0;
    }

    .totals-table .grand-total td {
        font-size: 11px;
        font-weight: bold;
        border-top: 1px solid #333;
        padding-top: 1.5mm;
    }

    .footer-text {
        font-size: 8px;
        color: #666;
        margin-top: 2mm;
    }

    .branding {
        font-size: 7px;
        color: #aaa;
        margin-top: 3mm;
    }
</style>
