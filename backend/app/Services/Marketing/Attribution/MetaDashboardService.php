<?php

namespace App\Services\Marketing\Attribution;

use App\Jobs\SyncMetaAdsInsights;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingLeadIdentity;
use App\Models\MarketingMessage;
use App\Models\MetaAdEntity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Marketing\Meta\MetaSpendReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Las cifras del panel «Mercadeo digital (Meta)», todas sobre el MISMO periodo.
 *
 * El periodo se elige en días de Bogotá y se convierte a un rango UTC
 * `[desde, hasta)`, como CashReceipts::businessDay: lo que pasa entre las 19:00
 * y la medianoche de Bogotá es de ese día, no del siguiente.
 *
 * EL UNIVERSO, U: las PERSONAS reales (sin pruebas, ver {@see LeadUniverse};
 * una persona es su teléfono, D6) cuyo PRIMER contacto de toda su historia cae
 * en el periodo. Quien ya había escrito antes no entra, aunque vuelva a escribir
 * dentro del periodo. Cada persona la representa su lead principal, el de primer
 * contacto más antiguo de toda su historia real; los demás leads de la persona
 * son duplicados (`duplicate`, con `duplicate_of`). Leads, convertidos,
 * renovaciones, ingresos, pauta, ROAS, CAC, origen y campañas se cuentan por
 * persona, sobre ese lead principal: nunca salen más convertidos que leads, y
 * un subperiodo nunca atribuye a una persona algo distinto que el periodo que
 * lo contiene. Las conversaciones son la excepción documentada: cuentan las
 * conversaciones con algún mensaje entrante en el periodo, aunque el lead sea
 * antiguo.
 *
 * ROAS y CAC miran el universo PAUTA RESUELTO: las personas cuyo primer toque
 * fue un anuncio de CUALQUIERA de las cuentas publicitarias CONECTADAS
 * (`kpis.paid_resolved`), que son las únicas cuyo gasto se conoce; el gasto es
 * el consolidado de esas mismas cuentas. Así no se atribuye a los anuncios lo
 * que vendió lo orgánico, ni a una cuenta lo que trajo otra que no está
 * conectada. Sin gasto conocido, en otra moneda o en cero, valen null con su
 * motivo: nunca se inventa un número. Si hay leads de pauta de una cuenta no
 * conectada y el gasto se conoce, `roas_warning` lo avisa, y `paid_coverage`
 * dice cuántos son, solo si hay evidencia de que lo son (`established`).
 *
 * Desglose: cuenta (`accounts`) → campaña → conjunto → anuncio. Los leads de
 * pauta de una cuenta no conectada van a «Cuenta publicitaria no conectada»,
 * sin gasto, ingresos atribuibles, ROAS ni CAC: no se inventan.
 *
 * EL DINERO (ingresos, ROAS, CAC) solo lo ve quien tiene visión COMPLETA, igual
 * que en /admin/marketing/analytics. A los demás les llega en null con el
 * motivo `forbidden` y `money_visible = false`; los conteos sí los ven. Lo
 * decide el controlador.
 *
 * Nada de esto escribe en la base: la identidad de cada lead se resuelve en
 * memoria. Lo único que hace algo es el despacho de la sincronización.
 */
class MetaDashboardService
{
    public const PERIODS = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'];

    public const DEFAULT_PERIOD = '30d';

    /** Un periodo personalizado más largo obligaría a recorrer años de leads en cada carga. */
    public const MAX_CUSTOM_DAYS = 366;

    /** Moneda de los ingresos: todo lo que cobra el gimnasio está en pesos. */
    public const REVENUE_CURRENCY = 'COP';

    public const MAX_PER_PAGE = 50;

    /** Página más alta que se atiende: más allá no hay leads y la cuenta se desborda. */
    public const MAX_PAGE = 10000;

    /** La fila de los leads de pauta cuyo anuncio no está en la dimensión de ninguna cuenta conectada. */
    public const UNRESOLVED = 'unresolved';

    /** La fila de nivel cuenta de esos mismos leads ({@see accountTable()}). */
    public const UNCONNECTED = 'unconnected';

    /** Su nombre si Meta Ads no está conectado: no hay dimensión contra la que resolver. */
    public const UNRESOLVED_NAME = 'Sin campaña (Meta Ads sin conexión)';

    /**
     * Su nombre si Meta Ads está conectado y hay EVIDENCIA ({@see established()}):
     * todas las cuentas conectadas tienen alguna pasada buena y el gasto del
     * periodo está cubierto entero, así que un anuncio que no está en lo
     * sincronizado de ninguna es de una cuenta que no está conectada. Sigue
     * siendo pauta; su gasto no se conoce.
     */
    public const UNRESOLVED_NAME_OTHER_ACCOUNT = 'Cuenta publicitaria no conectada';

    /**
     * Su nombre, neutro, si Meta Ads está conectado pero esa evidencia falta:
     * alguna cuenta conectada aún sin ninguna pasada buena (su dimensión está
     * vacía), o el periodo sin cubrir entero (la pasada horaria mira 7 días: un
     * anuncio que solo entregó antes no está en la dimensión aunque sea de una
     * cuenta conectada). No se sabe si el anuncio es de una de ellas.
     */
    public const UNRESOLVED_NAME_NEVER_SYNCED = 'Sin campaña (Meta Ads aún sin sincronizar)';

    /** Las tres respuestas de la conclusión ejecutiva. */
    public const PROFITABLE_YES = 'YES';

    public const PROFITABLE_NO = 'NO';

    public const PROFITABLE_INCONCLUSIVE = 'INCONCLUSIVE';

    /** Meses abreviados como los escribe el panel («16 sept 2026»). */
    private const SHORT_MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    /** Motivo de un importe que el usuario no puede ver. */
    public const MONEY_FORBIDDEN = 'forbidden';

    /** Aviso del ROAS: hay leads de pauta cuyo anuncio no es de ninguna cuenta conectada. */
    public const WARNING_UNRESOLVED_PAID_LEADS = 'unresolved_paid_leads';

    /** Motivo del retorno y de las cifras con gasto cuando el gasto es solo de una parte del periodo. */
    public const SPEND_INCOMPLETE = 'spend_incomplete';

    /**
     * Motivo del retorno cuando la cuenta no tuvo ni gasto ni ingresos en el
     * periodo: la resta daría 0, y eso no es un «punto de equilibrio».
     */
    public const NO_AD_ACTIVITY = 'no_ad_activity';

    /**
     * Motivo de las cifras atribuibles de un periodo ANTERIOR a la cobertura: el
     * CRM aún no captaba leads ni referrals, así que un 0 no sería una medición.
     */
    public const NO_HISTORICAL_COVERAGE = 'no_historical_coverage';

    /** Lo mismo cuando el periodo empieza antes de la cobertura y termina después. */
    public const PARTIAL_HISTORICAL_COVERAGE = 'partial_historical_coverage';

    /** Huecos de hasta tantos días no cortan un tramo de captación. */
    private const COVERAGE_MAX_GAP_DAYS = 7;

    /** Un tramo de captación abre la cobertura si dura al menos tantos días… */
    private const COVERAGE_MIN_RUN_DAYS = 7;

    /** …y tiene al menos tantos días distintos con captación. */
    private const COVERAGE_MIN_ACTIVE_DAYS = 4;

    /**
     * Un lead se captó AL LLEGAR si su registro de atribución se escribió
     * (`created_at`) a menos de tanto de su primer contacto. Uno viejo que
     * vuelve a escribir estrena su registro ese día, y uno que recupera el
     * relleno lo escribe el día del relleno (aunque su `first_touch_at` sea el
     * del mensaje antiguo): ninguno dice nada de entonces.
     */
    private const COVERAGE_CAPTURE_TOLERANCE_SECONDS = 86400;

    private const COVERAGE_CACHE_KEY = 'marketing:meta-dashboard:coverage-since';

    /**
     * Las filas de «Origen de leads», en el orden en que se pintan. Solo salen
     * las que tienen leads. Lo que no es de esta lista (un anuncio o una
     * publicación sin plataforma reconocible, un «directo» de otro canal) va a
     * «Otros», que dice qué incluye.
     */
    public const ORIGIN_ORDER = [
        'facebook_ads', 'instagram_ads', 'whatsapp_direct', 'instagram_organic', 'facebook_organic',
        self::ORIGIN_OTHERS, LeadSourceClassifier::KEY_UNATTRIBUTED,
    ];

    public const ORIGIN_OTHERS = 'others';

    /** Filas por página en el desglose de conjuntos y anuncios. */
    public const CHILDREN_PER_PAGE = 25;

    /** Pausa mínima entre dos sincronizaciones pedidas desde el panel. */
    public const SYNC_COOLDOWN_SECONDS = 300;

    private const SYNC_COOLDOWN_KEY = 'meta-dashboard:sync-requested';

    private const CHUNK = 500;

    public function __construct(
        private readonly LeadSourceClassifier $classifier,
        private readonly ConversionLedger $ledger,
        private readonly MetaSpendReader $spend,
        private readonly MetaAdsSync $sync,
        private readonly MetaAdsApiClient $client,
    ) {}

    /** La zona del negocio: la misma con la que Caja parte los días. */
    public static function timezone(): string
    {
        return (string) config('caja.timezone', 'America/Bogota');
    }

    // ── Periodo ─────────────────────────────────────────────────────────────

    /**
     * Traduce el periodo pedido a días de Bogotá y a su rango UTC `[start, end)`.
     *
     * Sin clave, `custom` si llegan fechas y si no los últimos 30 días.
     *
     * @return array{key: string, from: string, to: string, timezone: string, start: CarbonImmutable, end: CarbonImmutable}
     *
     * @throws InvalidArgumentException con el motivo en español
     */
    public function period(?string $key = null, ?string $from = null, ?string $to = null): array
    {
        $tz = self::timezone();
        $key = trim((string) $key);
        if ($key === '') {
            $key = filled($from) || filled($to) ? 'custom' : self::DEFAULT_PERIOD;
        }

        if (! in_array($key, self::PERIODS, true)) {
            throw new InvalidArgumentException('Periodo desconocido: usa today, 7d, 30d, this_month, last_month o custom.');
        }

        $today = CarbonImmutable::now($tz)->startOfDay();
        $previousMonth = $today->startOfMonth()->subMonthNoOverflow();

        [$first, $last] = match ($key) {
            'today' => [$today, $today],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            // Hasta HOY, no hasta fin de mes: el periodo dice lo que cubren las cifras.
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$previousMonth, $previousMonth->endOfMonth()->startOfDay()],
            default => $this->customDays($from, $to, $tz),
        };

        return [
            'key' => $key,
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
            'timezone' => $tz,
            'start' => $first->utc(),
            // Fin exclusivo: la medianoche de Bogotá del día siguiente al último.
            'end' => $last->addDay()->utc(),
        ];
    }

    // ── Lo que pide el panel ────────────────────────────────────────────────

    /**
     * Todo el panel de una vez, sobre el periodo de {@see period()}.
     *
     * `$moneyVisible` lo decide quien conoce al usuario (el controlador): sin
     * visión completa, los importes salen en null con su motivo.
     *
     * @param  array{key: string, from: string, to: string, timezone: string, start: CarbonInterface, end: CarbonInterface}  $period
     * @return array<string, mixed>
     */
    public function dashboard(array $period, bool $moneyVisible = true): array
    {
        [$from, $to] = $this->bounds($period['start'], $period['end']);

        $ctx = $this->context($from, $to);
        $conversations = $this->conversationsByLead($from, $to, $ctx);
        $spend = $this->spend->snapshot($from, $to);
        [$origins, $unattributedShare] = $this->origins($ctx, $moneyVisible);
        $attributableShare = $unattributedShare === null ? null : round(1 - $unattributedShare, 4);

        $kpis = $this->kpis($ctx, $conversations, $spend, $moneyVisible);
        $kpis['attributable_share'] = $attributableShare;

        // Antes de que el CRM captara leads y referrals, lo atribuible no es una medición.
        $history = $this->coverageOf($from, $to);
        $kpis = $this->withoutCoverage($kpis, $history['state']);
        $secondary = $this->secondary($ctx, $from, $to);
        if ($history['state'] === 'none') {
            [$origins, $unattributedShare, $attributableShare] = [[], null, null];
            // Las del periodo también son de leads: sin captación, «—». Las de ahora no dependen del periodo.
            $secondary = array_map(fn (array $m): array => $m['scope'] === 'period' ? ['value' => null] + $m : $m, $secondary);
        }

        $coverage = $this->spend->coverage($from, $to);
        $previous = $this->previousPeriod($period);

        // Una vez por carga: si se puede afirmar que la pauta sin resolver es de una cuenta NO
        // conectada, y con eso el nombre de su fila, la cobertura de pauta y la regla 8.
        $established = $this->established($coverage);
        $unresolvedName = $this->unresolvedName($established);
        $campaignRows = $this->campaignRows(MetaAdEntity::LEVEL_CAMPAIGN, $ctx, $conversations, $from, $to, $moneyVisible, null, $history, $unresolvedName);
        $paidCoverage = $this->paidCoverage($kpis, $history['state'], $established);

        return [
            'period' => [
                'key' => $period['key'],
                'from' => $period['from'],
                'to' => $period['to'],
                'timezone' => $period['timezone'],
            ],
            'previous_period' => ['from' => $previous['from'], 'to' => $previous['to']],
            'historical_coverage' => $history,
            'spend' => $spend,
            'kpis' => $kpis,
            'previous' => $this->previousSummary($previous, $moneyVisible),
            'secondary' => $secondary,
            'origins' => $origins,
            'unattributed_share' => $unattributedShare,
            'attributable_share' => $attributableShare,
            'campaigns' => [
                'level' => MetaAdEntity::LEVEL_CAMPAIGN,
                'rows' => $campaignRows,
                'partial' => $coverage['known'] && ! $coverage['complete'],
                'covered_to' => $coverage['known'] && ! $coverage['complete'] ? $coverage['through'] : null,
            ],
            'attribution' => [
                'model' => 'first_touch',
                'window_days' => ConversionLedger::windowDays(),
                'definitions' => self::definitions(),
            ],
            'sync' => $this->sync->statusAll(),
            'metrics_available' => $this->spend->metricsAvailable(),
            'paid_coverage' => $paidCoverage,
            'accounts' => $this->accountTable($ctx, $conversations, $from, $to, $moneyVisible, $history, $campaignRows, $unresolvedName),
            'executive' => $this->executive($spend, $kpis, $paidCoverage, $history, $to, $established),
        ];
    }

    /**
     * La tabla de campañas, conjuntos o anuncios del periodo `[from, to)`.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(string $level, CarbonInterface $from, CarbonInterface $to, bool $moneyVisible = true): array
    {
        return $this->campaignTable($level, $from, $to, $moneyVisible, null, 1, PHP_INT_MAX)['rows'];
    }

    /**
     * La tabla de un nivel, o el desglose de los hijos de una fila: los
     * conjuntos de una campaña (`$parentId` de una campaña, nivel adset) o los
     * anuncios de un conjunto (de un conjunto, nivel ad). `$parentId` es la
     * clave opaca de la fila ({@see opaqueId()}); si no es de una cuenta
     * conectada, no hay hijos.
     *
     * @return array{rows: list<array<string, mixed>>, partial: bool, covered_to: ?string,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function campaignTable(
        string $level,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $moneyVisible = true,
        ?string $parentId = null,
        int $page = 1,
        int $perPage = self::CHILDREN_PER_PAGE,
    ): array {
        if (! in_array($level, MetaAdEntity::LEVELS, true)) {
            throw new InvalidArgumentException('Nivel desconocido: usa campaign, adset o ad.');
        }

        [$from, $to] = $this->bounds($from, $to);
        $coverage = $this->spend->coverage($from, $to);
        $perPage = max(1, $perPage);
        $page = max(1, min(self::MAX_PAGE, $page));

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->resolveParent($level, $parentId);
            if ($parent === null) {
                return $this->tableOf([], $coverage, 1, $perPage);
            }
        }

        $ctx = $this->context($from, $to);
        $conversations = $this->conversationsByLead($from, $to, $ctx);
        // El mismo nombre de la pauta sin resolver que en el panel del periodo.
        $unresolvedName = $this->unresolvedName($this->established($coverage));
        $rows = $this->campaignRows($level, $ctx, $conversations, $from, $to, $moneyVisible, $parent, $this->coverageOf($from, $to), $unresolvedName);

        return $this->tableOf($rows, $coverage, $page, $perPage);
    }

    /**
     * Las PERSONAS del periodo (una fila cada una, con su lead principal), de la
     * más reciente a la más antigua. Una persona con dos perfiles de WhatsApp, o
     * con dos teléfonos que llevan al mismo cliente, sale una vez: `meta.total`
     * es la cifra «Leads». `$origin` filtra por la fila de «Origen de leads».
     *
     * Sin teléfono, sin wa_id, sin referral crudo y sin el `ad_id` completo: el
     * anuncio va enmascarado en `ad_ref`. Un toque de pauta dice si su anuncio
     * es de una cuenta conectada (`account_status`) y de cuál (`account_name`).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function leads(
        CarbonInterface $from,
        CarbonInterface $to,
        int $page = 1,
        int $perPage = 20,
        bool $moneyVisible = true,
        ?string $origin = null,
    ): array {
        if ($origin !== null && ! in_array($origin, self::ORIGIN_ORDER, true)) {
            throw new InvalidArgumentException('Origen desconocido.');
        }

        [$from, $to] = $this->bounds($from, $to);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        // El tope va antes de la cuenta: (página - 1) × tamaño no puede desbordarse.
        $page = max(1, min(self::MAX_PAGE, $page));

        // El libro se calcula sobre TODO el periodo y luego se pagina: una
        // persona con dos leads en páginas distintas no puede cobrar dos veces.
        $ctx = $this->context($from, $to);
        $principals = array_flip(array_values($ctx['persons']));

        // `leads` ya va del primer contacto más reciente al más antiguo.
        $people = $ctx['leads']->filter(function (MarketingLead $lead, int $id) use ($principals, $ctx, $origin): bool {
            return isset($principals[$id])
                && ($origin === null || self::originGroup($ctx['firstTouch'][$id]['key']) === $origin);
        });
        $total = $people->count();

        // De qué cuenta es la pauta de cada lead: se mira una vez, no por lead.
        $accounts = [
            'configured' => $this->client->isConfigured(),
            // «No conectada» solo con evidencia para el periodo de la lista, como en el panel.
            'established' => $this->established($this->spend->coverage($from, $to)),
            'names' => MetaAdEntity::accountNames($this->client->accountIds()),
        ];

        $data = $people->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (MarketingLead $lead): array => $this->leadRow($lead, $ctx, $moneyVisible, $accounts))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** La fila de «Origen de leads» de una clave del clasificador. */
    public static function originGroup(string $key): string
    {
        return in_array($key, self::ORIGIN_ORDER, true) ? $key : self::ORIGIN_OTHERS;
    }

    /**
     * «Actualizar»: despacha una pasada de Meta Ads si tiene sentido.
     *
     * Devuelve `queued`, o por qué no: `not_configured` (falta token o cuenta),
     * `running` (ya hay una pasada de alguna cuenta) o `cooldown` (hubo un
     * intento o una petición en los últimos cinco minutos; repetir solo gastaría
     * cuota de Meta). El estado es el consolidado de todas las cuentas
     * ({@see MetaAdsSync::statusAll()}).
     *
     * `sync` es el estado de ANTES de despachar: el panel compara con él para
     * saber cuándo terminó la pasada pedida. Leído después, si el job ya corrió
     * (una cola síncrona, o un worker que lo toma al instante) traería la hora
     * de la pasada nueva, y el panel la esperaría sin fin.
     *
     * @return array{status: string, sync: array<string, mixed>}
     */
    public function requestSync(): array
    {
        $before = $this->sync->statusAll();
        $outcome = $this->syncOutcome($before);

        if ($outcome === 'queued') {
            // Sin cuenta: el job despacha uno por cuenta conectada, y cada una reintenta sola.
            SyncMetaAdsInsights::dispatch(MetaSyncRun::TRIGGER_MANUAL);
        }

        return ['status' => $outcome, 'sync' => $before];
    }

    /**
     * Qué significa cada cifra, para el `title` del panel.
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        $days = ConversionLedger::windowDays();

        // Las frases principales son las del cliente, palabra por palabra; detrás,
        // la precisión que evita malentendidos.
        return [
            'period' => 'Días completos en hora de Bogotá. Todas las cifras usan el mismo periodo, y la comparación usa el mismo número de días justo antes.',
            'spend' => 'Suma del gasto que Meta reporta para el periodo en las cuentas conectadas, sin contar nada dos veces. Sin conexión, o si alguna cuenta conectada no tiene datos del periodo, es «—», nunca $0.',
            'leads' => 'Personas únicas que iniciaron contacto comercial en el periodo. Una persona cuenta una vez aunque escriba desde dos perfiles o teléfonos que llevan al mismo cliente; sin leads de prueba, y quien ya había escrito antes del periodo no cuenta de nuevo.',
            'conversations' => 'Hilos con al menos un mensaje entrante en el periodo. No tiene por qué coincidir con Leads: un lead antiguo puede escribir en el periodo y un lead puede tener varios hilos.',
            'converted' => "Leads que realizaron su primer cobro válido de membresía dentro de la ventana de atribución ({$days} días desde el primer contacto). Un cobro de \$0 no cuenta.",
            'renewals' => "Miembros existentes que realizaron un cobro válido después del contacto comercial (dentro de los {$days} días). Se cuentan aparte de los nuevos clientes.",
            'revenue_attributed' => "Dinero realmente cobrado y vinculado a leads dentro de la ventana ({$days} días): planes y membresías, pagos válidos y abonos aplicados. Sin tienda ni cafetería; una deuda sin cobrar no cuenta, y si un pago se anula o un abono se revierte, sale.",
            'meta_revenue' => 'Dinero cobrado a leads cuyo primer contacto fue un anuncio de una de las cuentas publicitarias conectadas (nuevos clientes y renovaciones). Lo orgánico, lo sin atribuir y la pauta de una cuenta no conectada no entran.',
            'paid' => 'Pauta Meta: personas cuyo primer contacto llegó desde un anuncio de una de las cuentas publicitarias conectadas, que son las del gasto. ROAS, CAC, conversión Meta y retorno usan solo este universo; la pauta de una cuenta no conectada se cuenta aparte, en la cobertura de pauta.',
            'roas' => 'Ingreso atribuible a Meta / gasto Meta. Los dos, de las cuentas conectadas: el mismo universo arriba y abajo. Sin gasto completo y conocido, en otra moneda o en cero, no se calcula.',
            'roas_new' => 'Como el ROAS, pero solo con el dinero de clientes nuevos: sin las renovaciones de quien ya pagaba.',
            'cac' => 'Gasto Meta / nuevos clientes obtenidos desde pauta. Los dos, de las cuentas conectadas. Sin gasto completo y conocido o sin clientes nuevos de pauta, no se calcula.',
            'conversion_rate' => 'Nuevos clientes / leads del mismo universo: todos los leads del periodo.',
            'meta_conversion_rate' => 'Nuevos clientes de pauta / leads de pauta de las cuentas conectadas.',
            'return_after_ad_spend' => 'Ingresos atribuidos a Meta menos el gasto publicitario. No incluye nómina, arriendo ni otros costos operativos.',
            'unattributed' => 'Sin atribuir significa que no existe evidencia técnica suficiente para asignar el lead a una campaña. No se asignan campañas por suposición.',
            'identity' => 'El lead se enlaza con una persona del CRM por su ficha de socio o porque los 10 últimos dígitos de su teléfono coinciden con UNA sola persona. Si coinciden con varias, no se enlaza; también si coinciden una ficha de socio y otra cuenta de usuario distinta: es más prudente que enlazar con la ficha.',
            'first_touch' => 'El origen es el del primer contacto, que no cambia. Sin un anuncio o publicación de Meta que lo pruebe, el lead queda «Sin atribuir».',
            'campaign' => 'La campaña se obtiene del anuncio por la Marketing API de Meta, de las cuentas publicitarias conectadas. Sin conexión, los leads de pauta van a «Sin campaña»; si el anuncio es de una cuenta no conectada, a «Cuenta publicitaria no conectada».',
            'reach' => 'Personas únicas que vieron los anuncios, según Meta. No se suma por días: solo se enseña para los periodos que Meta calculó enteros.',
            'paid_coverage' => 'Cobertura de pauta: de las personas cuyo primer contacto fue un anuncio en el periodo, cuántas llegaron por una de las cuentas publicitarias conectadas. Las de una cuenta no conectada siguen siendo pauta, pero su gasto no se conoce: no entran en Ingresos Meta, ROAS, CAC, Conversión Meta ni Retorno después de pauta.',
        ];
    }

    // ── El universo del periodo ─────────────────────────────────────────────

    /**
     * Los leads del periodo, sus atribuciones clasificadas, las personas de U y
     * el libro de cobros de sus leads principales.
     *
     *  - `leads`: los leads reales cuyo primer contacto cae en el periodo (lo
     *    que enseña la lista), del más reciente al más antiguo.
     *  - `persons`: persona de U (D6) → su lead principal.
     *  - `mainOf`: lead del periodo → lead principal de su persona, que puede
     *    ser de antes del periodo (entonces la persona no es de U).
     *  - `ledger`: el libro de los leads principales de U. Si alguno es del
     *    mismo cliente que un lead anterior al periodo, lleva también ese lead,
     *    solo para saber que no es nuevo: lo que se cuenta sale de U.
     *
     * @return array{leads: Collection<int, MarketingLead>, firstTouch: array<int, array<string, mixed>>,
     *     lastTouch: array<int, array<string, mixed>>, persons: array<string, int>, mainOf: array<int, int>,
     *     ledger: Collection<int, array<string, mixed>>, otherTouch: array<int, array<string, mixed>>}
     */
    private function context(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = LeadUniverse::firstContactSql();

        $leads = LeadUniverse::constrain(MarketingLead::query())
            ->select([
                'id', 'channel', 'source', 'meta_user_id', 'phone', 'name', 'status', 'temperature',
                'member_id', 'first_message_at', 'created_at',
            ])
            ->whereRaw("{$first} >= ?", [$from])
            ->whereRaw("{$first} < ?", [$to])
            ->orderByRaw("{$first} DESC")
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (MarketingLead $lead) => (int) $lead->id);

        $attributions = $this->attributionsFor($leads->keys()->all());

        $firstTouch = [];
        $lastTouch = [];
        foreach ($leads as $id => $lead) {
            $a = $attributions[$id] ?? null;
            $firstTouch[$id] = $this->classifier->classify($a, $lead, 'first');
            $lastTouch[$id] = $this->classifier->classify($a, $lead, 'last');
        }

        // Cada persona (D6) la representa su lead de primer contacto más antiguo
        // de TODA su historia real, no solo del periodo: si no, el lead principal
        // y lo que se le atribuye cambiarían según el periodo elegido.
        $main = [];
        foreach ([...$this->earlierLeadsOfSamePersons($leads, $from), ...$leads->values()->all()] as $lead) {
            $key = LeadUniverse::personKey($lead);
            if (! isset($main[$key]) || $this->isEarlier($lead, $main[$key])) {
                $main[$key] = $lead;
            }
        }

        $persons = [];
        $mainOf = [];
        foreach ($leads as $id => $lead) {
            $principal = $main[LeadUniverse::personKey($lead)];
            $mainOf[$id] = (int) $principal->id;

            // U: la persona entra si su primer contacto de siempre es de este periodo.
            if ($leads->has((int) $principal->id)) {
                $persons[LeadUniverse::personKey($lead)] = (int) $principal->id;
            }
        }

        $principals = $leads->only(array_values($persons))->values();
        $ledger = $this->ledger->forLeads($principals);

        // También ANTES del periodo: quien ya había escrito desde otro teléfono
        // o perfil que lleva al mismo cliente no es nuevo. Con esos leads
        // anteriores, el libro le da el cliente al más antiguo, igual que entre
        // dos leads del periodo. Solo se recalcula si hay alguno.
        $earlier = $this->earlierLeadsOfSameCustomers($ledger, $from);
        if ($earlier !== []) {
            $ledger = $this->ledger->forLeads($principals->merge($earlier));
        }

        // Una PERSONA, una vez, también por su identidad: dos teléfonos (o dos
        // perfiles) que llevan al mismo cliente son la misma persona. El libro
        // se lo da al lead más antiguo (`duplicate_of`), sea del periodo o de
        // antes; el otro sale de U, y sus leads pasan a apuntar a ese lead.
        foreach ($persons as $key => $leadId) {
            $record = $ledger[$leadId] ?? null;
            if (($record['kind'] ?? null) !== ConversionLedger::KIND_DUPLICATE || $record['duplicate_of'] === null) {
                continue;
            }

            unset($persons[$key]);
            foreach ($mainOf as $id => $main) {
                if ($main === $leadId) {
                    $mainOf[$id] = (int) $record['duplicate_of'];
                }
            }
        }

        return [
            'leads' => $leads,
            'firstTouch' => $firstTouch,
            'lastTouch' => $lastTouch,
            'persons' => $persons,
            'mainOf' => $mainOf,
            'ledger' => $ledger,
            'otherTouch' => [],
        ];
    }

    /**
     * Los leads reales con primer contacto ANTERIOR al periodo que son de las
     * mismas personas (D6) que los del periodo: en lote, una consulta por tanda
     * de teléfonos y otra por canal, nunca una por lead.
     *
     * La persona es su teléfono (10 últimos dígitos) o, sin teléfono, su
     * identificador en el canal. En SQL se compara el final del teléfono sin
     * los separadores habituales; el resultado se vuelve a filtrar con
     * {@see LeadUniverse::personKey()}, que es la regla.
     *
     * @param  Collection<int, MarketingLead>  $leads
     * @return list<MarketingLead>
     */
    private function earlierLeadsOfSamePersons(Collection $leads, CarbonImmutable $from): array
    {
        $wanted = [];
        $phones = [];
        $channelIds = [];
        foreach ($leads as $lead) {
            $key = LeadUniverse::personKey($lead);
            $wanted[$key] = true;
            if (str_starts_with($key, 'tel:')) {
                $phones[] = substr($key, 4);
            } elseif (! str_starts_with($key, 'lead:')) {
                $channelIds[(string) $lead->channel][] = (string) $lead->meta_user_id;
            }
        }

        if ($phones === [] && $channelIds === []) {
            return [];
        }

        $first = LeadUniverse::firstContactSql();
        $last10 = LeadUniverse::phoneTailSql('marketing_leads.phone');

        $queries = [];
        foreach (array_chunk(array_values(array_unique($phones)), self::CHUNK) as $chunk) {
            $queries[] = fn ($q) => $q->whereIn(DB::raw($last10), $chunk);
        }
        foreach ($channelIds as $channel => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
                $queries[] = fn ($q) => $q->where('channel', $channel)->whereIn('meta_user_id', $chunk);
            }
        }

        $out = [];
        foreach ($queries as $match) {
            $rows = LeadUniverse::constrain(MarketingLead::query())
                ->select(['id', 'channel', 'meta_user_id', 'phone', 'first_message_at', 'created_at'])
                ->whereRaw("{$first} < ?", [$from])
                ->where($match)
                ->get();

            foreach ($rows as $lead) {
                if (isset($wanted[LeadUniverse::personKey($lead)])) {
                    $out[(int) $lead->id] = $lead;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Los leads reales con primer contacto ANTERIOR al periodo que pueden ser del
     * mismo CLIENTE que una persona de U con otro teléfono o perfil: los
     * enlazados a sus fichas de socio y los que escribieron desde un teléfono de
     * ese cliente (el de su usuario o el de sus fichas). Son candidatos: si de
     * verdad son el mismo cliente lo decide el libro, con la regla de identidad
     * de siempre ({@see LeadIdentityResolver}). En lote, como
     * {@see earlierLeadsOfSamePersons()}.
     *
     * @param  Collection<int, array<string, mixed>>  $ledger  el libro de los principales de U
     * @return list<MarketingLead>
     */
    private function earlierLeadsOfSameCustomers(Collection $ledger, CarbonImmutable $from): array
    {
        $userIds = [];
        $memberIds = [];
        foreach ($ledger as $record) {
            if (! in_array($record['identity'], MarketingLeadIdentity::LINKED, true)) {
                continue;
            }
            if ($record['user_id'] !== null) {
                $userIds[(int) $record['user_id']] = true;
            }
            if ($record['member_id'] !== null) {
                $memberIds[(int) $record['member_id']] = true;
            }
        }

        if ($userIds === [] && $memberIds === []) {
            return [];
        }

        // Los teléfonos del cliente: el de su usuario y los de todas sus fichas.
        $phones = [];
        $known = [];
        $remember = function (?string $phone) use (&$phones): void {
            $digits = LeadUniverse::last10($phone);
            if ($digits !== null) {
                $phones[$digits] = $digits;
            }
        };
        foreach (array_chunk(array_keys($userIds), self::CHUNK) as $ids) {
            foreach (DB::table('users')->whereIn('id', $ids)->get(['phone']) as $user) {
                $remember($user->phone);
            }
            foreach (DB::table('members')->whereIn('user_id', $ids)->get(['id', 'phone']) as $member) {
                $memberIds[(int) $member->id] = true;
                $known[(int) $member->id] = true;
                $remember($member->phone);
            }
        }
        foreach (array_chunk(array_keys(array_diff_key($memberIds, $known)), self::CHUNK) as $ids) {
            foreach (DB::table('members')->whereIn('id', $ids)->get(['phone']) as $member) {
                $remember($member->phone);
            }
        }

        $first = LeadUniverse::firstContactSql();
        $last10 = LeadUniverse::phoneTailSql('marketing_leads.phone');

        $queries = [];
        foreach (array_chunk(array_keys($memberIds), self::CHUNK) as $ids) {
            $queries[] = fn ($q) => $q->whereIn('member_id', $ids);
        }
        foreach (array_chunk(array_values($phones), self::CHUNK) as $chunk) {
            $queries[] = fn ($q) => $q->whereIn(DB::raw($last10), $chunk);
        }

        $out = [];
        foreach ($queries as $match) {
            $rows = LeadUniverse::constrain(MarketingLead::query())
                ->select(['id', 'channel', 'meta_user_id', 'phone', 'member_id', 'first_message_at', 'created_at'])
                ->whereRaw("{$first} < ?", [$from])
                ->where($match)
                ->get();

            foreach ($rows as $lead) {
                $out[(int) $lead->id] = $lead;
            }
        }

        return array_values($out);
    }

    /**
     * Atribuciones de estos leads, con la dimensión de sus anuncios precargada.
     *
     * @param  list<int>  $leadIds
     * @return array<int, MarketingLeadAttribution>
     */
    private function attributionsFor(array $leadIds): array
    {
        $out = [];
        $adIds = [];

        foreach (array_chunk($leadIds, self::CHUNK) as $ids) {
            MarketingLeadAttribution::query()
                ->whereIn('marketing_lead_id', $ids)
                ->get([
                    'id', 'marketing_lead_id', 'source_type', 'source_platform', 'ad_id', 'source_url',
                    'first_touch_at', 'first_touch_source_type', 'first_touch_ad_id',
                    'last_touch_at', 'last_touch_source_type', 'last_touch_ad_id',
                ])
                ->each(function (MarketingLeadAttribution $a) use (&$out, &$adIds): void {
                    $out[(int) $a->marketing_lead_id] = $a;
                    foreach ([$a->first_touch_ad_id, $a->last_touch_ad_id, $a->ad_id] as $adId) {
                        if (filled($adId)) {
                            $adIds[] = (string) $adId;
                        }
                    }
                });
        }

        $this->classifier->preload($adIds);

        return $out;
    }

    /**
     * Conversaciones distintas con algún mensaje ENTRANTE en el periodo, por
     * lead real. Una conversación es de un solo lead, así que la suma por lead
     * es el total de conversaciones distintas.
     *
     * De paso clasifica el primer toque de los leads que conversan pero no son
     * de U, para repartir sus conversaciones por campaña.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<int, int>
     */
    private function conversationsByLead(CarbonImmutable $from, CarbonImmutable $to, array &$ctx): array
    {
        $query = DB::table('marketing_messages as m')
            ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('marketing_leads as l', 'l.id', '=', 'c.lead_id')
            ->where('m.direction', MarketingMessage::DIRECTION_INBOUND)
            ->where('m.created_at', '>=', $from)
            ->where('m.created_at', '<', $to);

        $rows = LeadUniverse::constrain($query, 'l.source')
            ->groupBy('c.lead_id')
            ->selectRaw('c.lead_id as lead_id, COUNT(DISTINCT m.conversation_id) as n')
            ->get();

        $byLead = [];
        foreach ($rows as $r) {
            $byLead[(int) $r->lead_id] = (int) $r->n;
        }

        $outside = array_values(array_diff(array_keys($byLead), array_keys($ctx['firstTouch'])));
        if ($outside !== []) {
            $attributions = $this->attributionsFor($outside);
            foreach (array_chunk($outside, self::CHUNK) as $ids) {
                foreach (MarketingLead::query()->whereIn('id', $ids)->get(['id', 'channel']) as $lead) {
                    $ctx['otherTouch'][(int) $lead->id] = $this->classifier->classify($attributions[(int) $lead->id] ?? null, $lead, 'first');
                }
            }
        }

        return $byLead;
    }

    // ── Cifras ──────────────────────────────────────────────────────────────

    /**
     * Las cifras principales, por persona de U (su lead principal).
     *
     * `paid` es toda la pauta; `paid_resolved`, la de anuncios de las cuentas
     * conectadas, que es el universo de ROAS, ROAS de clientes nuevos y CAC (el
     * gasto es el consolidado de esas mismas cuentas). `unresolved_paid_leads`
     * cuenta las personas de pauta que no son de ninguna, y `roas_warning` lo
     * avisa cuando el gasto se conoce.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, int>  $conversations
     * @param  array<string, mixed>  $spend
     * @return array<string, mixed>
     */
    private function kpis(array $ctx, array $conversations, array $spend, bool $moneyVisible): array
    {
        $converted = 0;
        $renewals = 0;
        $revenueNew = 0.0;
        $revenueRenewal = 0.0;
        $paid = ['leads' => 0, 'converted' => 0, 'revenue' => 0.0];
        $resolved = ['leads' => 0, 'converted' => 0, 'revenue' => 0.0, 'revenue_new' => 0.0, 'renewals' => 0, 'revenue_renewal' => 0.0];

        // Una persona, una vez: todo se cuenta sobre su lead principal.
        foreach ($ctx['persons'] as $leadId) {
            $touch = $ctx['firstTouch'][$leadId];
            $fromAds = $touch['origin'] === LeadSourceClassifier::ORIGIN_PAID;
            // Pauta de una cuenta conectada: las únicas cuyo gasto se conoce.
            $fromAccount = $fromAds && $touch['resolved'];
            $record = $ctx['ledger'][$leadId];

            $paid['leads'] += $fromAds ? 1 : 0;
            $resolved['leads'] += $fromAccount ? 1 : 0;

            if ($record['kind'] === ConversionLedger::KIND_NEW) {
                $converted++;
                $revenueNew += $record['revenue'];
                $paid['converted'] += $fromAds ? 1 : 0;
                $resolved['converted'] += $fromAccount ? 1 : 0;
                $resolved['revenue_new'] += $fromAccount ? $record['revenue'] : 0.0;
            } elseif ($record['kind'] === ConversionLedger::KIND_RENEWAL) {
                $renewals++;
                $revenueRenewal += $record['revenue'];
                $resolved['renewals'] += $fromAccount ? 1 : 0;
                $resolved['revenue_renewal'] += $fromAccount ? $record['revenue'] : 0.0;
            } else {
                continue;
            }

            $paid['revenue'] += $fromAds ? $record['revenue'] : 0.0;
            $resolved['revenue'] += $fromAccount ? $record['revenue'] : 0.0;
        }

        $leads = count($ctx['persons']);
        $paid['revenue'] = round($paid['revenue'], 2);
        $resolved['revenue'] = round($resolved['revenue'], 2);
        $resolved['revenue_new'] = round($resolved['revenue_new'], 2);
        $resolved['revenue_renewal'] = round($resolved['revenue_renewal'], 2);
        $unresolved = $paid['leads'] - $resolved['leads'];

        $spendProblem = $this->spendProblem($spend);
        $roasReason = $spendProblem;
        $cacReason = $spendProblem ?? ($resolved['converted'] === 0 ? 'no_paid_conversions' : null);

        // Retorno = ingresos Meta − gasto Meta. Una resta, no una división: un
        // gasto de cero comprobado vale; uno incompleto o desconocido, no.
        $returnReason = $this->returnProblem($spend);
        // Sin pauta ni ingresos de la cuenta, la resta da 0, pero no hubo nada que
        // equilibrar: «Punto de equilibrio» diría lo que no pasó.
        if ($returnReason === null && abs((float) $spend['amount']) < 0.005 && abs($resolved['revenue']) < 0.005) {
            $returnReason = self::NO_AD_ACTIVITY;
        }
        $return = $returnReason === null ? round($resolved['revenue'] - (float) $spend['amount'], 2) : null;

        // Hay leads de pauta que el gasto de las cuentas conectadas no cubre: el
        // ROAS no los incluye, y hay que decirlo.
        $spendKnown = in_array($spend['status'], [MetaSpendReader::OK, MetaSpendReader::STALE, MetaSpendReader::REAL_ZERO], true);

        $kpis = [
            'leads' => $leads,
            'conversations' => array_sum($conversations),
            'converted' => $converted,
            'renewals' => $renewals,
            'revenue_new' => round($revenueNew, 2),
            'revenue_renewal' => round($revenueRenewal, 2),
            'revenue_attributed' => round($revenueNew + $revenueRenewal, 2),
            'revenue_reason' => null,
            // Por qué falta «Ingresos Meta» (paid_resolved.revenue): sin permiso o sin cobertura histórica.
            'meta_revenue_reason' => null,
            'currency' => self::REVENUE_CURRENCY,
            'paid' => $paid,
            'paid_resolved' => $resolved,
            'unresolved_paid_leads' => $unresolved,
            'roas' => $roasReason === null ? round($resolved['revenue'] / $spend['amount'], 2) : null,
            'roas_reason' => $roasReason,
            'roas_new' => $roasReason === null ? round($resolved['revenue_new'] / $spend['amount'], 2) : null,
            'roas_new_reason' => $roasReason,
            'roas_warning' => $unresolved > 0 && $spendKnown ? self::WARNING_UNRESOLVED_PAID_LEADS : null,
            'cac' => $cacReason === null ? round($spend['amount'] / $resolved['converted'], 2) : null,
            'cac_reason' => $cacReason,
            // Cada conversión con su universo: nuevos de TODOS los leads entre
            // todos los leads; nuevos de pauta entre los leads de pauta.
            'conversion_rate' => $leads > 0 ? round($converted / $leads, 4) : null,
            'meta_conversion_rate' => $resolved['leads'] > 0 ? round($resolved['converted'] / $resolved['leads'], 4) : null,
            'return_after_ad_spend' => $return,
            'return_after_ad_spend_reason' => $returnReason,
            'return_state' => $return === null ? null : match (true) {
                $return > 0 => 'positive',
                $return < 0 => 'negative',
                default => 'breakeven',
            },
            'money_visible' => $moneyVisible,
        ];

        return $moneyVisible ? $kpis : $this->withoutMoney($kpis);
    }

    /**
     * Los KPI sin dinero, para quien no tiene visión completa: los importes y lo
     * que se calcula con ellos van en null con el motivo `forbidden`; los
     * conteos se quedan.
     *
     * @param  array<string, mixed>  $kpis
     * @return array<string, mixed>
     */
    private function withoutMoney(array $kpis): array
    {
        foreach (['revenue_new', 'revenue_renewal', 'revenue_attributed', 'roas', 'roas_new', 'cac', 'return_after_ad_spend', 'return_state'] as $field) {
            $kpis[$field] = null;
        }
        foreach (['revenue_reason', 'meta_revenue_reason', 'roas_reason', 'roas_new_reason', 'cac_reason', 'return_after_ad_spend_reason'] as $field) {
            $kpis[$field] = self::MONEY_FORBIDDEN;
        }
        $kpis['paid']['revenue'] = null;
        $kpis['paid_resolved']['revenue'] = null;
        $kpis['paid_resolved']['revenue_new'] = null;
        $kpis['paid_resolved']['revenue_renewal'] = null;
        $kpis['money_visible'] = false;

        return $kpis;
    }

    /**
     * Por qué el gasto no sirve para dividir, o null si sirve.
     *
     * @param  array<string, mixed>  $spend
     */
    private function spendProblem(array $spend): ?string
    {
        // Un importe de una parte del periodo daría un ROAS y un CAC que parecen
        // del periodo y no lo son. Va primero: sea cual sea el estado de la
        // última pasada, el motivo es el mismo.
        if ($spend['amount'] !== null && ($spend['complete'] ?? true) === false) {
            return self::SPEND_INCOMPLETE;
        }

        if (! in_array($spend['status'], [MetaSpendReader::OK, MetaSpendReader::STALE], true)) {
            return 'spend_'.$spend['status'];
        }

        if ($spend['amount'] === null || $spend['amount'] <= 0) {
            return 'spend_zero';
        }

        // Sin conversión de monedas inventada: si Meta factura en otra, no se divide.
        if (strtoupper((string) $spend['currency']) !== self::REVENUE_CURRENCY) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * Por qué no se puede restar el gasto a los ingresos de pauta, o null si se
     * puede. A diferencia de dividir, un gasto de cero comprobado sirve.
     *
     * @param  array<string, mixed>  $spend
     */
    private function returnProblem(array $spend): ?string
    {
        if ($spend['amount'] !== null && ($spend['complete'] ?? true) === false) {
            return self::SPEND_INCOMPLETE;
        }

        if (! in_array($spend['status'], [MetaSpendReader::OK, MetaSpendReader::STALE, MetaSpendReader::REAL_ZERO], true)) {
            return 'spend_'.$spend['status'];
        }

        if ($spend['amount'] === null) {
            return 'spend_unknown';
        }

        if ($spend['amount'] > 0 && strtoupper((string) $spend['currency']) !== self::REVENUE_CURRENCY) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * Las cifras secundarias, cada una con su alcance: `period` si es del
     * periodo, `now` si es una foto del momento.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, array{value: int, scope: string}> (en `dashboard()`, `value` null sin cobertura)
     */
    private function secondary(array $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Personas de U con algún lead caliente del periodo. Por su lead principal
        // (`mainOf`), no por el teléfono: el perfil que se fundió con otro por
        // identidad ya no tiene clave propia en `persons`, pero sigue siendo de
        // esa persona.
        $principals = array_flip(array_values($ctx['persons']));
        $hot = [];
        foreach ($ctx['leads'] as $id => $lead) {
            $main = $ctx['mainOf'][$id];
            if ($lead->temperature === MarketingLead::STATUS_HOT && isset($principals[$main])) {
                $hot[$main] = true;
            }
        }

        $real = LeadUniverse::constrain(DB::table('marketing_leads')->select('marketing_leads.id'));

        return [
            'hot_leads' => ['value' => count($hot), 'scope' => 'period'],
            'pending_followups' => [
                'value' => MarketingFollowup::query()
                    ->where('status', MarketingFollowup::STATUS_PENDING)
                    ->whereIn('lead_id', $real)
                    ->count(),
                'scope' => 'now',
            ],
            'ai_actions' => [
                'value' => MarketingAiAction::query()
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<', $to)
                    ->whereIn('lead_id', $real)
                    ->count(),
                'scope' => 'period',
            ],
            'human_control' => [
                'value' => MarketingConversation::query()
                    ->where('human_takeover', true)
                    ->whereIn('lead_id', $real)
                    ->count(),
                'scope' => 'now',
            ],
        ];
    }

    /**
     * «Origen de leads»: personas, cuota, convertidos e ingresos por primer toque.
     *
     * Solo las categorías con evidencia (con algún lead), en el orden de
     * {@see ORIGIN_ORDER}. Lo que no es de la lista principal va a «Otros», con
     * `includes` = las etiquetas de lo que agrupa. «Directo» solo aparece si
     * algo lo prueba: no se deduce de que falte el referral.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{0: list<array<string, mixed>>, 1: ?float}
     */
    private function origins(array $ctx, bool $moneyVisible): array
    {
        $rows = [];
        $includes = [];
        $row = function (string $key) use (&$rows): void {
            $rows[$key] ??= $this->originRow($key);
        };

        foreach ($ctx['persons'] as $leadId) {
            $key = $ctx['firstTouch'][$leadId]['key'];
            $group = self::originGroup($key);
            $row($group);
            $rows[$group]['leads']++;
            if ($group === self::ORIGIN_OTHERS) {
                $includes[LeadSourceClassifier::labelFor($key)] = true;
            }
        }

        // El libro solo trae los leads principales de U: una persona, una vez.
        foreach ($ctx['persons'] as $leadId) {
            $record = $ctx['ledger'][$leadId];
            if (! in_array($record['kind'], [ConversionLedger::KIND_NEW, ConversionLedger::KIND_RENEWAL], true)) {
                continue;
            }

            $group = self::originGroup($ctx['firstTouch'][$leadId]['key']);
            $rows[$group]['converted'] += $record['kind'] === ConversionLedger::KIND_NEW ? 1 : 0;
            $rows[$group]['revenue'] += $record['revenue'];
        }

        $total = count($ctx['persons']);
        $out = [];
        foreach (self::ORIGIN_ORDER as $key) {
            if (! isset($rows[$key])) {
                continue;
            }
            $r = $rows[$key];
            $r['share'] = $total > 0 ? round($r['leads'] / $total, 4) : null;
            $r['revenue'] = $moneyVisible ? round($r['revenue'], 2) : null;
            if ($key === self::ORIGIN_OTHERS) {
                $r['includes'] = array_keys($includes);
            }
            $out[] = $r;
        }

        $unattributed = $total > 0
            ? round(($rows[LeadSourceClassifier::KEY_UNATTRIBUTED]['leads'] ?? 0) / $total, 4)
            : null;

        return [$out, $unattributed];
    }

    /** @return array{key: string, label: string, leads: int, share: ?float, converted: int, revenue: float} */
    private function originRow(string $key): array
    {
        return [
            'key' => $key,
            'label' => $key === self::ORIGIN_OTHERS ? 'Otros' : LeadSourceClassifier::labelFor($key),
            'leads' => 0,
            'share' => null,
            'converted' => 0,
            'revenue' => 0.0,
        ];
    }

    /**
     * La entidad de este nivel a la que lleva un primer toque de pauta, o null
     * si el anuncio no se resolvió contra ninguna cuenta conectada.
     *
     * @param  array<string, mixed>  $touch
     * @return array{0: ?string, 1: ?string} id y nombre
     */
    private function entityOf(string $level, array $touch): array
    {
        return match ($level) {
            MetaAdEntity::LEVEL_CAMPAIGN => [$touch['campaign_id'], $touch['campaign_name']],
            MetaAdEntity::LEVEL_ADSET => [$touch['adset_id'], $touch['adset_name']],
            default => [$touch['resolved'] ? $touch['ad_id'] : null, $touch['ad_name']],
        };
    }

    /**
     * Filas de campaña, conjunto o anuncio: el gasto de Meta y lo que trajeron
     * las personas cuyo PRIMER toque fue ese anuncio. Cada fila dice de qué
     * cuenta es (`account_id` opaco, `account_ref` y `account_name`).
     *
     * Las entidades a las que llevan los leads salen aunque no gastaran en el
     * periodo, y su gasto lo decide el lector de gasto ({@see MetaSpendReader::byLevel()}):
     * 0,00 comprobado si es de una cuenta conectada y el periodo de esa cuenta
     * está cubierto; null si no se sabe. Nunca un cero por defecto.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, int>  $conversations
     * @param  array{since: ?string, state: string}  $history
     * @param  string  $unresolvedName  el de {@see unresolvedName()} para el periodo
     * @return list<array<string, mixed>>
     */
    private function campaignRows(
        string $level,
        array $ctx,
        array $conversations,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $moneyVisible,
        ?array $parent,
        array $history,
        string $unresolvedName,
    ): array {
        // Con padre, solo cuenta lo que es de él: un toque de otra campaña (o de
        // otro conjunto) no es de esta fila, y uno sin resolver no es de ninguna.
        $ofParent = function (array $touch) use ($parent): bool {
            if ($parent === null) {
                return true;
            }

            return $touch['resolved'] && match ($parent['level']) {
                MetaAdEntity::LEVEL_CAMPAIGN => $touch['campaign_id'] === $parent['id'],
                default => $touch['adset_id'] === $parent['id'],
            };
        };

        // Las entidades a las que llevan las personas y las conversaciones.
        $include = [];
        foreach ([...array_values($ctx['persons']), ...array_keys($conversations)] as $leadId) {
            $touch = $ctx['firstTouch'][$leadId] ?? $ctx['otherTouch'][$leadId] ?? null;
            if ($touch !== null && $touch['origin'] === LeadSourceClassifier::ORIGIN_PAID && $ofParent($touch)) {
                [$entity] = $this->entityOf($level, $touch);
                if ($entity !== null) {
                    $include[$entity] = true;
                }
            }
        }

        // Qué reporta Meta, por cuenta: una métrica que la cuenta de la fila no
        // reporta es null en esa fila, aunque otra cuenta sí la reporte.
        $available = $this->spend->availabilityByAccount();
        $rows = [];
        foreach ($this->spend->byLevel($level, $from, $to, array_keys($include), $parent) as $s) {
            $rows[$s['id']] = $this->campaignRow($s['id'], $s['name'], $s['status'], $s, $available[$s['account_id']] ?? [], $s['account_id']);
        }

        $lookups = [];
        $bucket = function (?array $touch) use (&$rows, &$lookups, $level, $unresolvedName, $ofParent): ?string {
            if ($touch === null || $touch['origin'] !== LeadSourceClassifier::ORIGIN_PAID || ! $ofParent($touch)) {
                return null;
            }

            [$entity, $name] = $this->entityOf($level, $touch);

            $id = $entity ?? self::UNRESOLVED;
            if (! isset($rows[$id])) {
                // Sin fila del lector (Meta Ads sin conexión): las cifras de Meta no se saben.
                $rows[$id] = $entity === null
                    ? $this->campaignRow(null, $unresolvedName, null, null, [])
                    : $this->campaignRow($entity, $name, null, null, [], $touch['account_id'] ?? null);
                if ($entity !== null) {
                    $lookups[] = $entity;
                }
            }

            return $id;
        };

        $this->tally($rows, $ctx, $conversations, $bucket);
        $this->completeFromDimension($level, $rows, $lookups);
        $rows = $this->withDerived($rows);

        uasort($rows, function (array $a, array $b): int {
            // «Sin campaña» siempre al final; el resto, por gasto, leads y nombre.
            if (($a['entity'] === null) !== ($b['entity'] === null)) {
                return $a['entity'] === null ? 1 : -1;
            }

            return [$b['spend'] ?? -1, $b['leads'], (string) $a['name']] <=> [$a['spend'] ?? -1, $a['leads'], (string) $b['name']];
        });

        $rows = array_values($rows);
        $names = MetaAdEntity::accountNames($this->client->accountIds());

        return $this->rowsWithoutCoverage(
            array_map(fn (array $row): array => $this->publicCampaignRow($level, $row, $moneyVisible, $names), $rows),
            $history,
            array_map(fn (array $row): ?string => $row['last_day'], $rows),
        );
    }

    /**
     * Cuenta en cada fila lo que trajeron las personas de U (leads, nuevos,
     * renovaciones e ingresos, sobre su lead principal: una persona, una vez) y
     * las conversaciones del periodo. `$bucket` dice a qué fila lleva un toque
     * (y la crea si hace falta), o null si a ninguna.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $ctx
     * @param  array<int, int>  $conversations
     * @param  callable(?array<string, mixed>): ?string  $bucket
     */
    private function tally(array &$rows, array $ctx, array $conversations, callable $bucket): void
    {
        foreach ($ctx['persons'] as $leadId) {
            if (($id = $bucket($ctx['firstTouch'][$leadId])) !== null) {
                $rows[$id]['leads']++;
            }
        }

        foreach ($ctx['persons'] as $leadId) {
            $record = $ctx['ledger'][$leadId];
            if (! in_array($record['kind'], [ConversionLedger::KIND_NEW, ConversionLedger::KIND_RENEWAL], true)) {
                continue;
            }
            if (($id = $bucket($ctx['firstTouch'][$leadId])) !== null) {
                $rows[$id]['converted'] += $record['kind'] === ConversionLedger::KIND_NEW ? 1 : 0;
                $rows[$id]['renewals'] += $record['kind'] === ConversionLedger::KIND_RENEWAL ? 1 : 0;
                $rows[$id]['revenue'] += $record['revenue'];
            }
        }

        foreach ($conversations as $leadId => $n) {
            $touch = $ctx['firstTouch'][$leadId] ?? $ctx['otherTouch'][$leadId] ?? null;
            if (($id = $bucket($touch)) !== null) {
                $rows[$id]['conversations'] += $n;
            }
        }
    }

    /**
     * Lo que se calcula con las sumas de cada fila: ROAS, CAC, conversión,
     * retorno, CTR, CPM, CPC y costo por conversación. Las mismas reglas para
     * campañas, conjuntos, anuncios y cuentas.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int|string, array<string, mixed>>
     */
    private function withDerived(array $rows): array
    {
        foreach ($rows as $id => $row) {
            $spend = $row['spend'];
            // Gasto que se puede afirmar para TODO el periodo y en pesos: lo único
            // con que se divide o se resta.
            $complete = $spend !== null && ! $row['partial']
                && ($spend == 0.0 || strtoupper((string) $row['currency']) === self::REVENUE_CURRENCY);
            $divisible = $complete && $spend > 0;
            $ratio = fn (?int $part, int|float|null $whole, int $digits = 4): ?float => $part !== null && $whole !== null && $whole > 0
                ? round($part / $whole, $digits)
                : null;

            $rows[$id]['revenue'] = round($row['revenue'], 2);
            $rows[$id]['roas'] = $divisible ? round($row['revenue'] / $spend, 2) : null;
            $rows[$id]['cac'] = $divisible && $row['converted'] > 0 ? round($spend / $row['converted'], 2) : null;
            $rows[$id]['conversion_rate'] = $row['leads'] > 0 ? round($row['converted'] / $row['leads'], 4) : null;
            // Sin gasto ni ingresos en la fila no hay retorno que dar (como en el KPI).
            $active = $spend > 0 || abs($row['revenue']) >= 0.005;
            $rows[$id]['return_after_ad_spend'] = $complete && $active ? round($row['revenue'] - $spend, 2) : null;
            // Derivadas de las sumas del periodo, no medias de los ratios diarios.
            $rows[$id]['ctr'] = $ratio($row['clicks'], $row['impressions']);
            $rows[$id]['cpm'] = $spend !== null && $row['impressions'] !== null && $row['impressions'] > 0
                ? round($spend / $row['impressions'] * 1000, 2) : null;
            $rows[$id]['cpc'] = $spend !== null && $row['clicks'] !== null && $row['clicks'] > 0
                ? round($spend / $row['clicks'], 2) : null;
            $rows[$id]['cost_per_conversation'] = $spend !== null && $row['meta_conversations'] !== null && $row['meta_conversations'] > 0
                ? round($spend / $row['meta_conversations'], 2) : null;
        }

        return $rows;
    }

    /**
     * Una fila con las cifras de Meta del lector (`$s`, null si no se saben) y los
     * contadores del CRM a cero. Una métrica que Meta no reporta para la cuenta
     * queda en null, nunca en 0.
     *
     * @param  array<string, mixed>|null  $s
     * @param  array<string, bool>  $available
     * @param  string|null  $account  la cuenta conectada de la fila (cruda), si se sabe
     * @return array<string, mixed>
     */
    private function campaignRow(?string $entity, ?string $name, ?string $status, ?array $s, array $available, ?string $account = null): array
    {
        $metric = fn (string $key, ?string $flag = null): ?int => $s !== null && $s[$key] !== null && ($flag === null || ($available[$flag] ?? false))
            ? (int) $s[$key]
            : null;

        return [
            'entity' => $entity,
            'name' => $name,
            'status' => $status,
            'account' => $account,
            'spend' => $s['spend'] ?? null,
            'currency' => $s['currency'] ?? null,
            'partial' => (bool) ($s['partial'] ?? false),
            'impressions' => $metric('impressions'),
            'reach' => $s['reach'] ?? null,
            'clicks' => $metric('clicks'),
            'link_clicks' => $metric('link_clicks'),
            'meta_conversations' => $metric('messaging_started', 'messaging_started'),
            'meta_replies' => $metric('messaging_replied', 'messaging_replied'),
            'landing_page_views' => $metric('landing_page_views', 'landing_page_views'),
            // El último día con actividad en Meta dentro del periodo (no sale en la respuesta).
            'last_day' => $s['last_day'] ?? null,
            'leads' => 0,
            'conversations' => 0,
            'converted' => 0,
            'renewals' => 0,
            'revenue' => 0.0,
        ];
    }

    /**
     * Nombre y estado de las entidades que entraron por sus leads y no por su
     * gasto. Una sola consulta.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $entityIds
     */
    private function completeFromDimension(string $level, array &$rows, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }

        $found = MetaAdEntity::query()
            ->forConfiguredAccount()
            ->where('level', $level)
            ->whereIn('entity_id', $entityIds)
            ->get(['entity_id', 'name', 'effective_status'])
            ->keyBy('entity_id');

        foreach ($entityIds as $entity) {
            $e = $found->get($entity);
            if ($e === null || ! isset($rows[$entity])) {
                continue;
            }
            $rows[$entity]['name'] ??= $e->name;
            $rows[$entity]['status'] ??= $e->effective_status;
        }
    }

    /**
     * La fila tal como sale: el id de Meta se sustituye por una clave opaca y
     * estable, y solo se enseña enmascarado; la cuenta, igual. Sin visión
     * completa, sin dinero.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, ?string>  $names  nombre de cada cuenta conectada
     * @return array<string, mixed>
     */
    private function publicCampaignRow(string $level, array $row, bool $moneyVisible, array $names = []): array
    {
        $entity = $row['entity'];
        $account = $row['account'] ?? null;
        $face = $account === null ? null : MetaAdEntity::publicAccount($account, $names[$account] ?? null);

        return [
            'id' => $entity === null ? self::UNRESOLVED : self::opaqueId($level, $entity),
            'ref' => LeadSourceClassifier::maskId($entity),
            'name' => LeadSourceClassifier::maskDigits($row['name']),
            'status' => $row['status'],
            'level' => $level,
            // «Sin campaña» no tiene conjuntos que abrir, y un anuncio no tiene hijos.
            'has_children' => $entity !== null && $level !== MetaAdEntity::LEVEL_AD,
            'spend' => $row['spend'],
            'currency' => $row['currency'],
            'partial' => $row['partial'],
            'impressions' => $row['impressions'],
            'reach' => $row['reach'],
            'clicks' => $row['clicks'],
            'link_clicks' => $row['link_clicks'],
            'meta_conversations' => $row['meta_conversations'],
            'meta_replies' => $row['meta_replies'],
            'landing_page_views' => $row['landing_page_views'],
            'cpm' => $row['cpm'],
            'ctr' => $row['ctr'],
            'cpc' => $row['cpc'],
            'cost_per_conversation' => $row['cost_per_conversation'],
            'leads' => $row['leads'],
            'conversations' => $row['conversations'],
            'converted' => $row['converted'],
            'renewals' => $row['renewals'],
            'revenue' => $moneyVisible ? $row['revenue'] : null,
            'cac' => $moneyVisible ? $row['cac'] : null,
            'roas' => $moneyVisible ? $row['roas'] : null,
            'conversion_rate' => $row['conversion_rate'],
            'return_after_ad_spend' => $moneyVisible ? $row['return_after_ad_spend'] : null,
            // La cuenta de la fila; null en la de la pauta sin cuenta conectada.
            'account_id' => $face['id'] ?? null,
            'account_ref' => $face['ref'] ?? null,
            'account_name' => $face['name'] ?? null,
        ];
    }

    /**
     * La tabla por CUENTA conectada (el primer nivel del desglose) y, aparte,
     * la pauta cuya cuenta no está conectada.
     *
     *  - `rows`: una fila por cuenta conectada, de mayor a menor gasto y luego
     *    por nombre, con las columnas de una fila de campaña más `covered_to`,
     *    `campaign_count` (sus campañas en `campaigns.rows`) y `has_children`.
     *    Lo de Meta sale de {@see MetaSpendReader::accounts()}; los recuentos
     *    del CRM, de las personas cuyo anuncio es de esa cuenta. Mismas reglas
     *    que las campañas: dinero oculto, gasto parcial, cobertura histórica y
     *    sin retorno cuando no hubo ni gasto ni ingresos.
     *  - `unconnected`: id, nombre y recuentos de la pauta sin cuenta conectada.
     *    Su nombre es el de {@see unresolvedName()}: «Cuenta publicitaria no
     *    conectada» solo con evidencia; si no, el neutro. SIN gasto, ingresos,
     *    ROAS, CAC ni retorno: no se conocen y no se inventan. Null si no hay
     *    nada que contar en ella, o si Meta no está configurado (lo dice ya la
     *    fila «Sin campaña (Meta Ads sin conexión)»).
     *
     * Sin Meta configurado, sin filas: el panel enseña la tabla de campañas.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, int>  $conversations
     * @param  array{since: ?string, state: string}  $history
     * @param  list<array<string, mixed>>  $campaignRows  las filas públicas de campaña del mismo periodo
     * @return array{rows: list<array<string, mixed>>, unconnected: ?array<string, mixed>}
     */
    private function accountTable(
        array $ctx,
        array $conversations,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $moneyVisible,
        array $history,
        array $campaignRows,
        string $unresolvedName,
    ): array {
        if (! $this->client->isConfigured()) {
            return ['rows' => [], 'unconnected' => null];
        }

        $available = $this->spend->availabilityByAccount();
        $rows = [];
        foreach ($this->spend->accounts($from, $to) as $s) {
            $account = (string) $s['account'];
            $rows[$account] = $this->campaignRow($account, $s['name'], null, $s, $available[$account] ?? [], $account)
                + ['covered_to' => $s['covered_to']];
        }

        $bucket = function (?array $touch) use (&$rows, $unresolvedName): ?string {
            if ($touch === null || $touch['origin'] !== LeadSourceClassifier::ORIGIN_PAID) {
                return null;
            }

            if ($touch['resolved'] && $touch['account_id'] !== null) {
                $account = (string) $touch['account_id'];
                // Toda cuenta conectada tiene su fila; si faltara, sin cifras de Meta inventadas.
                $rows[$account] ??= $this->campaignRow($account, null, null, null, [], $account) + ['covered_to' => null];

                return $account;
            }

            $rows[self::UNCONNECTED] ??= $this->campaignRow(null, $unresolvedName, null, null, []);

            return self::UNCONNECTED;
        };

        $this->tally($rows, $ctx, $conversations, $bucket);

        $unconnected = $rows[self::UNCONNECTED] ?? null;
        unset($rows[self::UNCONNECTED]);

        $rows = $this->withDerived($rows);
        uasort($rows, fn (array $a, array $b): int => [$b['spend'] ?? -1, (string) $a['name']] <=> [$a['spend'] ?? -1, (string) $b['name']]);
        $rows = array_values($rows);

        $campaigns = [];
        foreach ($campaignRows as $c) {
            if (($c['account_id'] ?? null) !== null) {
                $campaigns[$c['account_id']] = ($campaigns[$c['account_id']] ?? 0) + 1;
            }
        }

        $public = array_map(function (array $row) use ($moneyVisible, $campaigns): array {
            $out = $this->publicCampaignRow(MetaAdEntity::LEVEL_ACCOUNT, $row, $moneyVisible);
            // Una cuenta no es «de una cuenta»: sus campos de cuenta sobran.
            unset($out['account_id'], $out['account_ref'], $out['account_name']);
            $count = $campaigns[$out['id']] ?? 0;
            $out['covered_to'] = $row['covered_to'];
            $out['campaign_count'] = $count;
            $out['has_children'] = $count > 0;

            return $out;
        }, $rows);

        return [
            'rows' => $this->rowsWithoutCoverage($public, $history, array_map(fn (array $row): ?string => $row['last_day'], $rows)),
            'unconnected' => $unconnected === null ? null : $this->rowsWithoutCoverage([[
                'id' => self::UNCONNECTED,
                'name' => $unconnected['name'],
                'leads' => $unconnected['leads'],
                'conversations' => $unconnected['conversations'],
                'converted' => $unconnected['converted'],
                'renewals' => $unconnected['renewals'],
            ]], $history, [null])[0],
        ];
    }

    /**
     * «Cobertura de pauta»: de las personas de pauta del periodo
     * (`kpis.paid.leads`), cuántas llegaron por una cuenta conectada
     * (`kpis.paid_resolved.leads`) y cuántas por una que no lo está
     * (`kpis.unresolved_paid_leads`), la cuota con cuenta conectada y
     * `established` ({@see established()}).
     *
     * Sin cobertura histórica, los recuentos en null (un 0 no sería una
     * medición). Sin evidencia (`established = false`: Meta sin configurar,
     * alguna cuenta sin ninguna pasada buena o el periodo sin cubrir entero),
     * el total sí, pero ni los de un lado ni los del otro: un anuncio que no
     * está en la dimensión podría ser de una cuenta conectada.
     *
     * @param  array<string, mixed>  $kpis  ya sin lo que la cobertura histórica no permite
     * @return array{total: ?int, connected: ?int, unconnected: ?int, share: ?float, established: bool}
     */
    private function paidCoverage(array $kpis, string $historyState, bool $established): array
    {
        if ($historyState === 'none') {
            return ['total' => null, 'connected' => null, 'unconnected' => null, 'share' => null, 'established' => $established];
        }

        $total = $kpis['paid']['leads'] ?? null;
        if (! $established) {
            return ['total' => $total, 'connected' => null, 'unconnected' => null, 'share' => null, 'established' => false];
        }

        $connected = $kpis['paid_resolved']['leads'] ?? null;

        return [
            'total' => $total,
            'connected' => $connected,
            'unconnected' => $kpis['unresolved_paid_leads'] ?? null,
            'share' => $total !== null && $total > 0 && $connected !== null ? round($connected / $total, 4) : null,
            'established' => true,
        ];
    }

    /**
     * El resumen para administración: las MISMAS cifras de las tarjetas, nunca
     * otras (inversión, adquisición, retorno y cobertura), y la conclusión con
     * su motivo y su recomendación ({@see conclusion()}).
     *
     * `roi` = (ingresos − gasto) / gasto, con 4 decimales; null cuando el ROAS
     * es null (el gasto no sirve para dividir, no hay permiso o no hay
     * cobertura): las mismas condiciones.
     *
     * @param  array<string, mixed>  $spend  el gasto consolidado
     * @param  array<string, mixed>  $kpis  ya sin dinero y sin lo que la cobertura histórica no permite
     * @param  array{total: ?int, connected: ?int, unconnected: ?int, share: ?float, established: bool}  $paidCoverage
     * @param  array{since: ?string, state: string}  $history
     * @return array<string, mixed>
     */
    private function executive(array $spend, array $kpis, array $paidCoverage, array $history, CarbonImmutable $periodEnd, bool $established): array
    {
        $revenue = $kpis['paid_resolved']['revenue'] ?? null;
        $amount = $spend['amount'];
        $roi = $kpis['roas'] !== null && $revenue !== null && $amount !== null && $amount > 0
            ? round(($revenue - $amount) / $amount, 4)
            : null;

        return [
            'investment' => [
                'spend' => $amount,
                'currency' => $spend['currency'],
                'complete' => $spend['complete'],
            ],
            'acquisition' => [
                'paid_leads' => $paidCoverage['total'],
                'connected_paid_leads' => $paidCoverage['connected'],
                'new_customers' => $kpis['paid_resolved']['converted'] ?? null,
                'renewals' => $kpis['paid_resolved']['renewals'] ?? null,
                'conversion_rate' => $kpis['meta_conversion_rate'],
                'cac' => $kpis['cac'],
            ],
            'return' => [
                'revenue' => $revenue,
                'roas' => $kpis['roas'],
                'roi' => $roi,
            ],
            'coverage' => [
                'connected' => $paidCoverage['connected'],
                'unconnected' => $paidCoverage['unconnected'],
                'share' => $paidCoverage['share'],
            ],
            'conclusion' => $this->conclusion($spend, $kpis, $paidCoverage, $history, $periodEnd, $established),
        ];
    }

    /**
     * ¿Fue rentable la pauta de las cuentas conectadas en el periodo? Con la
     * caja real, y en este orden:
     *
     *  1. sin permiso para ver ingresos → no concluyente;
     *  2. sin Meta, o con el gasto desconocido o incompleto → no concluyente
     *     (y con el gasto en otra moneda que la caja, tampoco: no se comparan);
     *  3. el periodo empieza antes de la cobertura histórica → no concluyente;
     *  4. gasto 0 → no concluyente (con o sin ingresos: no hubo inversión);
     *  5. ingresos ≥ gasto → sí;
     *  6. ingresos < gasto con la ventana de atribución abierta → no concluyente
     *     (aún pueden llegar pagos);
     *  7. ingresos < gasto con la ventana cerrada → no;
     *  8. si hay leads de pauta de una cuenta no conectada (solo con evidencia,
     *     {@see established()}), se añade al motivo y a la recomendación.
     *
     * @param  array<string, mixed>  $spend
     * @param  array<string, mixed>  $kpis
     * @param  array{total: ?int, connected: ?int, unconnected: ?int, share: ?float, established: bool}  $paidCoverage
     * @param  array{since: ?string, state: string}  $history
     * @return array{profitable: string, reason: string, recommendation: string}
     */
    private function conclusion(array $spend, array $kpis, array $paidCoverage, array $history, CarbonImmutable $periodEnd, bool $established): array
    {
        $verdict = fn (string $profitable, string $reason, string $recommendation): array => [
            'profitable' => $profitable,
            'reason' => $reason,
            'recommendation' => $recommendation,
        ];
        $inconclusive = fn (string $reason, string $recommendation): array => $verdict(self::PROFITABLE_INCONCLUSIVE, $reason, $recommendation);

        $amount = $spend['amount'];
        $revenue = $kpis['paid_resolved']['revenue'] ?? null;
        $currency = strtoupper((string) $spend['currency']);

        $base = match (true) {
            ($kpis['money_visible'] ?? false) !== true => $inconclusive(
                'Sin permiso para ver ingresos.',
                'Pedir la conclusión a un administrador que pueda ver los ingresos.',
            ),
            ! $this->client->isConfigured(), $amount === null, ($spend['complete'] ?? true) === false => $inconclusive(
                'El gasto de Meta del periodo no está completo o no se conoce.',
                'Revisar la sincronización de Meta Ads.',
            ),
            $amount > 0 && $currency !== self::REVENUE_CURRENCY => $inconclusive(
                'El gasto de Meta está en otra moneda ('.($currency !== '' ? $currency : 'sin moneda').'): no se puede comparar con la caja en pesos.',
                'Revisar la moneda de las cuentas publicitarias.',
            ),
            $history['state'] !== 'full' => $history['since'] !== null
                ? $inconclusive(
                    'El periodo empieza antes del '.$this->shortDate($history['since']).', cuando el CRM empezó a captar leads.',
                    'Elige un periodo desde el '.$this->shortDate($history['since']).'.',
                )
                : $inconclusive(
                    'El CRM todavía no capta leads de forma continua: no hay con qué medir el periodo.',
                    'Revisar la captación de leads del CRM.',
                ),
            abs((float) $amount) < 0.005 => $revenue !== null && $revenue >= 0.005
                ? $inconclusive(
                    'Hubo ingresos de leads de pauta, pero ningún gasto en el periodo: sus anuncios gastaron antes.',
                    'Mirar un periodo más largo, que incluya el gasto de esos anuncios.',
                )
                : $inconclusive(
                    'Sin inversión ni ingresos de las cuentas conectadas en el periodo.',
                    'Elegir un periodo con pauta activa.',
                ),
            // Defensa: con todo lo anterior descartado, ingresos y ROAS tienen cifra.
            $revenue === null || $kpis['roas'] === null => $inconclusive(
                'No se puede calcular el retorno del periodo.',
                'Revisar la sincronización de Meta Ads.',
            ),
            $revenue >= $amount => $verdict(
                self::PROFITABLE_YES,
                'Los leads de pauta de las cuentas conectadas pagaron '.$this->cop($revenue).' en caja frente a '.$this->cop($amount).' de gasto (ROAS '.$this->times($kpis['roas']).').',
                'Mantener la inversión y vigilar el CAC.',
            ),
            $periodEnd->addDays(ConversionLedger::windowDays())->greaterThan(now()) => $inconclusive(
                'Por ahora los ingresos ('.$this->cop($revenue).') no cubren el gasto ('.$this->cop($amount).'), pero la ventana de '
                    .ConversionLedger::windowDays().' días sigue abierta: aún pueden llegar pagos.',
                'Revisar de nuevo cuando cierre la ventana (el '
                    .$this->shortDate($periodEnd->addDays(ConversionLedger::windowDays())->setTimezone(self::timezone())->toDateString()).').',
            ),
            default => $verdict(
                self::PROFITABLE_NO,
                'Los ingresos en caja ('.$this->cop($revenue).') no cubren el gasto ('.$this->cop($amount).'): ROAS '.$this->times($kpis['roas']).'.',
                'Revisar las campañas con gasto y sin clientes nuevos.',
            ),
        };

        // Solo se puede afirmar que son de una cuenta no conectada con evidencia.
        $unconnected = (int) ($paidCoverage['unconnected'] ?? 0);
        $total = (int) ($paidCoverage['total'] ?? 0);
        if ($established && $unconnected > 0 && $total > 0) {
            $share = (int) round($unconnected / $total * 100);
            // Con leads a los dos lados, el redondeo no borra a nadie: nunca «0 %» ni «100 %» (como el front).
            if ($unconnected < $total) {
                $share = min(99, max(1, $share));
            }
            $base['reason'] .= ' No incluye '.$unconnected.($unconnected === 1 ? ' lead' : ' leads')
                .' ('.$share.' %) de una cuenta publicitaria no conectada.';
            $base['recommendation'] .= ' Conectar esa cuenta para medir el resto.';
        }

        return $base;
    }

    /** Pesos sin decimales, como los escribe el panel: «$ 329.199». */
    private function cop(float|int $value): string
    {
        return '$ '.number_format(round((float) $value), 0, ',', '.');
    }

    /** Un ROAS como «12,04×». */
    private function times(float|int $value): string
    {
        return number_format((float) $value, 2, ',', '.').'×';
    }

    /** Un día AAAA-MM-DD como «16 sept 2026». */
    private function shortDate(string $day): string
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $day, self::timezone());

        return $d->day.' '.self::SHORT_MONTHS[$d->month - 1].' '.$d->year;
    }

    /**
     * El nombre de la fila de la pauta que no resuelve contra ninguna cuenta
     * conectada, que dice lo que se sabe:
     *  - sin Meta Ads configurado, «sin conexión»;
     *  - con evidencia ({@see established()}), «Cuenta publicitaria no conectada»;
     *  - si no, el neutro «aún sin sincronizar»: el anuncio podría ser de una
     *    cuenta conectada cuyos días no se han sincronizado.
     */
    private function unresolvedName(bool $established): string
    {
        return match (true) {
            ! $this->client->isConfigured() => self::UNRESOLVED_NAME,
            $established => self::UNRESOLVED_NAME_OTHER_ACCOUNT,
            default => self::UNRESOLVED_NAME_NEVER_SYNCED,
        };
    }

    /**
     * ¿Se puede afirmar, en el periodo, que un anuncio de pauta que no está en
     * la dimensión de ninguna cuenta conectada es de una cuenta NO conectada?
     * Solo con evidencia:
     *  - Meta Ads configurado;
     *  - TODAS las cuentas conectadas con al menos una pasada buena (si no, su
     *    dimensión está vacía);
     *  - y el gasto del periodo cubierto entero en todas ({@see MetaSpendReader::coverage()}):
     *    la dimensión sale de las filas de gasto, y la pasada horaria mira 7
     *    días; un anuncio que solo entregó antes no está aunque sea de una
     *    cuenta conectada.
     *
     * @param  array{complete: bool}  $coverage  la cobertura consolidada del gasto del periodo
     */
    private function established(array $coverage): bool
    {
        if (! $this->client->isConfigured() || $coverage['complete'] !== true) {
            return false;
        }

        foreach ($this->client->accountIds() as $account) {
            // Sin ninguna pasada buena (ni de relleno) la dimensión de esa cuenta está vacía.
            if ($this->sync->forAccount($account)->coverage() === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * El padre de un desglose, a partir de su clave opaca: la campaña de los
     * conjuntos o el conjunto de los anuncios. Solo de las cuentas conectadas;
     * null si no se reconoce.
     *
     * @return array{level: string, id: string}|null
     */
    private function resolveParent(string $level, string $parentId): ?array
    {
        $parentLevel = match ($level) {
            MetaAdEntity::LEVEL_ADSET => MetaAdEntity::LEVEL_CAMPAIGN,
            MetaAdEntity::LEVEL_AD => MetaAdEntity::LEVEL_ADSET,
            default => null,
        };

        if ($parentLevel === null || ! str_starts_with($parentId, $parentLevel.':')) {
            return null;
        }

        foreach (MetaAdEntity::query()->forConfiguredAccount()->where('level', $parentLevel)->pluck('entity_id') as $id) {
            if (hash_equals(self::opaqueId($parentLevel, (string) $id), $parentId)) {
                return ['level' => $parentLevel, 'id' => (string) $id];
            }
        }

        return null;
    }

    /**
     * Una página de filas con lo que el panel necesita para decir si los datos de
     * Meta son parciales.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{known: bool, complete: bool, through: ?string}  $coverage
     * @return array{rows: list<array<string, mixed>>, partial: bool, covered_to: ?string,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    private function tableOf(array $rows, array $coverage, int $page, int $perPage): array
    {
        $total = count($rows);
        // Sin paginar (la tabla entera de un nivel, que llega sin tope) la página
        // es el total: un PHP_INT_MAX en el JSON no lo representa bien JavaScript.
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = max(1, $total);
        }
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $partial = $coverage['known'] && ! $coverage['complete'];

        return [
            'rows' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'partial' => $partial,
            'covered_to' => $partial ? $coverage['through'] : null,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * El primer día (AAAA-MM-DD, Bogotá) en que el CRM capta de verdad leads y
     * referrals, o null si todavía no. Por configuración
     * (`marketing.attribution.coverage_since`) o deducido de los datos (ver
     * deducedCoverageSince()). Diez minutos en caché, también el «todavía no»;
     * la clave lleva la configuración, así que cambiarla vale al momento.
     */
    public function coverageSince(): ?string
    {
        $fixed = trim((string) config('marketing.attribution.coverage_since'));

        // null no se guarda en caché: «todavía no» va como ''.
        $since = Cache::remember(self::COVERAGE_CACHE_KEY.':'.md5($fixed), 600, function () use ($fixed): string {
            if ($fixed !== '') {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $fixed, self::timezone());
                // createFromFormat acepta el 31 de septiembre y lo corre a octubre: esa no es la fecha que se fijó.
                if ($day !== false && $day->format('Y-m-d') === $fixed) {
                    return $fixed;
                }
                // Una fecha mal escrita no mueve la cobertura en silencio: se avisa y se deduce.
                Log::warning('marketing.attribution.coverage_since no es un día AAAA-MM-DD válido: se deduce de los datos.', ['value' => $fixed]);
            }

            return $this->deducedCoverageSince() ?? '';
        });

        return $since === '' ? null : $since;
    }

    /**
     * El primer día del primer tramo SOSTENIDO de días en que el CRM captó
     * leads al llegar: leads reales cuyo registro de atribución (el que deja la
     * captura del referral) se escribió a menos de un día de su primer
     * contacto. Ni un lead viejo que vuelve a escribir ni uno que recupera el
     * relleno cuentan, así que la fecha no retrocede. Tramo sostenido: huecos de hasta 7 días, al menos 7
     * días de duración y al menos 4 días distintos con captación. Los leads
     * sueltos de antes (pruebas, una importación) no abren la cobertura.
     */
    private function deducedCoverageSince(): ?string
    {
        $tz = self::timezone();
        $first = LeadUniverse::firstContactSql();
        $days = LeadUniverse::constrain(MarketingLead::query())
            ->join('marketing_lead_attributions', 'marketing_lead_attributions.marketing_lead_id', '=', 'marketing_leads.id')
            // La hora en que el CRM escribió el registro, no la del toque: el relleno la pone en el pasado.
            ->selectRaw("{$first} as first_contact, marketing_lead_attributions.created_at as captured_at")
            ->toBase()
            ->get()
            ->map(fn (object $r): array => [
                CarbonImmutable::parse((string) $r->first_contact, 'UTC'),
                CarbonImmutable::parse((string) $r->captured_at, 'UTC'),
            ])
            ->filter(fn (array $at): bool => abs($at[1]->getTimestamp() - $at[0]->getTimestamp()) <= self::COVERAGE_CAPTURE_TOLERANCE_SECONDS)
            ->map(fn (array $at): string => $at[0]->setTimezone($tz)->toDateString())
            ->unique()
            ->sort()
            ->values();

        $start = null;
        $prev = null;
        $active = 0;
        foreach ($days as $day) {
            $d = CarbonImmutable::parse($day);
            if ($prev === null || $prev->diffInDays($d) > self::COVERAGE_MAX_GAP_DAYS) {
                $start = $d;
                $active = 0;
            }
            $prev = $d;
            $active++;
            if ($start->diffInDays($d) >= self::COVERAGE_MIN_RUN_DAYS && $active >= self::COVERAGE_MIN_ACTIVE_DAYS) {
                return $start->toDateString();
            }
        }

        return null;
    }

    /**
     * Cuánto del periodo `[from, to)` cubre la captación del CRM: `full`,
     * `partial` (empieza antes y termina después) o `none`.
     *
     * @return array{since: ?string, state: string}
     */
    private function coverageOf(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $since = $this->coverageSince();
        if ($since === null) {
            return ['since' => null, 'state' => 'none'];
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $since, self::timezone())->utc();

        return ['since' => $since, 'state' => match (true) {
            $from->greaterThanOrEqualTo($start) => 'full',
            $to->lessThanOrEqualTo($start) => 'none',
            default => 'partial',
        }];
    }

    /**
     * Los KPI sin lo que no es una medición. Con días sin captación dentro
     * (`partial`), lo atribuible a la pauta (ingresos Meta, ROAS, CAC,
     * conversión Meta y retorno) va en null con su motivo: el gasto de esos días
     * no tiene leads con los que compararse. Sin ninguna captación (`none`),
     * tampoco los recuentos comerciales: un 0 diría que se midió.
     *
     * @param  array<string, mixed>  $kpis
     * @return array<string, mixed>
     */
    private function withoutCoverage(array $kpis, string $state): array
    {
        if ($state === 'full') {
            return $kpis;
        }

        $reason = $state === 'none' ? self::NO_HISTORICAL_COVERAGE : self::PARTIAL_HISTORICAL_COVERAGE;
        // Un importe que el usuario no puede ver sigue diciendo `forbidden`.
        $because = fn (?string $current): string => $current === self::MONEY_FORBIDDEN ? $current : $reason;
        foreach (['roas', 'roas_new', 'cac', 'meta_conversion_rate', 'return_after_ad_spend', 'return_state'] as $field) {
            $kpis[$field] = null;
        }
        foreach (['roas_reason', 'roas_new_reason', 'cac_reason', 'return_after_ad_spend_reason', 'meta_revenue_reason'] as $field) {
            $kpis[$field] = $because($kpis[$field] ?? null);
        }
        foreach (['revenue', 'revenue_new', 'revenue_renewal'] as $field) {
            if (is_array($kpis['paid_resolved'] ?? null)) {
                $kpis['paid_resolved'][$field] = null;
            }
        }

        if ($state === 'none') {
            foreach (['leads', 'conversations', 'converted', 'renewals', 'revenue_new', 'revenue_renewal', 'revenue_attributed', 'conversion_rate', 'attributable_share', 'unresolved_paid_leads', 'roas_warning'] as $field) {
                $kpis[$field] = null;
            }
            $kpis['revenue_reason'] = $because($kpis['revenue_reason'] ?? null);
            $kpis['paid'] = array_map(fn () => null, is_array($kpis['paid'] ?? null) ? $kpis['paid'] : []);
            $kpis['paid_resolved'] = array_map(fn () => null, is_array($kpis['paid_resolved'] ?? null) ? $kpis['paid_resolved'] : []);
        }

        return $kpis;
    }

    /**
     * Las filas de campaña, conjunto o anuncio sin lo atribuible cuando el
     * periodo no está cubierto entero. Los recuentos del CRM tampoco son una
     * medición sin ninguna cobertura, ni en una fila cuya actividad en Meta
     * acabó antes de la cobertura y no tiene nada captado: su «0» no se midió.
     * Lo que viene de Meta (gasto, impresiones…) se queda: es de Meta.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{since: ?string, state: string}  $history
     * @param  list<?string>  $lastDays  el último día con actividad en Meta de cada fila
     * @return list<array<string, mixed>>
     */
    private function rowsWithoutCoverage(array $rows, array $history, array $lastDays): array
    {
        if ($history['state'] === 'full') {
            return $rows;
        }

        $counts = ['leads', 'conversations', 'converted', 'renewals'];

        return array_map(function (array $row, ?string $lastDay) use ($history, $counts): array {
            $fields = ['revenue', 'roas', 'cac', 'conversion_rate', 'return_after_ad_spend'];
            $nothingCaptured = array_sum(array_map(fn (string $f): int => (int) ($row[$f] ?? 0), $counts)) === 0;
            $endedBefore = $lastDay !== null && $history['since'] !== null && $lastDay < $history['since'];
            if ($history['state'] === 'none' || ($endedBefore && $nothingCaptured)) {
                $fields = [...$fields, ...$counts];
            }
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $row[$field] = null;
                }
            }

            return $row;
        }, $rows, $lastDays);
    }

    /**
     * El periodo anterior equivalente: el mismo número de días de Bogotá, justo
     * antes del elegido. Para «30 días», los 30 anteriores.
     *
     * @param  array{from: string, to: string}  $period
     * @return array{from: string, to: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    private function previousPeriod(array $period): array
    {
        $tz = self::timezone();
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $period['from'], $tz);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $period['to'], $tz);
        $days = (int) $first->diffInDays($last) + 1;

        $prevLast = $first->subDay();
        $prevFirst = $prevLast->subDays($days - 1);

        return [
            'from' => $prevFirst->toDateString(),
            'to' => $prevLast->toDateString(),
            'start' => $prevFirst->utc(),
            'end' => $first->utc(),
        ];
    }

    /**
     * Las cifras del periodo anterior que el panel compara. Mismo cálculo que el
     * periodo elegido (mismo universo, mismo libro, mismas reglas de gasto), sin
     * tablas ni listas.
     *
     * @param  array{from: string, to: string, start: CarbonImmutable, end: CarbonImmutable}  $previous
     * @return array<string, mixed>
     */
    private function previousSummary(array $previous, bool $moneyVisible): array
    {
        [$from, $to] = $this->bounds($previous['start'], $previous['end']);

        $ctx = $this->context($from, $to);
        $spend = $this->spend->snapshot($from, $to);
        // kpis() suma las conversaciones por lead; aquí basta el total.
        $k = $this->kpis($ctx, [$this->conversationCount($from, $to)], $spend, $moneyVisible);
        // Un periodo anterior sin cobertura entera no es una base: frente a sus
        // días sin captación, cualquier periodo cubierto «crecería». Sin
        // comparación, mejor que un +400 % que no pasó. Su gasto sí se compara: es de Meta.
        $covered = $this->coverageOf($from, $to)['state'] === 'full';

        return [
            'period' => ['from' => $previous['from'], 'to' => $previous['to']],
            'spend' => [
                'status' => $spend['status'],
                'amount' => $spend['amount'],
                'currency' => $spend['currency'],
                'complete' => $spend['complete'],
            ],
            'kpis' => array_map(fn (mixed $v): mixed => $covered ? $v : null, [
                'leads' => $k['leads'],
                'conversations' => $k['conversations'],
                'converted' => $k['converted'],
                'renewals' => $k['renewals'],
                'revenue_attributed' => $k['revenue_attributed'],
                'meta_leads' => $k['paid_resolved']['leads'],
                'meta_converted' => $k['paid_resolved']['converted'],
                'meta_revenue' => $k['paid_resolved']['revenue'],
                'roas' => $k['roas'],
                'cac' => $k['cac'],
                'conversion_rate' => $k['conversion_rate'],
                'meta_conversion_rate' => $k['meta_conversion_rate'],
                'return_after_ad_spend' => $k['return_after_ad_spend'],
            ]),
        ];
    }

    /** Conversaciones distintas de leads reales con algún mensaje entrante en `[from, to)`: una consulta. */
    private function conversationCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = DB::table('marketing_messages as m')
            ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('marketing_leads as l', 'l.id', '=', 'c.lead_id')
            ->where('m.direction', MarketingMessage::DIRECTION_INBOUND)
            ->where('m.created_at', '>=', $from)
            ->where('m.created_at', '<', $to);

        return (int) LeadUniverse::constrain($query, 'l.source')->distinct()->count('m.conversation_id');
    }

    /**
     * Clave pública y estable de una cuenta, una campaña, un conjunto o un
     * anuncio ({@see MetaAdEntity::opaqueId()}: HMAC con la clave de la
     * aplicación, para que no se pueda invertir desde el `ref`).
     */
    public static function opaqueId(string $level, string $entity): string
    {
        return MetaAdEntity::opaqueId($level, $entity);
    }

    /**
     * Un lead de la lista. Lo que NO sale: teléfono, wa_id, referral crudo,
     * `ad_id` completo e identidad financiera.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array{configured: bool, established: bool, names: array<string, ?string>}  $accounts
     * @return array<string, mixed>
     */
    private function leadRow(MarketingLead $lead, array $ctx, bool $moneyVisible, array $accounts): array
    {
        $id = (int) $lead->id;
        $main = $ctx['mainOf'][$id];

        // Si no es el lead principal de su persona, su compra la cuenta el otro.
        $conversion = $main !== $id
            ? ['kind' => ConversionLedger::KIND_DUPLICATE, 'revenue' => 0.0, 'duplicate_of' => $main]
            : [
                'kind' => $ctx['ledger'][$id]['kind'],
                'revenue' => $ctx['ledger'][$id]['revenue'],
                'duplicate_of' => $ctx['ledger'][$id]['duplicate_of'],
            ];

        if (! $moneyVisible) {
            $conversion['revenue'] = null;
        }

        return [
            'id' => $id,
            // Hay quien pone su número o su correo como nombre de perfil de WhatsApp.
            'name' => LeadSourceClassifier::maskPersonName($lead->name),
            'channel' => $lead->channel,
            'first_touch' => $this->touchView($ctx['firstTouch'][$id], $accounts),
            'last_touch' => $this->touchView($ctx['lastTouch'][$id], $accounts),
            'temperature' => $lead->temperature,
            'status' => $lead->status,
            'conversion' => $conversion,
            'first_contact_at' => LeadUniverse::firstContactAt($lead)->toIso8601String(),
        ];
    }

    /**
     * Un toque tal como sale. En uno de pauta, `account_status`:
     *  - `connected`: el anuncio es de una cuenta conectada (`account_name`, la
     *    suya, o null si aún no se leyó);
     *  - `unconnected`: con evidencia de que no es de ninguna ({@see established()}:
     *    todas las cuentas con alguna pasada buena y el periodo cubierto entero);
     *  - null: no es pauta, Meta no está configurado o aún no se puede afirmar.
     *
     * @param  array<string, mixed>  $touch
     * @param  array{configured: bool, established: bool, names: array<string, ?string>}  $accounts
     * @return array<string, mixed>
     */
    private function touchView(array $touch, array $accounts): array
    {
        $status = match (true) {
            $touch['origin'] !== LeadSourceClassifier::ORIGIN_PAID, ! $accounts['configured'] => null,
            $touch['resolved'] => 'connected',
            $accounts['established'] => 'unconnected',
            default => null,
        };
        $account = $status === 'connected' ? ($touch['account_id'] ?? null) : null;

        return [
            'origin' => $touch['origin'],
            'platform' => $touch['platform'],
            'key' => $touch['key'],
            'label' => LeadSourceClassifier::labelFor($touch['key']),
            'campaign_name' => LeadSourceClassifier::maskDigits($touch['campaign_name']),
            'adset_name' => LeadSourceClassifier::maskDigits($touch['adset_name']),
            'ad_name' => LeadSourceClassifier::maskDigits($touch['ad_name']),
            'ad_ref' => LeadSourceClassifier::maskId($touch['ad_id']),
            'account_status' => $status,
            'account_name' => $account === null ? null : LeadSourceClassifier::maskDigits($accounts['names'][$account] ?? null),
        ];
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function bounds(CarbonInterface $from, CarbonInterface $to): array
    {
        // En UTC: así se guardan los timestamps y así se formatean los bindings.
        return [CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()];
    }

    private function isEarlier(MarketingLead $a, MarketingLead $b): bool
    {
        $x = LeadUniverse::firstContactAt($a);
        $y = LeadUniverse::firstContactAt($b);

        return $x->lessThan($y) || ($x->equalTo($y) && (int) $a->id < (int) $b->id);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function customDays(?string $from, ?string $to, string $tz): array
    {
        $first = $this->day($from, 'from', $tz);
        $last = $this->day($to, 'to', $tz);

        if ($last->lessThan($first)) {
            throw new InvalidArgumentException('La fecha final es anterior a la inicial.');
        }

        if ($first->diffInDays($last) + 1 > self::MAX_CUSTOM_DAYS) {
            throw new InvalidArgumentException('El periodo personalizado no puede pasar de '.self::MAX_CUSTOM_DAYS.' días.');
        }

        return [$first, $last];
    }

    private function day(?string $raw, string $field, string $tz): CarbonImmutable
    {
        $raw = trim((string) $raw);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $raw, $tz)
            : false;

        // createFromFormat acepta el 31 de febrero y lo corre a marzo; esa no es
        // la fecha que se pidió.
        if ($day === false || $day->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException("Falta o no es válida la fecha «{$field}» (AAAA-MM-DD) del periodo personalizado.");
        }

        return $day;
    }

    /** @param  array<string, mixed>  $status  {@see MetaAdsSync::status()} */
    private function syncOutcome(array $status): string
    {
        if (! $this->client->isConfigured()) {
            return 'not_configured';
        }

        if ($status['status'] === MetaSyncRun::STATUS_RUNNING) {
            return 'running';
        }

        $lastAttempt = $status['last_attempt_at'] !== null ? CarbonImmutable::parse($status['last_attempt_at']) : null;
        if ($lastAttempt !== null && $lastAttempt->greaterThan(now()->subSeconds(self::SYNC_COOLDOWN_SECONDS))) {
            return 'cooldown';
        }

        // Atómico: dos clics casi a la vez no despachan dos pasadas.
        if (! Cache::add(self::SYNC_COOLDOWN_KEY, now()->toIso8601String(), self::SYNC_COOLDOWN_SECONDS)) {
            return 'cooldown';
        }

        return 'queued';
    }
}
