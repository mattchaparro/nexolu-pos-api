<?php

namespace App\Models;

use App\Support\BusinessFeaturePresets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'slug',
    'owner_name',
    'phone',
    'whatsapp_number',
    'instagram_handle',
    'tiktok_handle',
    'email_whatsapp_cta',
    'nit',
    'address',
    'logo_path',
    'ticket_paper_width',
    'invoice_prefix',
    'ticket_header_tagline',
    'ticket_thanks_message',
    'ticket_footer_text',
    'delivery_enabled',
    'delivery_fee',
    'service_charge_enabled',
    'service_charge_rate',
    'ipoconsumo_enabled',
    'ipoconsumo_rate',
    'layaway_allowed_category_ids',
    'payment_methods',
    'low_stock_alert_threshold',
    'low_stock_email_enabled',
    'low_stock_email',
    'low_stock_snoozed_until',
    'email_config',
    'notification_preferences',
    'active',
    'trial_ends_at',
    'paid_until',
    'subscription_expiry_notified_at',
    'subscription_plan',
    'custom_price_cop',
    'feature_flags',
    'service_orders_show_catalog',
    'service_orders_default_service_name',
    'onboarding_profile',
    'crm_notes',
    'crm_next_follow_up_at',
])]
class Business extends Model
{
    use HasFactory, SoftDeletes;

    const TRIAL_DAYS = 14;

    private const DEFAULT_PAYMENT_METHODS = [
        ['id' => 'cash', 'label' => 'Efectivo'],
        ['id' => 'transfer', 'label' => 'Transferencia'],
        ['id' => 'credit', 'label' => 'Fiado'],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trial_ends_at' => 'datetime',
            'paid_until' => 'datetime',
            'crm_next_follow_up_at' => 'datetime',
            'feature_flags' => 'array',
            'low_stock_email_enabled' => 'boolean',
            'low_stock_snoozed_until' => 'datetime',
            'subscription_expiry_notified_at' => 'datetime',
            'email_config' => 'array',
            'notification_preferences' => 'array',
            'delivery_enabled' => 'boolean',
            'delivery_fee' => 'decimal:2',
            'payment_methods' => 'array',
            'service_charge_enabled' => 'boolean',
            'service_charge_rate' => 'decimal:2',
            'ipoconsumo_enabled' => 'boolean',
            'ipoconsumo_rate' => 'decimal:2',
            'layaway_allowed_category_ids' => 'array',
            'service_orders_show_catalog' => 'boolean',
            'email_whatsapp_cta' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            if (! $business->slug) {
                $business->slug = Str::slug($business->name);
                $count = static::where('slug', 'like', $business->slug.'%')->count();
                if ($count > 0) {
                    $business->slug .= '-'.($count + 1);
                }
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function expenseTypes(): HasMany
    {
        return $this->hasMany(ExpenseType::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BusinessTable::class);
    }

    public function paymentMethods(): array
    {
        $methods = $this->payment_methods;
        if (! is_array($methods) || empty($methods)) {
            return self::DEFAULT_PAYMENT_METHODS;
        }

        return static::normalizePaymentMethodsInput($methods);
    }

    public static function normalizePaymentMethodsInput(array $methods): array
    {
        $normalized = [];
        foreach ($methods as $method) {
            $label = is_array($method)
                ? trim((string) ($method['label'] ?? $method['id'] ?? ''))
                : trim((string) $method);

            if ($label === '') {
                continue;
            }

            $id = strtolower((string) Str::of($label)->ascii()->replaceMatches('/[^a-zA-Z0-9]+/', '_')->trim('_'));
            if ($id === '') {
                continue;
            }

            if (! isset($normalized[$id])) {
                $normalized[$id] = ['id' => $id, 'label' => $label];
            }
        }

        if (empty($normalized)) {
            foreach (self::DEFAULT_PAYMENT_METHODS as $method) {
                $normalized[$method['id']] = $method;
            }
        }

        return array_values($normalized);
    }

    /**
     * Ids de metodo de pago validos para este negocio (para validacion y para
     * SaleService). Unico lugar donde se deriva esta lista.
     *
     * @return list<string>
     */
    public function allowedPaymentMethodIds(): array
    {
        return collect($this->paymentMethods())
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * El id que este negocio usa para el metodo de pago en efectivo. Soporta
     * tanto 'cash' (default/legacy) como 'efectivo' (configs en espanol).
     */
    public function resolveCashPaymentMethodId(): string
    {
        foreach ($this->paymentMethods() as $method) {
            $id = strtolower((string) ($method['id'] ?? ''));
            $label = strtolower((string) ($method['label'] ?? ''));
            if (in_array($id, ['cash', 'efectivo'], true) || in_array($label, ['cash', 'efectivo'], true)) {
                return (string) ($method['id'] ?? 'cash');
            }
        }

        return 'cash';
    }

    /**
     * Normaliza un payment_method id al id que este negocio tiene configurado.
     * Resuelve aliases legacy <-> espanol (cash<->efectivo, transfer<->transferencia, credit<->fiado).
     * Si el id ya esta configurado o no hay alias aplicable, lo retorna tal cual.
     */
    public function normalizePaymentMethodId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return $id;
        }

        $idLower = strtolower($id);
        $configured = $this->allowedPaymentMethodIds();

        if (in_array($idLower, $configured, true)) {
            return $idLower;
        }

        $aliases = [
            'cash' => ['efectivo'],
            'efectivo' => ['cash'],
            'transfer' => ['transferencia', 'transferencias'],
            'transferencia' => ['transfer'],
            'transferencias' => ['transfer'],
            'credit' => ['fiado', 'credito', 'crédito'],
            'fiado' => ['credit'],
            'credito' => ['credit'],
            'crédito' => ['credit'],
        ];

        foreach (($aliases[$idLower] ?? []) as $alias) {
            if (in_array($alias, $configured, true)) {
                return $alias;
            }
        }

        return $idLower;
    }

    /**
     * Metodos que representan "el cliente paga despues" (fiado). Ninguno de
     * estos deberia terminar sumado en una venta cerrada como ingreso directo.
     */
    public function isCreditPaymentMethod(?string $method): bool
    {
        if ($method === null) {
            return false;
        }

        return in_array(strtolower($method), ['credit', 'fiado', 'credito', 'crédito'], true);
    }

    public function chargesConfig(): array
    {
        return [
            'service_charge_enabled' => (bool) ($this->service_charge_enabled ?? false),
            'service_charge_rate' => (float) ($this->service_charge_rate ?? 10.0),
            'ipoconsumo_enabled' => (bool) ($this->ipoconsumo_enabled ?? false),
            'ipoconsumo_rate' => (float) ($this->ipoconsumo_rate ?? 8.0),
        ];
    }

    public function hasFeature(string $feature): bool
    {
        $flags = $this->feature_flags;

        // Negocios muy antiguos sin JSON: todo habilitado (retrocompatibilidad).
        if ($flags === null || ! is_array($flags) || $flags === []) {
            return true;
        }

        if (array_key_exists($feature, $flags)) {
            return (bool) $flags[$feature];
        }

        // Clave ausente pero plan definido → completar con el default del plan,
        // para que negocios creados antes de agregar la clave no queden sin restricción.
        if ($this->subscription_plan) {
            $planDefaults = BusinessFeaturePresets::fromPlan($this->subscription_plan);
            if (array_key_exists($feature, $planDefaults)) {
                return (bool) $planDefaults[$feature];
            }
        }

        return false;
    }

    /**
     * Compras y proveedores: alineado con el hub de catalogo (inventario basico, avanzado o recetas).
     */
    public function canAccessPurchases(): bool
    {
        return $this->hasFeature('inventory_advanced')
            || $this->hasFeature('ingredients')
            || $this->hasFeature('inventory');
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isPaid(): bool
    {
        return $this->paid_until && $this->paid_until->isFuture();
    }

    public function hasAccess(): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->onTrial() || $this->isPaid();
    }

    public function subscriptionStatus(): string
    {
        return match (true) {
            ! $this->active => 'inactive',
            $this->isPaid() => 'paid',
            $this->onTrial() => 'trial',
            default => 'expired',
        };
    }
}
