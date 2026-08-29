<?php

namespace App\Models;

use App\Support\BusinessFeaturePresets;
use App\Support\NotificationTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    'notification_schedule',
    'last_daily_summary_sent_on',
    'last_low_stock_alert_sent_on',
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
    'ai_chat_blocked',
    'ai_chat_daily_messages',
    'ai_message_pack_balance',
])]
class Business extends Model
{
    /** Ver clientLimit(). */
    public const CLIENT_LIMIT_FULL = 2000;

    public const CLIENT_LIMIT_ONLINE_STORE = 20000;

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
            'ai_chat_blocked' => 'boolean',
            'ai_chat_daily_messages' => 'integer',
            'ai_message_pack_balance' => 'integer',
            'trial_ends_at' => 'datetime',
            'paid_until' => 'datetime',
            'crm_next_follow_up_at' => 'datetime',
            'feature_flags' => 'array',
            'low_stock_email_enabled' => 'boolean',
            'low_stock_snoozed_until' => 'datetime',
            'subscription_expiry_notified_at' => 'datetime',
            'email_config' => 'array',
            'notification_preferences' => 'array',
            'notification_schedule' => 'array',
            'last_daily_summary_sent_on' => 'date',
            'last_low_stock_alert_sent_on' => 'date',
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

    public function billingProfile(): HasOne
    {
        return $this->hasOne(BillingProfile::class);
    }

    /** Configuracion de la tienda online. Null si nunca se abrio el modulo. */
    public function storeSettings(): HasOne
    {
        return $this->hasOne(BusinessStoreSettings::class);
    }

    public function paymentGateways(): HasMany
    {
        return $this->hasMany(BusinessPaymentGateway::class);
    }

    /**
     * La pasarela con la que este negocio le cobra a sus compradores, si
     * tiene una lista. Si conecto las dos, gana Bold: la misma llave le
     * sirve para el datafono, asi que es la que mas probablemente este
     * puesta a proposito.
     */
    public function activePaymentGateway(): ?BusinessPaymentGateway
    {
        return $this->paymentGateways()
            ->where('is_active', true)
            ->orderByRaw("FIELD(provider_slug, 'bold', 'wompi')")
            ->get()
            ->first(fn (BusinessPaymentGateway $gateway) => $gateway->isUsable());
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

    public function saasSubscriptionPayments(): HasMany
    {
        return $this->hasMany(SaasSubscriptionPayment::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BusinessTable::class);
    }

    /**
     * Workflow de etapas para ordenes de servicio asignado a este negocio -
     * cuando mucho uno a la vez (business_service_workflows.business_id es
     * unico). Lo administra un superadmin (ver
     * Api\V1\SuperAdmin\ServiceWorkflowController::assignBusiness()); este
     * negocio solo lo consume (ver ServiceOrderService, BusinessServiceWorkflowController).
     */
    public function serviceWorkflow(): HasOneThrough
    {
        return $this->hasOneThrough(
            ServiceWorkflow::class,
            BusinessServiceWorkflow::class,
            'business_id',
            'id',
            'id',
            'workflow_id',
        );
    }

    /**
     * Catalogo normalizado (App\Models\PosPaymentMethod) que este negocio
     * eligio via Ajustes - ver PosPaymentMethod para por que no se llama
     * simplemente "paymentMethods" a nivel de relacion (paymentMethods() ya
     * es el metodo publico estable que usa toda la app, ver mas abajo).
     *
     * @return BelongsToMany<PosPaymentMethod, $this>
     */
    public function posPaymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PosPaymentMethod::class, 'business_pos_payment_methods')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    /**
     * Negocios que ya migraron a Ajustes > Medios de pago (tienen al menos
     * una fila en business_pos_payment_methods) leen su catalogo normalizado
     * de ahi - los que no, siguen con el JSON libre de siempre
     * (`payment_methods`) hasta que un admin abra esa pantalla y guarde
     * (ver PosPaymentMethodController::update()), momento en el que se
     * crean sus filas de pivote por primera vez. Ningun negocio se migra en
     * bloque aca - es deliberado, ver la nota de docs/CUTOVER_TODO.md sobre
     * por que normalizar TODOS los negocios existentes es un paso aparte,
     * con sus propias pruebas, no algo que este refactor deba forzar.
     *
     * `enabled` es opcional en el shape de retorno (ausente = true) para no
     * romper a quienes ya leen ['id']/['label'] de paymentMethods() - solo
     * los negocios ya migrados al catalogo distinguen habilitado/deshabilitado.
     */
    public function paymentMethods(): array
    {
        $this->loadMissing('posPaymentMethods');

        if ($this->posPaymentMethods->isNotEmpty()) {
            return $this->posPaymentMethods
                ->sortBy('sort_order')
                ->map(fn (PosPaymentMethod $method) => [
                    'id' => $method->key,
                    'label' => $method->label,
                    'enabled' => (bool) $method->pivot->is_enabled,
                ])
                ->values()
                ->all();
        }

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
    /**
     * Solo los medios HABILITADOS para transacciones nuevas - los negocios
     * en el JSON legacy (sin fila 'enabled') se consideran todos habilitados,
     * como siempre. Los que ya migraron al catalogo (ver paymentMethods())
     * excluyen aca los que desactivaron, pero paymentMethods() los sigue
     * devolviendo igual para que resolveCashPaymentMethodId()/labels de
     * transacciones historicas no se rompan.
     */
    public function allowedPaymentMethodIds(): array
    {
        return collect($this->paymentMethods())
            ->filter(fn (array $method) => ($method['enabled'] ?? true) !== false)
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Igual que allowedPaymentMethodIds() pero conservando id+label - para
     * poblar selectores donde se crea una transacción NUEVA (checkout,
     * abono a compra/apartado, filtro de reportes): un medio que el
     * negocio ya desactivó no debe ofrecerse ahí. No usar para resolver el
     * label de una transacción YA EXISTENTE (pudo haberse hecho con un
     * medio hoy desactivado) - para eso ver paymentMethodLabelsMap().
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function enabledPaymentMethods(): array
    {
        return collect($this->paymentMethods())
            ->filter(fn (array $method) => ($method['enabled'] ?? true) !== false)
            ->map(fn (array $method) => [
                'id' => (string) $method['id'],
                'label' => (string) ($method['label'] ?? ucfirst(str_replace('_', ' ', (string) $method['id']))),
            ])
            ->values()
            ->all();
    }

    /**
     * id => label de TODOS los métodos configurados (habilitados o no) -
     * para resolver el label de una transacción ya existente que pudo usar
     * un medio desde entonces desactivado. Nunca usar para poblar un
     * selector de una transacción nueva, ver enabledPaymentMethods().
     *
     * @return array<string, string>
     */
    public function paymentMethodLabelsMap(): array
    {
        return collect($this->paymentMethods())
            ->mapWithKeys(fn (array $method) => [
                (string) $method['id'] => (string) ($method['label'] ?? ucfirst(str_replace('_', ' ', (string) $method['id']))),
            ])
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
     * Hora (HH:mm, America/Bogota) a la que este negocio quiere recibir un
     * tipo de notificacion schedulable (ver NotificationTypes::SCHEDULABLE)
     * - notification_schedule solo guarda overrides, asi que sin
     * personalizar cae al default de la plataforma.
     */
    public function notificationHour(string $type): string
    {
        return $this->notification_schedule[$type] ?? NotificationTypes::DEFAULT_HOURS[$type] ?? '00:00';
    }

    /**
     * El id que este negocio usa para el metodo de pago por transferencia.
     * Soporta 'transfer' (default/legacy) y 'transferencia'/'transferencias'
     * (configs en espanol) - mismo criterio que resolveCashPaymentMethodId().
     */
    public function resolveTransferPaymentMethodId(): string
    {
        foreach ($this->paymentMethods() as $method) {
            $id = strtolower((string) ($method['id'] ?? ''));
            $label = strtolower((string) ($method['label'] ?? ''));
            if (in_array($id, ['transfer', 'transferencia', 'transferencias'], true)
                || in_array($label, ['transfer', 'transferencia', 'transferencias'], true)) {
                return (string) ($method['id'] ?? 'transfer');
            }
        }

        return 'transfer';
    }

    /**
     * Alias legacy <-> espanol de payment_method (cash<->efectivo,
     * transfer<->transferencia, credit<->fiado) - ver docs/CUTOVER_TODO.md #1,
     * el vocabulario diverge entre `sales`/`sale_payment_splits`/`receivables`
     * (id en espanol o ingles segun que app escribio la fila) y lo que cada
     * negocio tiene configurado hoy. Compartido por normalizePaymentMethodId()
     * (arbitrario -> el id configurado de este negocio) y
     * paymentMethodIdWithAliases() (id configurado -> todos los valores
     * crudos que deberian contar como ese id al filtrar/agrupar).
     */
    private const PAYMENT_METHOD_ALIASES = [
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

        foreach (self::PAYMENT_METHOD_ALIASES[$idLower] ?? [] as $alias) {
            if (in_array($alias, $configured, true)) {
                return $alias;
            }
        }

        return $idLower;
    }

    /**
     * El id mas sus alias conocidos (ver PAYMENT_METHOD_ALIASES) - para
     * filtrar/agrupar por payment_method sin perder filas guardadas con el
     * vocabulario viejo del legacy (`efectivo` cuando el negocio filtra por
     * `cash`, etc. - ver docs/CUTOVER_TODO.md #1). Estatico porque los alias
     * no dependen de la config de un negocio en particular, solo de que id
     * pidio el caller.
     *
     * @return array<int, string>
     */
    public static function paymentMethodIdWithAliases(string $id): array
    {
        $idLower = strtolower($id);

        return array_values(array_unique([$idLower, ...(self::PAYMENT_METHOD_ALIASES[$idLower] ?? [])]));
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

    /**
     * Repetido en cada servicio que cobra un monto puntual (cerrar cuenta
     * abierta, cobrar fiado, abonar apartado): valida que $method este
     * configurado para este negocio, y opcionalmente que no sea el metodo de
     * credito (no tiene sentido "pagar" una deuda con otra deuda).
     */
    public function assertValidPaymentMethod(string $method, bool $forbidCredit = false): void
    {
        $allowed = $this->allowedPaymentMethodIds();
        if (! in_array($method, $allowed, true) || ($forbidCredit && $this->isCreditPaymentMethod($method))) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metodo de pago no permitido para este negocio.',
            ]);
        }
    }

    /**
     * Cuantos clientes puede registrar este negocio.
     *
     * El tope existe para que el plan basico no se use como CRM ilimitado,
     * pero una tienda online lo vuelve absurdo: cada comprador es una ficha
     * nueva y 50 se llenan en semanas. Con la tienda encendida el tope se
     * levanta - el comerciante ya esta pagando por vender por internet, y
     * perder el historial de quien le compro no es una palanca comercial
     * razonable, es un dato roto.
     */
    public function clientLimit(): int
    {
        if ($this->hasFeature('online_store')) {
            return self::CLIENT_LIMIT_ONLINE_STORE;
        }

        return $this->subscription_plan === 'full'
            ? self::CLIENT_LIMIT_FULL
            : Client::LIMIT_PER_BUSINESS;
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

        // Negocios muy antiguos sin JSON: todo habilitado (retrocompatibilidad),
        // salvo las banderas de opt-in explicito - un modulo que publica datos
        // hacia afuera no se puede encender solo. Ver BusinessFeaturePresets::OPT_IN_ONLY.
        if ($flags === null || ! is_array($flags) || $flags === []) {
            return ! in_array($feature, BusinessFeaturePresets::OPT_IN_ONLY, true);
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
     * Nombres de todos los feature flags habilitados para este negocio, en
     * el vocabulario canonico de BusinessFeaturePresets. Usado para armar el
     * TenantContext que se le manda al Nexolu IA Core (ver AiChatController):
     * el Core filtra que herramientas ofrecerle al modelo segun esta lista.
     *
     * @return list<string>
     */
    public function enabledFeatureNames(): array
    {
        return array_values(array_filter(
            array_keys(BusinessFeaturePresets::basic()),
            fn (string $feature) => $this->hasFeature($feature)
        ));
    }

    /**
     * Las 20 banderas del catalogo, cada una ya resuelta para este negocio
     * (mismo criterio de 3 ramas que hasFeature() para cada clave: valor
     * explicito -> default del plan si falta -> todo habilitado solo para
     * negocios muy antiguos sin JSON). Unica fuente que el frontend debe
     * leer para "que tiene encendido este negocio" (ver BusinessResource) -
     * exponer solo el feature_flags crudo obligaba al frontend a replicar
     * esta misma logica de resolucion, con el riesgo real de que se
     * desincronice (bug encontrado: una clave ausente en un feature_flags
     * no vacio se asumia habilitada del lado del frontend, cuando el
     * backend la resuelve con el default del plan).
     *
     * @return array<string, bool>
     */
    public function resolvedFeatures(): array
    {
        return collect(array_keys(BusinessFeaturePresets::basic()))
            ->mapWithKeys(fn (string $feature) => [$feature => $this->hasFeature($feature)])
            ->all();
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

    public function daysRemaining(): int
    {
        if ($this->isPaid()) {
            return (int) now()->diffInDays($this->paid_until, false);
        }

        if ($this->onTrial()) {
            return (int) now()->diffInDays($this->trial_ends_at, false);
        }

        return 0;
    }

    /** Precio mensual efectivo: precio personalizado si tiene, si no el del plan. */
    public function monthlyPriceCop(): int
    {
        return (int) ($this->custom_price_cop ?: BusinessFeaturePresets::planPriceCop($this->subscription_plan ?? 'basic'));
    }

    /** Extiende (o inicia) el periodo pago, encadenado al vencimiento vigente si ya estaba pago. */
    public function activate(int $days = 30): void
    {
        $from = $this->isPaid() ? $this->paid_until : now();
        $this->update([
            'paid_until' => $from->copy()->addDays($days),
            'active' => true,
        ]);
    }

    /**
     * Ciclos de precio promocional ya "consumidos" para la pantalla de
     * facturacion. Usa el maximo entre pagos registrados en
     * SaasSubscriptionPayment y periodos de ~30 dias desde el fin del trial
     * (o desde la creacion del negocio si nunca hubo trial), asi la promo no
     * queda pegada en "mes 1" cuando los cobros son manuales y no dejan
     * fila de pago.
     */
    public function subscriptionPromoCyclesConsumed(): int
    {
        $recorded = (int) $this->saasSubscriptionPayments()->count();

        if ($this->trial_ends_at && $this->trial_ends_at->isFuture()) {
            // Prueba aun vigente: si ya pago por adelantado, el primer ciclo
            // promocional no debe repetirse como "mes 1".
            if ($this->isPaid()) {
                return max($recorded, 1);
            }

            return $recorded;
        }

        $billingStart = ($this->trial_ends_at && $this->trial_ends_at->isPast())
            ? $this->trial_ends_at
            : $this->created_at;

        if (! $billingStart) {
            return $recorded;
        }

        $days = max(0, (int) $billingStart->copy()->startOfDay()->diffInDays(now()->copy()->startOfDay()));
        $elapsedByTime = intdiv($days, 30);

        return max($recorded, $elapsedByTime);
    }

    public function extendTrial(int $days): void
    {
        $from = $this->onTrial() ? $this->trial_ends_at : now();
        $this->update([
            'trial_ends_at' => $from->copy()->addDays($days),
            'active' => true,
        ]);
    }
}
