@php
    // dompdf lee el logo desde el filesystem (path absoluto); la vista de
    // impresion en el navegador necesita una URL real - ver receipts/print/*
    // que pasa $logoSrc explicito con asset().
    $logoAbsolutePath = $business->logo_path ? public_path($business->logo_path) : null;
    $resolvedLogoSrc = $logoSrc ?? (($logoAbsolutePath && is_file($logoAbsolutePath)) ? $logoAbsolutePath : null);
@endphp
<div class="center">
    @if ($resolvedLogoSrc)
        <img src="{{ $resolvedLogoSrc }}" class="business-logo" alt="">
    @endif
    <div class="business-name">{{ $business->name ?: 'Negocio' }}</div>
    <div class="business-meta">
        @if ($business->nit)
            NIT: {{ $business->nit }}<br>
        @endif
        @if ($business->phone)
            Tel: {{ $business->phone }}<br>
        @endif
        @if ($business->address)
            {{ $business->address }}
        @endif
    </div>
    @if ($business->ticket_header_tagline)
        <div class="business-meta">{{ $business->ticket_header_tagline }}</div>
    @endif
</div>
<div class="divider"></div>
