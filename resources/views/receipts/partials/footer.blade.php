<div class="divider"></div>
@if ($business->ticket_footer_text)
    <div class="footer-text center">{{ $business->ticket_footer_text }}</div>
@endif
<div class="center" style="font-style: italic; margin-top: 1.5mm;">
    {{ $business->ticket_thanks_message ?: 'Gracias por su compra.' }}
</div>
<div class="branding center">Generado por Nexolú - pos.nexolu.co</div>
