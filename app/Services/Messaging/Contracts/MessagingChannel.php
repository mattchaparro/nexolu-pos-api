<?php

namespace App\Services\Messaging\Contracts;

/**
 * Envio de mensajes salientes, sin atarse a un proveedor. Hoy la unica
 * implementacion habla directo con WhatsApp Cloud API
 * (App\Services\WhatsApp\WhatsAppCloudClient); mas adelante el envio real se
 * mueve a un servicio aparte (Nexolu Communications, mismo patron que
 * Nexolu Payments Core / Nexolu IA Core) y esa migracion es escribir una
 * clase nueva que implemente esto y cambiar el binding en
 * AppServiceProvider - nada que llama a este contrato (jobs, comandos)
 * deberia necesitar tocarse.
 *
 * A proposito NO cubre el lado entrante (webhooks, parseo de mensajes): el
 * formato de un webhook es propio de cada proveedor y no tiene sentido
 * abstraerlo antes de conocer el del proveedor que lo reemplace.
 */
interface MessagingChannel
{
    /** Hay credenciales para enviar. Si no, el emisor real no debe usarse. */
    public function isConfigured(): bool;

    /**
     * Texto libre. En WhatsApp, solo se entrega dentro de la ventana de 24h
     * desde el ultimo mensaje del usuario - fuera de ella, usar sendTemplate().
     */
    public function sendText(string $to, string $body): bool;

    /**
     * Mensaje de plantilla: via para mensajes iniciados por el negocio (OTP,
     * alertas, notificaciones proactivas), sin depender de una ventana de
     * conversacion abierta.
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(string $to, string $name, string $languageCode, array $components = []): bool;

    /**
     * Documento adjunto (hoy: comprobantes de venta/orden de servicio/
     * apartado, ver App\Jobs\SendReceiptJob). $documentUrl debe ser
     * publicamente alcanzable - quien reciba esto (Meta o Nexolu
     * Communications) lo descarga por su cuenta, no se le mandan los bytes.
     *
     * Igual que sendText(), esto solo entrega dentro de la ventana de 24h
     * desde el ultimo mensaje del destinatario. Para enviar un comprobante a
     * alguien que no le escribio antes al negocio hace falta una plantilla
     * aprobada con encabezado de tipo documento - todavia no existe una, se
     * agrega mas adelante sin tener que tocar este metodo (el nombre de la
     * plantilla se resolveria en la implementacion, no en el llamante).
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): bool;

    /**
     * Formulario nativo con datos prellenados, para confirmar un borrador de
     * escritura sin salir del canal de mensajeria.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendFlow(
        string $to,
        string $flowId,
        string $screen,
        string $bodyText,
        string $cta,
        array $data,
        string $flowToken,
    ): bool;

    /** Marca el mensaje entrante como leido y, si el canal lo soporta, activa el indicador de "escribiendo...". */
    public function markAsReadWithTyping(string $to, string $messageId): bool;
}
