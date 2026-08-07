{{--
    Uso: @include('emails.partials.footer', ['footer_text' => $email_config['footer_text'] ?? null, 'accent' => '#0369a1'])
    Parametros opcionales:
      $footer_text  — texto personalizado del negocio (puede ser null)
      $accent       — color del enlace de Nexolu (default #0369a1)
--}}
<tr>
    <td style="padding:0 28px;">
        <hr style="border:none;border-top:1px solid #e2e8f0;margin:0;">
    </td>
</tr>
<tr>
    <td style="padding:12px 28px 16px;text-align:center;">

        @if(!empty($footer_text))
        <p style="margin:0 0 6px;font-size:12px;color:#374151;font-family:Arial,sans-serif;">{{ $footer_text }}</p>
        @endif

        <p style="margin:0;font-size:11px;color:#94a3b8;font-family:Arial,sans-serif;">
            <a href="https://nexolu.co" style="color:{{ $accent ?? '#0369a1' }};text-decoration:none;font-weight:600;">Nexolú - Software para Negocios</a>
        </p>
    </td>
</tr>
