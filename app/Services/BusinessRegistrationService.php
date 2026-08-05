<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un negocio nuevo (self-service): crea el Business y su usuario
 * dueño en una sola transaccion. Es el unico punto de entrada para esto -
 * antes de este servicio, la unica forma de tener un negocio de prueba era
 * un tinker manual.
 */
class BusinessRegistrationService
{
    /**
     * @param  array{business_name: string, owner_name: string, email: string, password: string, phone?: ?string, nit?: ?string, address?: ?string, setup_mode?: ?string, plan?: ?string}  $data
     * @return array{business: Business, user: User}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // El self-registro (setup_mode) y el alta manual del superadmin
            // (plan directo) llegan al mismo resultado por dos caminos: uno
            // parte del tipo de negocio, el otro del plan comercial ya
            // decidido en una llamada de ventas.
            if (! empty($data['plan'])) {
                $plan = $data['plan'];
                $featureFlags = BusinessFeaturePresets::fromPlan($plan);
            } else {
                $setupMode = $data['setup_mode'] ?? BusinessFeaturePresets::SETUP_RETAIL;
                $plan = BusinessFeaturePresets::planForSetupMode($setupMode);
                $featureFlags = BusinessFeaturePresets::fromSetupMode($setupMode);
            }

            $business = Business::create([
                'name' => $data['business_name'],
                'owner_name' => $data['owner_name'],
                'phone' => $data['phone'] ?? null,
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
    }
}
