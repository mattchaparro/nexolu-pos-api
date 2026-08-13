<?php

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\Business;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Alta de un negocio nuevo (self-service): crea el Business y su usuario
 * dueño en una sola transaccion. Es el unico punto de entrada para esto -
 * antes de este servicio, la unica forma de tener un negocio de prueba era
 * un tinker manual.
 */
class BusinessRegistrationService
{
    /**
     * @param  array{business_name: string, owner_name: string, email: string, password: string, phone?: ?string, whatsapp_number?: ?string, nit?: ?string, address?: ?string, setup_mode?: ?string, plan?: ?string, feature_flags?: ?array<string, bool>}  $data
     * @return array{business: Business, user: User}
     */
    public function register(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            // El self-registro (setup_mode) y el alta manual del superadmin
            // (plan directo) llegan al mismo resultado por dos caminos: uno
            // parte del tipo de negocio, el otro del plan comercial ya
            // decidido en una llamada de ventas.
            if (! empty($data['plan'])) {
                $plan = $data['plan'];
                $featureFlags = BusinessFeaturePresets::fromPlan($plan);

                // Wizard de registro publico: solo se puede APAGAR una
                // bandera que el plan trae encendida por defecto, nunca
                // prender una que el plan no incluye - eso solo se logra
                // subiendo de plan. Cualquier override de una clave que ya
                // esta apagada en el plan se ignora en silencio, aunque el
                // front no deberia enviarlo.
                if (! empty($data['feature_flags']) && is_array($data['feature_flags'])) {
                    foreach ($featureFlags as $key => $enabledByDefault) {
                        if ($enabledByDefault && array_key_exists($key, $data['feature_flags'])) {
                            $featureFlags[$key] = (bool) $data['feature_flags'][$key];
                        }
                    }
                }
            } else {
                $setupMode = $data['setup_mode'] ?? BusinessFeaturePresets::SETUP_RETAIL;
                $plan = BusinessFeaturePresets::planForSetupMode($setupMode);
                $featureFlags = BusinessFeaturePresets::fromSetupMode($setupMode);
            }

            $business = Business::create([
                'name' => $data['business_name'],
                'owner_name' => $data['owner_name'],
                // Si el negocio no aclara un telefono distinto para
                // facturas/reportes, usamos el mismo whatsapp_number ya
                // pedido - evita duplicar el dato para el caso comun (un
                // solo numero para todo).
                'phone' => $data['phone'] ?? $data['whatsapp_number'] ?? null,
                'whatsapp_number' => $data['whatsapp_number'] ?? null,
                'nit' => $data['nit'] ?? null,
                'address' => $data['address'] ?? null,
                'trial_ends_at' => now()->addDays(Business::TRIAL_DAYS),
                'subscription_plan' => $plan,
                'feature_flags' => $featureFlags,
            ]);

            $user = User::create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'business_id' => $business->id,
                'is_active' => true,
                'is_business_owner' => true,
            ]);
            $user->assignRole('admin');

            return ['business' => $business, 'user' => $user];
        });

        // Fuera de la transaccion a proposito: un correo lento no debe tener
        // el lock de la escritura abierto, y si el envio falla no queremos
        // revertir un registro que ya se completo. Silencioso a proposito,
        // igual que NewUserCredentialsMail - el registro no se debe bloquear
        // por un mailer no configurado (dev sin SMTP).
        try {
            Mail::to($result['user']->email)->send(new WelcomeMail($result['user'], $result['business']));
        } catch (\Throwable) {
            //
        }

        return $result;
    }
}
