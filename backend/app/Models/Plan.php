<?php

namespace App\Models;

use App\Services\Billing\PricingMode;
use App\Services\Membership\PlanAccessRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tier',
        'price',
        'original_price',
        'duration_days',
        /*
         * BENEFICIO AÑADIDO. Un plan que se compra ENCIMA de otro y no lo
         * sustituye: el plan día, una clase suelta. No encadena detrás de la
         * membresía vigente, no le mueve el vencimiento y no le cambia la
         * etiqueta; vale desde el día que se compró y por su propia duración.
         *
         * Es una propiedad del PRODUCTO, no de cada venta. Ver la migración
         * `add_benefit_only_to_plans_table` para por qué no se adivina.
         */
        'benefit_only',
        // Reglas de acceso: qué compra el plan además de la vigencia.
        'access_mode',
        'entry_credits',
        'access_days',
        'access_windows',
        'benefits',
        'is_recommended',
        'badge',
        'sort_order',
        'access_classes',
        'reservations_limit',
        'access_locations',
        'restrictions',
        'active',
        'sellable',
        'visible_in_app',
        // «Se cobra en recepción». Ver la migración add_counter_sellable_to_plans_table.
        'counter_sellable',
        'features',
        // Facturación electrónica (aditivo).
        'tax_rate_id',
        'price_includes_tax',
        'unspsc_code',
        // Pricing V2.
        'pricing_mode',
        'billing_enabled',
    ];

    protected $casts = [
        'price' => 'float',
        'original_price' => 'float',
        'is_recommended' => 'boolean',
        'access_classes' => 'boolean',
        'benefit_only' => 'boolean',
        'active' => 'boolean',
        'sellable' => 'boolean',
        'visible_in_app' => 'boolean',
        'counter_sellable' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
        'entry_credits' => 'integer',
        'access_days' => 'array',
        'access_windows' => 'array',
        'price_includes_tax' => 'boolean',
        'billing_enabled' => 'boolean',
        // Archivo (ver la migración add_archive_to_plans_table). No son
        // asignables en masa: solo los escribe PlanArchive.
        'archived_at' => 'datetime',
        'archived_by' => 'integer',
        // PT-14: quién escribió el texto comercial vigente o lo puso a la venta.
        // No es asignable en masa: solo lo escriben PlanController y PlanArchive.
        'commercial_text_by' => 'integer',
    ];

    /**
     * Quién lo archivó y por qué NO salen en `toArray()`: el listado de planes
     * es PÚBLICO (la app lo lee sin sesión). El CRM los recibe aparte, solo con
     * sesión de administrador ({@see \App\Http\Controllers\Api\PlanController}).
     * El autor del texto comercial (PT-14), tampoco: no sale nunca.
     */
    protected $hidden = ['archived_by', 'archive_reason', 'archiver', 'commercial_text_by'];

    /** Segmentos comerciales disponibles para un plan. */
    public const TIERS = ['lite', 'pro', 'premium'];

    /*
     * EL TEXTO COMERCIAL (PT-14, diseño `plans/informes-cierre/diseno-arq-pt14.md`
     * §3 D3, D6 y D15): lo que se dice fuera de un plan, su NOMBRE y sus
     * BENEFICIOS. Esta es su única definición: la usan el permiso «Nombre y
     * beneficios» ({@see \App\Support\Access\PlanCommercialText}), la captura
     * de la publicación de la voz, su deriva y `consultar_planes`.
     */

    /** Los campos del texto comercial. El precio y las condiciones NO lo son. */
    public const COMMERCIAL_TEXT_FIELDS = ['name', 'benefits'];

    /** Como mucho estos planes en el catálogo (los mismos que lee `consultar_planes`). */
    public const COMMERCIAL_CATALOG_MAX_PLANS = 20;

    /** Como mucho estos beneficios de cada plan (los que examina `consultar_planes`). */
    public const COMMERCIAL_CATALOG_MAX_BENEFITS = 18;

    /** Un beneficio más largo no se guarda en el catálogo: la voz nunca lo dice (su tope es 90). */
    public const COMMERCIAL_BENEFIT_MAX_STORED = 255;

    /** El formato del catálogo publicado (`evidence.catalog` de la entrada `publish` de la voz). */
    public const COMMERCIAL_CATALOG_FORMAT = 'ultron-voice-catalog/1';

    /** Las claves del catálogo publicado, en orden de bytes. */
    private const COMMERCIAL_CATALOG_KEYS = ['authors', 'format', 'hash', 'plans'];

    /**
     * Las reglas de acceso del plan, normalizadas.
     *
     * Existe para que nadie vuelva a interpretar las columnas por su cuenta: el
     * CRM, el terminal de recepción y el registro de asistencia preguntan aquí.
     * Ver {@see PlanAccessRules}.
     */
    public function accessRules(): PlanAccessRules
    {
        return PlanAccessRules::fromPlan($this);
    }

    /** ¿Se vende por entradas («15 entradas al mes») y no por días sueltos? */
    public function isByEntries(): bool
    {
        return $this->accessRules()->isByEntries();
    }

    /**
     * Las restricciones del plan dichas como un beneficio más.
     *
     * PARA QUÉ. La app del socio no se puede modificar, y de un plan solo lee
     * precio, vigencia en meses y la lista de beneficios. Sin esto, quien abre
     * la app vería «Plan Valera · 1 mes» y ni una palabra de que son 15
     * entradas: la información más importante del plan sería justo la que no
     * aparece. Escribiéndola en la lista de beneficios —que la app ya pinta— el
     * socio la ve sin desplegar nada en el teléfono.
     *
     * Se generan, no se guardan: si mañana el plan pasa a 20 entradas, el texto
     * cambia solo y no queda un beneficio viejo mintiendo.
     *
     * @return list<string>
     */
    public function accessBenefitLines(): array
    {
        $reglas = $this->accessRules();
        $lineas = [];

        if ($reglas->isByEntries()) {
            $lineas[] = $reglas->entries.' entradas durante la vigencia del plan';
        }
        if ($reglas->restrictsDays()) {
            $lineas[] = 'Días de acceso: '.$reglas->daysLabel();
        }
        if ($reglas->restrictsHours()) {
            $lineas[] = 'Horario de acceso: '.$reglas->hoursLabel();
        }

        return $lineas;
    }

    /** Tarifa de IVA del plan (facturación electrónica). */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** La persona que lo archivó (nula si la cuenta se eliminó). Oculta en `toArray()`. */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'archived_by');
    }

    /**
     * ¿Está archivado? Un plan archivado existe para su historia (cobros,
     * traslados, socios que lo tienen) y no se vende por ninguna vía.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * ¿Este plan genera un comprobante fiscal?
     *
     * Un plan gratuito (precio 0) o marcado explícitamente como no facturable
     * (billing_enabled=false) NO exige tratamiento tributario: es un plan de
     * acceso, no una venta. Es el caso del plan Demo App Review, que debe seguir
     * funcionando sin recibir una clasificación tributaria inventada y sin
     * bloquear el diagnóstico de Factus.
     *
     * `null` cuenta como facturable: es el default de la columna, y un registro
     * recién creado (o anterior a la migración) no debe escaparse del control
     * tributario por no tener el valor materializado todavía.
     */
    public function isBillable(): bool
    {
        return ($this->billing_enabled ?? true) && (float) $this->price > 0;
    }

    /** Semántica del precio configurado (ver App\Services\Billing\PricingMode). */
    public function pricingMode(): PricingMode
    {
        return PricingMode::fromValue($this->pricing_mode);
    }

    public static function defaultFeatures(): array
    {
        return [
            'iron_ia' => false,
            'workouts' => true,
            'custom_routines' => false,
            'ranking' => false,
            'classes' => false,
            'progress' => true,
            'nutrition' => false,
        ];
    }

    public function resolvedFeatures(): array
    {
        $stored = is_array($this->features) ? $this->features : [];

        return array_merge(self::defaultFeatures(), $stored);
    }

    /**
     * ¿El plan incluye clases? Lo decide el módulo «Clases» (Módulos de la app),
     * que es la ÚNICA puerta real: con él apagado no se reserva ni desde la app
     * ni desde el mostrador ({@see \App\Services\Classes\ClassBookingService::hasClassesFeature()}).
     *
     * NO es la columna `access_classes` («Acceso a clases» en el CRM antiguo):
     * nunca la aplicó nadie y ya no se edita. Se conserva por su historia, y
     * quien describa el plan a un cliente tiene que decir lo que el plan da de
     * verdad, no lo que dice esa columna.
     */
    public function includesClasses(): bool
    {
        return (bool) ($this->resolvedFeatures()['classes'] ?? false);
    }

    public function getMonthsAttribute(): int
    {
        return max(1, (int) round(((int) $this->duration_days) / 30));
    }

    public function getPeriodAttribute(): string
    {
        $months = $this->months;

        return $months === 1 ? '1 mes' : "{$months} meses";
    }

    public function benefitsArray(): array
    {
        $benefits = $this->benefits;

        if (is_array($benefits)) {
            return $this->cleanBenefits($benefits);
        }

        if (! is_string($benefits) || trim($benefits) === '') {
            return [];
        }

        $decoded = json_decode($benefits, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->cleanBenefits($decoded);
        }

        return $this->cleanBenefits(preg_split('/\r\n|\r|\n|,/', $benefits) ?: []);
    }

    private function cleanBenefits(array $benefits): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $benefit): string => trim((string) $benefit),
            $benefits
        )));
    }

    /**
     * El texto comercial del plan, normalizado para COMPARAR por valor (D3): el
     * nombre sin espacios en los extremos y los beneficios como los lee
     * {@see benefitsArray()} (el CRM los reenvía como texto en cada guardado;
     * otra serialización de la misma lista no es otro texto).
     *
     * @return array{name: string, benefits: list<string>}
     */
    public function commercialText(): array
    {
        try {
            $beneficios = $this->benefitsArray();
        } catch (\ErrorException) {
            // Tragado a propósito (M-4): una lista ANIDADA hace que benefitsArray() convierta un array en texto y el
            // aviso de PHP sube como excepción. Ese texto no se puede decir ni comparar: cuenta como sin beneficios.
            // `consultar_planes` lo sigue viendo y contando con su propia lectura.
            $beneficios = [];
        }

        return ['name' => trim((string) $this->name), 'benefits' => $beneficios];
    }

    /** ¿Dicen lo mismo dos textos comerciales ({@see commercialText()})? */
    public static function commercialTextEquals(array $a, array $b): bool
    {
        return ($a['name'] ?? null) === ($b['name'] ?? null) && ($a['benefits'] ?? null) === ($b['benefits'] ?? null);
    }

    /**
     * Los planes que se dicen por la voz: los vendibles, en su orden y como
     * mucho {@see COMMERCIAL_CATALOG_MAX_PLANS}. Es la consulta de
     * `consultar_planes` y la de la captura: la misma. El último criterio
     * (`id`) desempata, para que dos lecturas del mismo catálogo den la misma
     * lista y la misma huella.
     */
    public function scopeCommercialCatalog($query)
    {
        return $query->sellable()
            ->orderBy('sort_order')
            ->orderBy('duration_days')
            ->orderBy('id')
            ->limit(self::COMMERCIAL_CATALOG_MAX_PLANS);
    }

    /**
     * El catálogo comercial de ahora, con su autor, en UNA consulta: el texto
     * y quién lo escribió son de la misma lectura (si se leyeran por separado,
     * una edición entre las dos atribuiría un texto a quien no lo escribió).
     *
     * De cada plan, su texto comercial con los topes de D6: como mucho
     * {@see COMMERCIAL_CATALOG_MAX_BENEFITS} beneficios (los primeros, los que
     * examina la voz) y ninguno de más de {@see COMMERCIAL_BENEFIT_MAX_STORED}
     * caracteres ni que no sea UTF-8 válido (nunca se dicen). NUNCA el precio,
     * la vigencia ni las reglas de acceso.
     *
     * @return array{plans: list<array{id: int, name: string, benefits: list<string>}>, authors: list<array{planId: int, by: int|null}>}
     */
    public static function commercialCatalogSnapshot(): array
    {
        $planes = [];
        $autores = [];
        foreach (self::query()->commercialCatalog()->get(['id', 'name', 'benefits', 'commercial_text_by']) as $p) {
            $texto = $p->commercialText();
            $beneficios = array_values(array_filter(
                array_slice($texto['benefits'], 0, self::COMMERCIAL_CATALOG_MAX_BENEFITS),
                static fn (string $b): bool => mb_check_encoding($b, 'UTF-8') && mb_strlen($b, 'UTF-8') <= self::COMMERCIAL_BENEFIT_MAX_STORED,
            ));
            $planes[] = ['id' => (int) $p->id, 'name' => $texto['name'], 'benefits' => $beneficios];
            $autores[] = ['planId' => (int) $p->id, 'by' => $p->commercial_text_by === null ? null : (int) $p->commercial_text_by];
        }

        return ['plans' => $planes, 'authors' => $autores];
    }

    /** @return list<array{id: int, name: string, benefits: list<string>}> el catálogo comercial de ahora, sin autores */
    public static function commercialCatalog(): array
    {
        return self::commercialCatalogSnapshot()['plans'];
    }

    /**
     * La huella de un catálogo: sha256 del JSON con las claves en orden fijo
     * (`benefits`, `id`, `name`), sin escapar el Unicode ni las barras.
     *
     * @param  list<array{id: int, name: string, benefits: list<string>}>  $plans
     */
    public static function commercialCatalogHash(array $plans): string
    {
        $canonico = array_map(static fn (array $p): array => [
            'benefits' => array_values($p['benefits']),
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
        ], array_values($plans));

        return hash('sha256', json_encode($canonico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Lectura ESTRICTA de un catálogo publicado (`evidence.catalog`), la única:
     * la usan la telefonía y el Studio. Exactamente `{format, hash, plans,
     * authors}`; el formato {@see COMMERCIAL_CATALOG_FORMAT}; los planes con la
     * forma de {@see commercialCatalogPlans()}; la huella, la de esos planes; y
     * los autores, uno por plan y en su orden (`by`: id ≥ 1 o null). Cualquier
     * otra cosa es null: no se sabe qué se aprobó (falla cerrado). Nunca lanza.
     *
     * @return array{hash: string, plans: list<array{id: int, name: string, benefits: list<string>}>, authors: list<array{planId: int, by: int|null}>}|null
     */
    public static function readCommercialCatalog(mixed $catalog): ?array
    {
        if (! is_array($catalog) || array_is_list($catalog)) {
            return null;
        }
        $claves = array_keys($catalog);
        sort($claves, SORT_STRING);
        if ($claves !== self::COMMERCIAL_CATALOG_KEYS || $catalog['format'] !== self::COMMERCIAL_CATALOG_FORMAT
            || ! is_string($catalog['hash']) || preg_match('/^[0-9a-f]{64}$/D', $catalog['hash']) !== 1) {
            return null;
        }
        $planes = self::commercialCatalogPlans($catalog['plans']);
        if ($planes === null || ! hash_equals(self::commercialCatalogHash($planes), $catalog['hash'])) {
            return null;
        }
        $autores = $catalog['authors'];
        if (! is_array($autores) || ! array_is_list($autores) || count($autores) !== count($planes)) {
            return null;
        }
        $leidos = [];
        foreach ($autores as $i => $a) {
            if (! is_array($a)) {
                return null;
            }
            $deEste = array_keys($a);
            sort($deEste, SORT_STRING);
            if ($deEste !== ['by', 'planId'] || $a['planId'] !== $planes[$i]['id']
                || ($a['by'] !== null && (! is_int($a['by']) || $a['by'] < 1))) {
                return null;
            }
            $leidos[] = ['planId' => $a['planId'], 'by' => $a['by']];
        }

        return ['hash' => $catalog['hash'], 'plans' => $planes, 'authors' => $leidos];
    }

    /**
     * La forma de la lista de planes de un catálogo: como mucho
     * {@see COMMERCIAL_CATALOG_MAX_PLANS}, cada uno exactamente `{id ≥ 1,
     * name ≤ 255, benefits}` con como mucho {@see COMMERCIAL_CATALOG_MAX_BENEFITS}
     * textos de hasta {@see COMMERCIAL_BENEFIT_MAX_STORED} caracteres, y sin ids
     * repetidos. Devuelve la lista normalizada, o null.
     *
     * @return list<array{id: int, name: string, benefits: list<string>}>|null
     */
    public static function commercialCatalogPlans(mixed $plans): ?array
    {
        if (! is_array($plans) || ! array_is_list($plans) || count($plans) > self::COMMERCIAL_CATALOG_MAX_PLANS) {
            return null;
        }
        $out = [];
        $ids = [];
        foreach ($plans as $p) {
            if (! is_array($p)) {
                return null;
            }
            // Exactamente estas tres (un `jsonb` puede devolverlas en otro orden).
            $claves = array_keys($p);
            sort($claves, SORT_STRING);
            if ($claves !== ['benefits', 'id', 'name'] || ! is_int($p['id']) || $p['id'] < 1 || isset($ids[$p['id']])
                || ! is_string($p['name']) || mb_strlen($p['name'], 'UTF-8') > 255
                || ! is_array($p['benefits']) || ! array_is_list($p['benefits']) || count($p['benefits']) > self::COMMERCIAL_CATALOG_MAX_BENEFITS) {
                return null;
            }
            foreach ($p['benefits'] as $b) {
                if (! is_string($b) || mb_strlen($b, 'UTF-8') > self::COMMERCIAL_BENEFIT_MAX_STORED) {
                    return null;
                }
            }
            $ids[$p['id']] = true;
            $out[] = ['id' => $p['id'], 'name' => $p['name'], 'benefits' => $p['benefits']];
        }

        return $out;
    }

    /**
     * Qué puede cotizarse, recomendarse y cobrarse a alguien de fuera: el canal
     * COMERCIAL (ULTRON, links de pago) y la base del canal de la app.
     *
     * Es UNA sola definición a propósito. Antes cada sitio comercial repetía
     * `where('active', true)` y con eso alcanzaba a planes que nadie quiere
     * vender: un plan contable de precio 0 y uno de 1 peso estaban a la vista
     * del asesor, y habrían acabado en un WhatsApp real o en un cobro.
     *
     * Las cuatro condiciones dicen cosas distintas:
     *  - `active`: el sistema lo usa. Inactivo no se vende por NINGUNA vía,
     *    tampoco en el mostrador;
     *  - `sellable`: el negocio quiere ofrecerlo fuera. NO gobierna el
     *    mostrador: recepción cobra cada semana el plan 24 («PLAN SEPTIEMBRE»,
     *    1 peso), que no es vendible. Eso lo decide `counter_sellable`;
     *  - precio > 0: si no se puede cobrar, no es una oferta;
     *  - duración > 0: si no da acceso durante un tiempo, tampoco.
     *
     * Las dos últimas son la red por si alguien reactiva un plan interno sin
     * darse cuenta: la bandera puede equivocarse, pero un plan de precio cero
     * sigue sin ser vendible.
     *
     * Cada canal pregunta lo suyo, y solo lo suyo:
     *  - comercial (ULTRON, links): esto;
     *  - app (catálogo, Wompi, suscripciones): esto y además que se enseñe en
     *    la app ({@see isSellableInApp()});
     *  - mostrador (cobro, plan a plazos, mejora): {@see isSellableAtCounter()}.
     */
    public function scopeSellable($query)
    {
        return $query->where('active', true)
            ->where('sellable', true)
            // Archivado ⇒ ya está inactivo y no vendible; esto es la red por si
            // alguien tocara las banderas por debajo.
            ->whereNull('archived_at')
            ->where('price', '>', 0)
            ->where('duration_days', '>', 0);
    }

    /**
     * ¿Se le enseña al socio en la app?
     *
     * Null cuenta como SÍ: es el valor por defecto de la columna, y un plan
     * creado antes de que existiera no debe desaparecer de la app por no tener
     * el dato materializado. Ocultar es siempre una decisión explícita.
     */
    public function isVisibleInApp(): bool
    {
        return ($this->visible_in_app ?? true) && (bool) $this->active;
    }

    /** La misma regla, para un plan ya cargado. */
    public function isSellable(): bool
    {
        return (bool) $this->active
            && (bool) $this->sellable
            && ! $this->isArchived()
            && (float) $this->price > 0
            && (int) $this->duration_days > 0;
    }

    /**
     * ¿Se cobra en la Caja de Gimnasio? Cobro de mostrador, plan a plazos y
     * mejora de plan.
     *
     * Activo, sin archivar y marcado «Se cobra en recepción». NO mira
     * `sellable`, el precio ni la duración, y es a propósito: recepción cobra
     * planes que no se ofrecen fuera —el plan 24, de 1 peso— y planes de cero
     * días, que no dan vigencia y siguen cobrándose (ZeroDurationPlanTest).
     *
     * `counter_sellable` nulo cuenta como SÍ: es el valor por defecto de la
     * columna, y un plan recién creado y aún no releído no lo trae cargado.
     */
    public function isSellableAtCounter(): bool
    {
        return (bool) $this->active
            && ! $this->isArchived()
            && (bool) ($this->counter_sellable ?? true);
    }

    /** La misma regla, en SQL. */
    public function scopeSellableAtCounter($query)
    {
        return $query->where('active', true)
            ->whereNull('archived_at')
            ->where('counter_sellable', true);
    }

    /**
     * ¿Se puede comprar desde la app? Catálogo, Wompi y suscripciones.
     *
     * Vendible y visible en la app, las dos cosas. El catálogo y la pasarela
     * solo miraban `active` y el archivo, así que un plan que el negocio no
     * quiere vender —el de 1 peso, uno contable— se podía pagar por Wompi desde
     * el teléfono aunque ULTRON y los links ya lo rechazaran.
     */
    public function isSellableInApp(): bool
    {
        return $this->isSellable() && $this->isVisibleInApp();
    }

    /** La misma regla, en SQL. */
    public function scopeSellableInApp($query)
    {
        return $query->sellable()
            // Nulo cuenta como visible, igual que en isVisibleInApp().
            ->where(fn ($q) => $q->where('visible_in_app', true)->orWhereNull('visible_in_app'));
    }
}
