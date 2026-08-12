@php
    $logoAbsolutePath = $business->logo_path ? public_path($business->logo_path) : null;
@endphp
<div class="center">
    @if ($logoAbsolutePath && is_file($logoAbsolutePath))
        <img src="{{ $logoAbsolutePath }}" class="business-logo" alt="">
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
