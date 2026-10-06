<?php

namespace App\Services\Marketing\Attribution;

use App\Jobs\SyncMetaAdsInsights;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
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
 * fue un anuncio de la cuenta publicitaria CONFIGURADA (`kpis.paid_resolved`),
 * que es la única cuyo gasto se conoce. Así no se atribuye a los anuncios lo
 * que vendió lo orgánico, ni a una cuenta lo que trajo otra. Sin gasto
 * conocido, en otra moneda o en cero, valen null con su motivo: nunca se
 * inventa un número. Si hay leads de pauta que no se resuelven contra la cuenta
 * y el gasto se conoce, `roas_warning` lo avisa.
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

    /** La fila de los leads de pauta cuyo anuncio no está en la dimensión de la cuenta configurada. */
    public const UNRESOLVED = 'unresolved';

    /** Su nombre si Meta Ads no está conectado: no hay dimensión contra la que resolver. */
    public const UNRESOLVED_NAME = 'Sin campaña (Meta Ads sin conexión)';

    /** Su nombre si Meta Ads está conectado: el anuncio no es de la cuenta configurada (o no se ha sincronizado). */
    public const UNRESOLVED_NAME_OTHER_ACCOUNT = 'Sin campaña (anuncio fuera de la cuenta conectada)';

    /** Motivo de un importe que el usuario no puede ver. */
    public const MONEY_FORBIDDEN = 'forbidden';

    /** Aviso del ROAS: hay leads de pauta cuyo anuncio no es de la cuenta configurada. */
    public const WARNING_UNRESOLVED_PAID_LEADS = 'unresolved_paid_leads';

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

        return [
            'period' => [
                'key' => $period['key'],
                'from' => $period['from'],
                'to' => $period['to'],
                'timezone' => $period['timezone'],
            ],
            'spend' => $spend,
            'kpis' => $this->kpis($ctx, $conversations, $spend, $moneyVisible),
            'secondary' => $this->secondary($ctx, $from, $to),
            'origins' => $origins,
            'unattributed_share' => $unattributedShare,
            'campaigns' => [
                'level' => MetaAdEntity::LEVEL_CAMPAIGN,
                'rows' => $this->campaignRows(MetaAdEntity::LEVEL_CAMPAIGN, $ctx, $conversations, $from, $to, $moneyVisible),
            ],
            'attribution' => [
                'model' => 'first_touch',
                'window_days' => ConversionLedger::windowDays(),
                'definitions' => self::definitions(),
            ],
            'sync' => $this->sync->status(),
        ];
    }

    /**
     * La tabla de campañas, conjuntos o anuncios del periodo `[from, to)`.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(string $level, CarbonInterface $from, CarbonInterface $to, bool $moneyVisible = true): array
    {
        if (! in_array($level, MetaAdEntity::LEVELS, true)) {
            throw new InvalidArgumentException('Nivel desconocido: usa campaign, adset o ad.');
        }

        [$from, $to] = $this->bounds($from, $to);
        $ctx = $this->context($from, $to);
        $conversations = $this->conversationsByLead($from, $to, $ctx);

        return $this->campaignRows($level, $ctx, $conversations, $from, $to, $moneyVisible);
    }

    /**
     * Los leads reales cuyo primer contacto cae en el periodo, del más reciente
     * al más antiguo. Un lead que no es el principal de su persona sale como
     * `duplicate`, con `duplicate_of` (que puede ser de antes del periodo).
     *
     * Sin teléfono, sin wa_id, sin referral crudo y sin el `ad_id` completo: el
     * anuncio va enmascarado en `ad_ref`.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function leads(CarbonInterface $from, CarbonInterface $to, int $page = 1, int $perPage = 20, bool $moneyVisible = true): array
    {
        [$from, $to] = $this->bounds($from, $to);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        // El tope va antes de la cuenta: (página - 1) × tamaño no puede desbordarse.
        $page = max(1, min(self::MAX_PAGE, $page));

        // El libro se calcula sobre TODO el periodo y luego se pagina: una
        // persona con dos leads en páginas distintas no puede cobrar dos veces.
        $ctx = $this->context($from, $to);
        $total = $ctx['leads']->count();

        $data = $ctx['leads']->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (MarketingLead $lead): array => $this->leadRow($lead, $ctx, $moneyVisible))
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

    /**
     * «Actualizar»: despacha una pasada de Meta Ads si tiene sentido.
     *
     * Devuelve `queued`, o por qué no: `not_configured` (falta token o cuenta),
     * `running` (ya hay una pasada) o `cooldown` (hubo un intento o una petición
     * en los últimos cinco minutos; repetir solo gastaría cuota de Meta).
     *
     * @return array{status: string, sync: array<string, mixed>}
     */
    public function requestSync(): array
    {
        $outcome = $this->syncOutcome();

        if ($outcome === 'queued') {
            SyncMetaAdsInsights::dispatch(MetaSyncRun::TRIGGER_MANUAL);
        }

        return ['status' => $outcome, 'sync' => $this->sync->status()];
    }

    /**
     * Qué significa cada cifra, para el `title` del panel.
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        $days = ConversionLedger::windowDays();

        return [
            'period' => 'Días completos en hora de Bogotá. Todas las cifras usan el mismo periodo.',
            'leads' => 'Personas distintas cuyo primer contacto (el primero de toda su historia) cayó en el periodo. Sin leads de prueba, y el mismo teléfono cuenta una vez: quien ya había escrito antes del periodo no cuenta de nuevo.',
            'conversations' => 'Conversaciones de leads reales con al menos un mensaje entrante en el periodo. No tiene por qué coincidir con Leads: un lead antiguo puede escribir en el periodo y un lead puede tener varias conversaciones.',
            'converted' => "Personas del periodo que no habían pagado nunca y pagaron (más de \$0) en los {$days} días siguientes a su primer contacto. Cada persona convierte una vez.",
            'renewals' => "Personas del periodo que ya habían pagado antes de escribir y volvieron a pagar en los {$days} días siguientes. Se cuentan aparte, no como convertidos.",
            'revenue_attributed' => "Dinero cobrado de planes y membresías (pagos válidos y abonos aplicados, de más de \$0) en los {$days} días siguientes al primer contacto. Sin tienda ni cafetería, y una deuda sin cobrar no cuenta. Si un pago se anula o un abono se revierte, sale.",
            'paid' => 'Universo pauta: personas cuyo primer contacto llegó desde un anuncio de Meta. Para ROAS y CAC solo cuentan las de anuncios de la cuenta publicitaria conectada, que es la del gasto.',
            'roas' => 'Ingresos de las personas de pauta de la cuenta conectada entre el gasto de esa cuenta en el periodo. Sin gasto conocido, en otra moneda o en cero, no se calcula.',
            'roas_new' => 'Como el ROAS, pero solo con el dinero de clientes nuevos: sin las renovaciones de quien ya pagaba.',
            'cac' => 'Gasto en Meta Ads entre clientes nuevos de pauta de la cuenta conectada. Sin gasto conocido o sin clientes nuevos de pauta, no se calcula.',
            'conversion_rate' => 'Convertidos entre leads.',
            'identity' => 'El lead se enlaza con una persona del CRM por su ficha de socio o porque los 10 últimos dígitos de su teléfono coinciden con UNA sola persona. Si coinciden con varias, no se enlaza; también si coinciden una ficha de socio y otra cuenta de usuario distinta: es más prudente que enlazar con la ficha.',
            'first_touch' => 'El origen es el del primer contacto, que no cambia. Sin un anuncio o publicación de Meta que lo pruebe, el lead queda «Sin atribuir».',
            'campaign' => 'La campaña se obtiene del anuncio por la Marketing API de Meta, solo de la cuenta publicitaria conectada. Sin esa conexión, o si el anuncio es de otra cuenta, los leads de pauta van a «Sin campaña».',
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
     *  - `ledger`: el libro, SOLO de los leads principales de U.
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

        return [
            'leads' => $leads,
            'firstTouch' => $firstTouch,
            'lastTouch' => $lastTouch,
            'persons' => $persons,
            'mainOf' => $mainOf,
            'ledger' => $this->ledger->forLeads($principals),
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
     * `paid` es toda la pauta; `paid_resolved`, la de anuncios de la cuenta
     * configurada, que es el universo de ROAS, ROAS de clientes nuevos y CAC.
     * `unresolved_paid_leads` cuenta las personas de pauta que no son de esa
     * cuenta, y `roas_warning` lo avisa cuando el gasto se conoce.
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
        $resolved = ['leads' => 0, 'converted' => 0, 'revenue' => 0.0, 'revenue_new' => 0.0];

        // Una persona, una vez: todo se cuenta sobre su lead principal.
        foreach ($ctx['persons'] as $leadId) {
            $touch = $ctx['firstTouch'][$leadId];
            $fromAds = $touch['origin'] === LeadSourceClassifier::ORIGIN_PAID;
            // Pauta de la cuenta configurada: la única cuyo gasto se conoce.
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
        $unresolved = $paid['leads'] - $resolved['leads'];

        $spendProblem = $this->spendProblem($spend);
        $roasReason = $spendProblem;
        $cacReason = $spendProblem ?? ($resolved['converted'] === 0 ? 'no_paid_conversions' : null);

        // Hay leads de pauta que el gasto de la cuenta no cubre: el ROAS de la
        // cuenta no los incluye, y hay que decirlo.
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
            'conversion_rate' => $leads > 0 ? round($converted / $leads, 4) : null,
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
        foreach (['revenue_new', 'revenue_renewal', 'revenue_attributed', 'roas', 'roas_new', 'cac'] as $field) {
            $kpis[$field] = null;
        }
        foreach (['revenue_reason', 'roas_reason', 'roas_new_reason', 'cac_reason'] as $field) {
            $kpis[$field] = self::MONEY_FORBIDDEN;
        }
        $kpis['paid']['revenue'] = null;
        $kpis['paid_resolved']['revenue'] = null;
        $kpis['paid_resolved']['revenue_new'] = null;
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
     * Las cifras secundarias, cada una con su alcance: `period` si es del
     * periodo, `now` si es una foto del momento.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, array{value: int, scope: string}>
     */
    private function secondary(array $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Personas de U con algún lead caliente del periodo.
        $hot = [];
        foreach ($ctx['leads'] as $lead) {
            $key = LeadUniverse::personKey($lead);
            if ($lead->temperature === MarketingLead::STATUS_HOT && isset($ctx['persons'][$key])) {
                $hot[$key] = true;
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
     * @param  array<string, mixed>  $ctx
     * @return array{0: list<array<string, mixed>>, 1: ?float}
     */
    private function origins(array $ctx, bool $moneyVisible): array
    {
        $rows = [];
        foreach (LeadSourceClassifier::KEYS as $key) {
            $rows[$key] = $this->originRow($key);
        }

        foreach ($ctx['persons'] as $leadId) {
            $key = $ctx['firstTouch'][$leadId]['key'];
            $rows[$key] ??= $this->originRow($key);
            $rows[$key]['leads']++;
        }

        // El libro solo trae los leads principales de U: una persona, una vez.
        foreach ($ctx['ledger'] as $leadId => $record) {
            if (! in_array($record['kind'], [ConversionLedger::KIND_NEW, ConversionLedger::KIND_RENEWAL], true)) {
                continue;
            }

            $key = $ctx['firstTouch'][$leadId]['key'];
            $rows[$key] ??= $this->originRow($key);
            $rows[$key]['converted'] += $record['kind'] === ConversionLedger::KIND_NEW ? 1 : 0;
            $rows[$key]['revenue'] += $record['revenue'];
        }

        $total = count($ctx['persons']);
        foreach ($rows as $key => $row) {
            $rows[$key]['share'] = $total > 0 ? round($row['leads'] / $total, 4) : null;
            $rows[$key]['revenue'] = $moneyVisible ? round($row['revenue'], 2) : null;
        }

        $unattributed = $total > 0
            ? round($rows[LeadSourceClassifier::KEY_UNATTRIBUTED]['leads'] / $total, 4)
            : null;

        return [array_values($rows), $unattributed];
    }

    /** @return array{key: string, label: string, leads: int, share: ?float, converted: int, revenue: float} */
    private function originRow(string $key): array
    {
        return [
            'key' => $key,
            'label' => LeadSourceClassifier::labelFor($key),
            'leads' => 0,
            'share' => null,
            'converted' => 0,
            'revenue' => 0.0,
        ];
    }

    /**
     * La entidad de este nivel a la que lleva un primer toque de pauta, o null
     * si el anuncio no se resolvió contra la cuenta configurada.
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
     * las personas cuyo PRIMER toque fue ese anuncio.
     *
     * Las entidades a las que llevan los leads salen aunque no gastaran en el
     * periodo, y su gasto lo decide el lector de gasto ({@see MetaSpendReader::byLevel()}):
     * 0,00 comprobado si es de la cuenta configurada y el periodo está cubierto;
     * null si no se sabe. Nunca un cero por defecto.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, int>  $conversations
     * @return list<array<string, mixed>>
     */
    private function campaignRows(
        string $level,
        array $ctx,
        array $conversations,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $moneyVisible,
    ): array {
        // Las entidades a las que llevan las personas y las conversaciones.
        $include = [];
        foreach ([...array_values($ctx['persons']), ...array_keys($conversations)] as $leadId) {
            $touch = $ctx['firstTouch'][$leadId] ?? $ctx['otherTouch'][$leadId] ?? null;
            if ($touch !== null && $touch['origin'] === LeadSourceClassifier::ORIGIN_PAID) {
                [$entity] = $this->entityOf($level, $touch);
                if ($entity !== null) {
                    $include[$entity] = true;
                }
            }
        }

        $rows = [];
        foreach ($this->spend->byLevel($level, $from, $to, array_keys($include)) as $s) {
            $rows[$s['id']] = $this->campaignRow($s['id'], $s['name'], $s['status'], $s['spend'], $s['currency']);
        }

        $unresolvedName = $this->client->isConfigured() ? self::UNRESOLVED_NAME_OTHER_ACCOUNT : self::UNRESOLVED_NAME;

        $lookups = [];
        $bucket = function (?array $touch) use (&$rows, &$lookups, $level, $unresolvedName): ?string {
            if ($touch === null || $touch['origin'] !== LeadSourceClassifier::ORIGIN_PAID) {
                return null;
            }

            [$entity, $name] = $this->entityOf($level, $touch);

            $id = $entity ?? self::UNRESOLVED;
            if (! isset($rows[$id])) {
                // Sin fila del lector (Meta Ads sin conexión): el gasto no se sabe.
                $rows[$id] = $entity === null
                    ? $this->campaignRow(null, $unresolvedName, null, null, null)
                    : $this->campaignRow($entity, $name, null, null, null);
                if ($entity !== null) {
                    $lookups[] = $entity;
                }
            }

            return $id;
        };

        foreach ($ctx['persons'] as $leadId) {
            if (($id = $bucket($ctx['firstTouch'][$leadId])) !== null) {
                $rows[$id]['leads']++;
            }
        }

        foreach ($ctx['ledger'] as $leadId => $record) {
            if (! in_array($record['kind'], [ConversionLedger::KIND_NEW, ConversionLedger::KIND_RENEWAL], true)) {
                continue;
            }
            if (($id = $bucket($ctx['firstTouch'][$leadId])) !== null) {
                $rows[$id]['converted'] += $record['kind'] === ConversionLedger::KIND_NEW ? 1 : 0;
                $rows[$id]['revenue'] += $record['revenue'];
            }
        }

        foreach ($conversations as $leadId => $n) {
            $touch = $ctx['firstTouch'][$leadId] ?? $ctx['otherTouch'][$leadId] ?? null;
            if (($id = $bucket($touch)) !== null) {
                $rows[$id]['conversations'] += $n;
            }
        }

        $this->completeFromDimension($level, $rows, $lookups);

        foreach ($rows as $id => $row) {
            $valid = $row['spend'] !== null && $row['spend'] > 0
                && strtoupper((string) $row['currency']) === self::REVENUE_CURRENCY;

            $rows[$id]['revenue'] = round($row['revenue'], 2);
            $rows[$id]['roas'] = $valid ? round($row['revenue'] / $row['spend'], 2) : null;
            $rows[$id]['cac'] = $valid && $row['converted'] > 0 ? round($row['spend'] / $row['converted'], 2) : null;
            $rows[$id]['conversion_rate'] = $row['leads'] > 0 ? round($row['converted'] / $row['leads'], 4) : null;
        }

        uasort($rows, function (array $a, array $b): int {
            // «Sin campaña» siempre al final; el resto, por gasto, leads y nombre.
            if (($a['entity'] === null) !== ($b['entity'] === null)) {
                return $a['entity'] === null ? 1 : -1;
            }

            return [$b['spend'] ?? -1, $b['leads'], (string) $a['name']] <=> [$a['spend'] ?? -1, $a['leads'], (string) $b['name']];
        });

        return array_values(array_map(fn (array $row): array => $this->publicCampaignRow($level, $row, $moneyVisible), $rows));
    }

    /** @return array<string, mixed> */
    private function campaignRow(?string $entity, ?string $name, ?string $status, ?float $spend, ?string $currency): array
    {
        return [
            'entity' => $entity,
            'name' => $name,
            'status' => $status,
            'spend' => $spend,
            'currency' => $currency,
            'leads' => 0,
            'conversations' => 0,
            'converted' => 0,
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
     * estable, y solo se enseña enmascarado. Sin visión completa, sin dinero.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicCampaignRow(string $level, array $row, bool $moneyVisible): array
    {
        $entity = $row['entity'];

        return [
            'id' => $entity === null ? self::UNRESOLVED : self::opaqueId($level, $entity),
            'ref' => LeadSourceClassifier::maskId($entity),
            'name' => LeadSourceClassifier::maskDigits($row['name']),
            'status' => $row['status'],
            'spend' => $row['spend'],
            'currency' => $row['currency'],
            'leads' => $row['leads'],
            'conversations' => $row['conversations'],
            'converted' => $row['converted'],
            'revenue' => $moneyVisible ? $row['revenue'] : null,
            'cac' => $moneyVisible ? $row['cac'] : null,
            'roas' => $moneyVisible ? $row['roas'] : null,
            'conversion_rate' => $row['conversion_rate'],
        ];
    }

    /**
     * Clave pública y estable de una campaña, un conjunto o un anuncio.
     *
     * Con la clave de la aplicación (HMAC): un SHA-256 sin secreto del id de
     * Meta se podría invertir por fuerza bruta a partir del `ref` («***1234») y
     * del prefijo típico de los ids de Meta.
     */
    public static function opaqueId(string $level, string $entity): string
    {
        return $level.':'.substr(hash_hmac('sha256', $level.'|'.$entity, (string) config('app.key')), 0, 16);
    }

    /**
     * Un lead de la lista. Lo que NO sale: teléfono, wa_id, referral crudo,
     * `ad_id` completo e identidad financiera.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function leadRow(MarketingLead $lead, array $ctx, bool $moneyVisible): array
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
            'first_touch' => $this->touchView($ctx['firstTouch'][$id]),
            'last_touch' => $this->touchView($ctx['lastTouch'][$id]),
            'temperature' => $lead->temperature,
            'status' => $lead->status,
            'conversion' => $conversion,
            'first_contact_at' => LeadUniverse::firstContactAt($lead)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $touch
     * @return array<string, mixed>
     */
    private function touchView(array $touch): array
    {
        return [
            'origin' => $touch['origin'],
            'platform' => $touch['platform'],
            'key' => $touch['key'],
            'label' => LeadSourceClassifier::labelFor($touch['key']),
            'campaign_name' => LeadSourceClassifier::maskDigits($touch['campaign_name']),
            'adset_name' => LeadSourceClassifier::maskDigits($touch['adset_name']),
            'ad_name' => LeadSourceClassifier::maskDigits($touch['ad_name']),
            'ad_ref' => LeadSourceClassifier::maskId($touch['ad_id']),
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

    private function syncOutcome(): string
    {
        if (! $this->client->isConfigured()) {
            return 'not_configured';
        }

        $status = $this->sync->status();
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
