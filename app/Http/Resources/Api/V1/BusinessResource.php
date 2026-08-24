<?php

namespace App\Http\Resources\Api\V1;

use App\Support\NotificationTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            'instagram_handle' => $this->instagram_handle,
            'tiktok_handle' => $this->tiktok_handle,
            'nit' => $this->nit,
            'address' => $this->address,
            'logo_path' => $this->logo_path,
            'ticket_paper_width' => $this->ticket_paper_width,
            'invoice_prefix' => $this->invoice_prefix,
            'ticket_header_tagline' => $this->ticket_header_tagline,
            'ticket_thanks_message' => $this->ticket_thanks_message,
            'ticket_footer_text' => $this->ticket_footer_text,
            'delivery_enabled' => $this->delivery_enabled,
            'delivery_fee' => $this->delivery_fee,
            'charges' => $this->chargesConfig(),
            'payment_methods' => $this->paymentMethods(),
            'low_stock_alert_threshold' => $this->low_stock_alert_threshold,
            'low_stock_email_enabled' => $this->low_stock_email_enabled,
            'low_stock_email' => $this->low_stock_email,
            'low_stock_snoozed_until' => $this->low_stock_snoozed_until,
            // null = todas las categorias; array de ids = lista restringida
            // (ver Product::forLayaway() y ProductController::sellable()).
            'layaway_allowed_category_ids' => $this->layaway_allowed_category_ids,
            'email_header_color' => $this->email_config['header_color'] ?? null,
            'email_footer_text' => $this->email_config['footer_text'] ?? null,
            'email_whatsapp_cta' => $this->email_whatsapp_cta,
            'notification_preferences' => $this->notification_preferences,
            // Siempre resuelto con los defaults de NotificationTypes::DEFAULT_HOURS
            // ya aplicados - el frontend no necesita conocerlos, solo
            // mostrar/editar esta hora y mandar de vuelta lo que el usuario
            // cambie (ver Business::notificationHour(), mismo criterio de
            // "el backend resuelve, el frontend no reimplementa" que
            // resolved_features mas abajo).
            'notification_schedule' => array_merge(
                NotificationTypes::DEFAULT_HOURS,
                $this->notification_schedule ?? [],
            ),
            // Configuracion del formulario de "Nueva orden de servicio" (ver
            // ServiceOrderFormView.vue en el frontend) - si el negocio quiere
            // que se pueda elegir un servicio del catalogo, y con que nombre
            // pre-llenar el campo al crear una orden nueva.
            'service_orders_show_catalog' => $this->service_orders_show_catalog,
            'service_orders_default_service_name' => $this->service_orders_default_service_name,
            'feature_flags' => $this->feature_flags,
            // Las 20 banderas del catalogo YA resueltas (ver
            // Business::resolvedFeatures()) - la fuente que el frontend debe
            // leer para "que tiene encendido este negocio" en vez de
            // replicar la logica de resolucion de feature_flags/plan por su
            // cuenta (bug real encontrado: el frontend asumia habilitada
            // cualquier clave ausente en un feature_flags no vacio, en vez
            // de resolverla con el default del plan como hace el backend).
            // feature_flags crudo se conserva arriba solo para las
            // pantallas de SuperAdmin que lo editan directamente.
            'resolved_features' => $this->resolvedFeatures(),
            // Computado en el modelo (ver Business::hasFeature()) en vez de
            // que el frontend replique la logica de flags/plan por su
            // cuenta - un negocio con feature_flags=null habilita todo por
            // retrocompatibilidad, algo que el frontend no puede saber solo
            // mirando el JSON de feature_flags.
            'can_access_purchases' => $this->canAccessPurchases(),
            // Mismo motivo que can_access_purchases: la pestaña "Servicios"
            // del hub de Catalogo (productos con is_service=true) depende
            // directo del feature flag 'services', sin permiso adicional -
            // se administran con los mismos permisos inventory.* que Productos.
            // Este mismo flag tambien gatea Ordenes de servicio (feature:
            // services + permission:appointments.manage en routes/api.php).
            'can_access_services' => $this->hasFeature('services'),
            // Agenda/citas (feature:scheduling + permission:appointments.manage)
            // es un feature independiente de 'services': un negocio puede
            // vender servicios sin necesitar calendario (ej. reparaciones a
            // domicilio) o al reves - nunca estuvieron atados en legacy, asi
            // que tampoco deben estarlo aca.
            'can_access_scheduling' => $this->hasFeature('scheduling'),
            // Modulo de Apartados (feature:layaway + permission:layaways.manage).
            'can_access_layaways' => $this->hasFeature('layaway'),
            'subscription_plan' => $this->subscription_plan,
            'subscription_status' => $this->subscriptionStatus(),
            'active' => $this->active,
        ];
    }
}
