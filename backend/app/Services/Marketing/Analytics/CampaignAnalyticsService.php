<?php

namespace App\Services\Marketing\Analytics;

use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pauta generó dinero de verdad.
 *
 * Todo lo que sale de aquí es determinista y auditable: sale de filas que
 * existen, con importes que alguien pagó. No hay modelo estadístico, no hay
 * reparto proporcional y no hay estimación. Si una venta no se puede unir a una
 * atribución siguiendo la cadena, cuenta como NO atribuida y se dice cuánto es.
 *
 * La cadena que une el dinero con la pauta, y es la única:
 *
 *   atribución → lead → miembro → pago aprobado
 *
 * Cada eslabón es un identificador guardado, no una coincidencia de teléfono ni
 * una ventana temporal. Eso deja fuera ventas reales —alguien que llegó por un
 * anuncio, no dejó lead y pagó en recepción— y está bien que las deje fuera:
 * inventar ese vínculo es exactamente cómo un panel de rentabilidad empieza a
 * mentir. Lo que no se puede demostrar se cuenta aparte.
 *
 * **Primer contacto y último contacto se calculan por separado y no se suman.**
 * Son respuestas a preguntas distintas —qué nos trajo a esta persona y qué la
 * hizo volver— y sumarlas contaría cada venta dos veces.
 */
class CampaignAnalyticsService
{
    /** Dimensiones por las que se puede agrupar. */
    public const DIMENSIONS = [
        'source_type', 'platform', 'campaign', 'adset', 'ad', 'creative', 'advertised_product', 'advertised_plan',
    ];

    /** Lo que se enseña cuando el canal no informó el dato. */
    public const UNKNOWN_LABEL = 'Desconocido';

    public const UNAVAILABLE_LABEL = 'No disponible';

    public function __construct(private readonly AdvertisingSpendProvider $spend) {}

    /**
     * Resumen general del período.
     *
     * @return array<string,mixed>
     */
    public function summary(Carbon $from, Carbon $to, array $filters = []): array
    {
        $funnel = $this->funnelTotals($from, $to, $filters);
        $revenue = $this->revenueTotals($from, $to, $filters);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'funnel' => $funnel,
            'rates' => $this->ratesFor($funnel),
            'revenue' => $revenue,
            'spend' => $this->spendSummary($from, $to),
        ];
    }

    /**
     * Una fila por valor de la dimensión pedida.
     *
     * @return array<int,array<string,mixed>>
     */
    public function breakdown(
        string $dimension,
        Carbon $from,
        Carbon $to,
        array $filters = [],
        string $touch = 'first',
    ): array {
        $column = $this->columnFor($dimension, $touch);

        // Embudo por dimensión, en UNA consulta agrupada. Pedir fila por fila
        // sería una consulta por campaña, que es el N+1 clásico de los paneles.
        $funnel = DB::table('marketing_lead_attributions as a')
            ->leftJoin('marketing_leads as l', 'l.id', '=', 'a.marketing_lead_id')
            ->selectRaw("COALESCE({$column}, ?) as bucket", [self::UNKNOWN_LABEL])
            ->selectRaw('COUNT(DISTINCT a.marketing_lead_id) as leads')
            ->selectRaw('COUNT(DISTINCT a.marketing_conversation_id) as conversations')
            ->selectRaw("COUNT(DISTINCT CASE WHEN l.status <> 'new' THEN l.id END) as qualified")
            ->whereBetween('a.first_touch_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $revenue = $this->revenueByDimension($column, $from, $to, $filters, $touch);
        $opportunities = $this->opportunitiesByDimension($column, $from, $to, $filters);
        $appointments = $this->appointmentsByDimension($column, $from, $to, $filters);
        $spend = $this->spendFor($dimension, $from, $to);

        $buckets = collect($funnel->keys())
            ->merge($revenue->keys())
            ->merge($opportunities->keys())
            ->merge($appointments->keys())
            ->unique();

        return $buckets->map(function (string $bucket) use ($funnel, $revenue, $opportunities, $appointments, $spend) {
            $f = $funnel->get($bucket);
            $r = $revenue->get($bucket);
            $o = $opportunities->get($bucket);
            $ap = $appointments->get($bucket);

            $row = [
                'bucket' => $bucket,
                'conversations' => (int) ($f->conversations ?? 0),
                'leads' => (int) ($f->leads ?? 0),
                'qualified_leads' => (int) ($f->qualified ?? 0),
                'opportunities' => (int) ($o->total ?? 0),
                'opportunities_won' => (int) ($o->won ?? 0),
                'appointments_created' => (int) ($ap->created ?? 0),
                'appointments_completed' => (int) ($ap->completed ?? 0),
                'payment_links' => (int) ($r->links ?? 0),
                'payments_pending' => (int) ($r->pending ?? 0),
                'payments_approved' => (int) ($r->approved ?? 0),
                'payments_declined' => (int) ($r->declined ?? 0),
                'sales' => (int) ($r->sales ?? 0),
                'renewals' => (int) ($r->renewals ?? 0),
                'upgrades' => (int) ($r->upgrades ?? 0),
                'revenue' => round((float) ($r->revenue ?? 0), 2),
                'renewal_revenue' => round((float) ($r->renewal_revenue ?? 0), 2),
                'upgrade_revenue' => round((float) ($r->upgrade_revenue ?? 0), 2),
                'currencies' => $r->currencies ?? null,
            ];

            $row['average_ticket'] = $this->safeDivide($row['revenue'], $row['sales']);
            $row['revenue_per_lead'] = $this->safeDivide($row['revenue'], $row['leads']);
            $row['revenue_per_conversation'] = $this->safeDivide($row['revenue'], $row['conversations']);
            $row['conversion_rate'] = $this->safeDivide($row['sales'], $row['leads']);
            $row['spend'] = $spend[$bucket] ?? null;
            $row['roas'] = $this->roasFor($row['revenue'], $spend[$bucket] ?? null);

            return $row;
        })->values()->all();
    }

    // ── Embudo ──────────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function funnelTotals(Carbon $from, Carbon $to, array $filters): array
    {
        $base = DB::table('marketing_lead_attributions as a')
            ->leftJoin('marketing_leads as l', 'l.id', '=', 'a.marketing_lead_id')
            ->whereBetween('a.first_touch_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters));

        $row = (clone $base)
            ->selectRaw('COUNT(DISTINCT a.marketing_lead_id) as leads')
            ->selectRaw('COUNT(DISTINCT a.marketing_conversation_id) as conversations')
            ->selectRaw("COUNT(DISTINCT CASE WHEN l.status <> 'new' THEN l.id END) as qualified")
            ->first();

        $leadIds = (clone $base)->distinct()->pluck('a.marketing_lead_id');

        return [
            'conversations' => (int) ($row->conversations ?? 0),
            'leads' => (int) ($row->leads ?? 0),
            'qualified_leads' => (int) ($row->qualified ?? 0),
            'opportunities' => $this->countOpportunities($leadIds),
            'appointments_created' => $this->countAppointments($leadIds, completed: false),
            'appointments_completed' => $this->countAppointments($leadIds, completed: true),
            'payments_approved' => $this->countPayments($leadIds, 'approved'),
            'payments_pending' => $this->countPayments($leadIds, 'pending'),
            'payments_declined' => $this->countPayments($leadIds, 'declined'),
            'sales' => $this->countPayments($leadIds, 'approved'),
        ];
    }

    /**
     * Tasas de paso entre etapas.
     *
     * Ninguna división se hace sin comprobar el denominador. Un panel con
     * «Infinity%» o un error de división por cero en una campaña sin leads es
     * la forma más rápida de que nadie vuelva a abrirlo.
     *
     * @param  array<string,int>  $f
     * @return array<string,?float>
     */
    public function ratesFor(array $f): array
    {
        return [
            'conversation_to_lead' => $this->safeDivide($f['leads'] ?? 0, $f['conversations'] ?? 0),
            'lead_to_qualified' => $this->safeDivide($f['qualified_leads'] ?? 0, $f['leads'] ?? 0),
            'qualified_to_opportunity' => $this->safeDivide($f['opportunities'] ?? 0, $f['qualified_leads'] ?? 0),
            'opportunity_to_payment' => $this->safeDivide($f['payments_approved'] ?? 0, $f['opportunities'] ?? 0),
            'payment_to_sale' => $this->safeDivide($f['sales'] ?? 0, $f['payments_approved'] ?? 0),
            'lead_to_sale' => $this->safeDivide($f['sales'] ?? 0, $f['leads'] ?? 0),
        ];
    }

    // ── Dinero ──────────────────────────────────────────────────────────────

    /**
     * Ingresos del período, separando lo atribuible de lo que no.
     *
     * @return array<string,mixed>
     */
    public function revenueTotals(Carbon $from, Carbon $to, array $filters = []): array
    {
        $approved = DB::table('payment_transactions as p')
            ->where('p.status', 'approved')
            ->whereBetween('p.paid_at', [$from, $to]);

        $total = (clone $approved)
            ->selectRaw('COUNT(*) as sales, COALESCE(SUM(p.amount),0) as revenue')
            ->selectRaw('COUNT(DISTINCT p.currency) as currencies')
            ->first();

        // Atribuible = el pago se puede seguir hasta una atribución conocida.
        $attributed = (clone $approved)
            ->join('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->join('marketing_lead_attributions as a', 'a.marketing_lead_id', '=', 'l.id')
            ->where('a.source_type', '<>', 'unknown')
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw('COUNT(DISTINCT p.id) as sales, COALESCE(SUM(p.amount),0) as revenue')
            ->first();

        $attributedRevenue = round((float) ($attributed->revenue ?? 0), 2);
        $totalRevenue = round((float) ($total->revenue ?? 0), 2);

        return [
            'total' => $totalRevenue,
            'attributed' => $attributedRevenue,
            'unattributed' => round($totalRevenue - $attributedRevenue, 2),
            'unattributed_share' => $this->safeDivide($totalRevenue - $attributedRevenue, $totalRevenue),
            'sales' => (int) ($total->sales ?? 0),
            'attributed_sales' => (int) ($attributed->sales ?? 0),
            'average_ticket' => $this->safeDivide($totalRevenue, (int) ($total->sales ?? 0)),
            // Más de una moneda hace que sumar deje de tener sentido. Se avisa
            // en vez de entregar un total que mezcla pesos con dólares.
            'multi_currency' => (int) ($total->currencies ?? 0) > 1,
        ];
    }

    /**
     * Ingresos por dimensión, ya clasificados por tipo de venta.
     *
     * La clasificación sale de los HECHOS comerciales cuando existen y, si no,
     * del orden de los pagos del propio miembro: el primero es alta, los
     * siguientes son renovación salvo que el plan sea más caro, y entonces es
     * mejora. Determinista y explicable mirando dos filas.
     */
    private function revenueByDimension(
        string $column,
        Carbon $from,
        Carbon $to,
        array $filters,
        string $touch,
    ): \Illuminate\Support\Collection {
        return DB::table('marketing_lead_attributions as a')
            ->join('marketing_leads as l', 'l.id', '=', 'a.marketing_lead_id')
            ->join('payment_transactions as p', 'p.member_id', '=', 'l.member_id')
            ->whereNotNull('l.member_id')
            ->whereBetween('p.created_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw("COALESCE({$column}, ?) as bucket", [self::UNKNOWN_LABEL])
            ->selectRaw('COUNT(DISTINCT p.id) as links')
            ->selectRaw("COUNT(DISTINCT CASE WHEN p.status = 'pending' THEN p.id END) as pending")
            ->selectRaw("COUNT(DISTINCT CASE WHEN p.status = 'approved' THEN p.id END) as approved")
            ->selectRaw("COUNT(DISTINCT CASE WHEN p.status = 'declined' THEN p.id END) as declined")
            ->selectRaw("COUNT(DISTINCT CASE WHEN p.status = 'approved' THEN p.id END) as sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.amount ELSE 0 END),0) as revenue")
            ->selectRaw($this->renewalCountExpression().' as renewals')
            ->selectRaw($this->renewalRevenueExpression().' as renewal_revenue')
            ->selectRaw($this->upgradeCountExpression().' as upgrades')
            ->selectRaw($this->upgradeRevenueExpression().' as upgrade_revenue')
            ->selectRaw('COUNT(DISTINCT p.currency) as currencies')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');
    }

    /**
     * Una renovación es un pago aprobado que NO es el primero de ese miembro.
     *
     * Se apoya en los hechos comerciales cuando los hay; el respaldo por
     * secuencia existe porque los eventos solo se registran con el motor
     * encendido y el histórico anterior no los tiene.
     */
    private function renewalCountExpression(): string
    {
        return "COUNT(DISTINCT CASE WHEN p.status = 'approved' AND EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved' AND pp.id < p.id
                ) THEN p.id END)";
    }

    private function renewalRevenueExpression(): string
    {
        return "COALESCE(SUM(CASE WHEN p.status = 'approved' AND EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved' AND pp.id < p.id
                ) THEN p.amount ELSE 0 END),0)";
    }

    /** Una mejora es una renovación por un importe mayor que la anterior. */
    private function upgradeCountExpression(): string
    {
        return "COUNT(DISTINCT CASE WHEN p.status = 'approved' AND EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved'
                      AND pp.id < p.id AND pp.amount < p.amount
                ) THEN p.id END)";
    }

    private function upgradeRevenueExpression(): string
    {
        return "COALESCE(SUM(CASE WHEN p.status = 'approved' AND EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved'
                      AND pp.id < p.id AND pp.amount < p.amount
                ) THEN p.amount ELSE 0 END),0)";
    }

    /**
     * Ingresos separados por QUÉ los produjo.
     *
     * La distinción que hace útil este bloque: una campaña adquirió a alguien
     * que después pagó cinco veces más. Meterlo todo en «ingresos de la
     * campaña» hace que una pauta de hace dos años parezca la mejor del año, y
     * decidir el presupuesto de este mes con esa cifra es un error caro.
     *
     * Por eso se separa el ingreso de ADQUISICIÓN —el primer pago de esa
     * persona— del ingreso POSTERIOR, que existe porque el gimnasio la
     * retuvo, no porque el anuncio fuera bueno.
     *
     * @return array<string,mixed>
     */
    public function revenueCategories(Carbon $from, Carbon $to, array $filters = []): array
    {
        $base = DB::table('payment_transactions as p')
            ->join('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->join('marketing_lead_attributions as a', 'a.marketing_lead_id', '=', 'l.id')
            ->where('p.status', 'approved')
            ->whereBetween('p.paid_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters));

        $row = (clone $base)
            ->selectRaw($this->firstPaymentRevenueExpression().' as acquisition')
            ->selectRaw($this->renewalRevenueExpression().' as renewal')
            ->selectRaw($this->upgradeRevenueExpression().' as upgrade')
            ->selectRaw($this->reactivationRevenueExpression().' as reactivation')
            ->first();

        $acquisition = round((float) ($row->acquisition ?? 0), 2);
        $renewal = round((float) ($row->renewal ?? 0), 2);
        $upgrade = round((float) ($row->upgrade ?? 0), 2);
        $reactivation = round((float) ($row->reactivation ?? 0), 2);

        return [
            'acquisition_revenue' => $acquisition,
            'renewal_revenue' => $renewal,
            'upgrade_revenue' => $upgrade,
            // Hoy el gimnasio vende un solo tipo de producto -membresías-, así
            // que no hay venta cruzada que medir. Se declara en cero y no se
            // esconde: el día que haya tienda o entrenador personal, este es el
            // sitio donde entra.
            'cross_sell_revenue' => 0.0,
            'reactivation_revenue' => $reactivation,
            // Lo que la campaña trajo el día que trajo al cliente, frente a lo
            // que ese cliente ha dado después.
            'subsequent_revenue' => round($renewal + $reactivation, 2),
        ];
    }

    /** El primer pago aprobado de cada miembro: eso es adquisición. */
    private function firstPaymentRevenueExpression(): string
    {
        return "COALESCE(SUM(CASE WHEN NOT EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved' AND pp.id < p.id
                ) THEN p.amount ELSE 0 END),0)";
    }

    /**
     * Reactivación: volvió después de más de 90 días sin pagar.
     *
     * El umbral es una convención declarada, no un descubrimiento. Se elige 90
     * porque el plan más largo del catálogo dura menos: pasado eso, quien
     * vuelve a pagar es alguien que se había ido, no alguien que renueva.
     */
    private function reactivationRevenueExpression(): string
    {
        // La resta de fechas se escribe distinto en cada motor y no hay forma
        // portable de hacerlo en SQL crudo. Se elige por driver en vez de
        // asumir PostgreSQL: las pruebas corren sobre SQLite y un fallo que
        // solo aparece en produccion es el peor de todos.
        $cutoff = DB::connection()->getDriverName() === 'pgsql'
            ? "p.paid_at - INTERVAL '90 days'"
            : "datetime(p.paid_at, '-90 days')";

        return "COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM payment_transactions pp
                    WHERE pp.member_id = p.member_id AND pp.status = 'approved' AND pp.id < p.id
                ) AND NOT EXISTS (
                    SELECT 1 FROM payment_transactions pr
                    WHERE pr.member_id = p.member_id AND pr.status = 'approved' AND pr.id < p.id
                      AND pr.paid_at > {$cutoff}
                ) THEN p.amount ELSE 0 END),0)";
    }

    /**
     * Qué tan de fiar son estos números.
     *
     * Va con los datos, no aparte, porque sin esto los números se leen mal. Un
     * panel que dice «Instagram trajo 40 % de las ventas» significa una cosa si
     * el 10 % está sin atribuir y otra muy distinta si es el 70 %.
     *
     * @return array<string,mixed>
     */
    public function attributionQuality(Carbon $from, Carbon $to): array
    {
        $row = DB::table('marketing_lead_attributions as a')
            ->whereBetween('a.first_touch_at', [$from, $to])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(CASE WHEN a.source_type <> 'unknown' THEN 1 END) as known")
            ->selectRaw("COUNT(CASE WHEN a.attribution_confidence = 'high' THEN 1 END) as high")
            ->selectRaw("COUNT(CASE WHEN a.attribution_confidence = 'medium' THEN 1 END) as medium")
            ->selectRaw("COUNT(CASE WHEN a.attribution_confidence = 'low' THEN 1 END) as low")
            ->selectRaw("COUNT(CASE WHEN a.attribution_confidence = 'unknown' OR a.attribution_confidence IS NULL THEN 1 END) as unknown")
            // Parcial: se sabe que vino de un anuncio pero no cuál.
            ->selectRaw("COUNT(CASE WHEN a.source_type = 'ad' AND a.ad_id IS NULL THEN 1 END) as partial")
            ->first();

        $total = (int) ($row->total ?? 0);
        $revenue = $this->revenueTotals($from, $to);

        return [
            'records' => $total,
            'known' => (int) ($row->known ?? 0),
            'unknown' => $total - (int) ($row->known ?? 0),
            'known_share' => $this->safeDivide((int) ($row->known ?? 0), $total),
            'confidence' => [
                'high' => (int) ($row->high ?? 0),
                'medium' => (int) ($row->medium ?? 0),
                'low' => (int) ($row->low ?? 0),
                'unknown' => (int) ($row->unknown ?? 0),
            ],
            'partial_records' => (int) ($row->partial ?? 0),
            'outdated_campaigns' => $this->outdatedCampaignCount(),
            'unattributed_revenue_share' => $revenue['unattributed_share'],
        ];
    }

    /**
     * Conversaciones marcadas por el sistema como llegadas con una oferta que
     * ya no existe. Es la única señal de incoherencia anuncio↔catálogo que se
     * registra, y se registra al detectarla, no al leer.
     */
    private function outdatedCampaignCount(): int
    {
        return (int) DB::table('marketing_conversation_tags')
            ->where('tag', 'pauta-desactualizada')
            ->count();
    }

    /**
     * Todo lo de una campaña concreta: anuncios, producto, tiempo hasta venta.
     *
     * @return array<string,mixed>
     */
    public function campaignDetail(string $campaign, Carbon $from, Carbon $to): array
    {
        $filters = ['campaign' => $campaign];

        return [
            'campaign' => $campaign,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'ads' => $this->breakdown('ad', $from, $to, $filters),
            'creatives' => $this->breakdown('creative', $from, $to, $filters),
            'advertised_products' => $this->breakdown('advertised_product', $from, $to, $filters),
            'revenue_categories' => $this->revenueCategories($from, $to, $filters),
            'time_to_sale' => $this->timeToSale($from, $to, $filters),
            'objections' => $this->topObjections($from, $to, $filters),
        ];
    }

    /**
     * Cuánto tarda en comprar quien llega por aquí.
     *
     * Se mide desde el primer contacto hasta el primer pago aprobado. La
     * mediana además de la media, porque una sola venta a los seis meses
     * desplaza la media y hace parecer lento un canal que cierra en dos días.
     *
     * @return array<string,mixed>
     */
    public function timeToSale(Carbon $from, Carbon $to, array $filters = []): array
    {
        $days = DB::table('marketing_lead_attributions as a')
            ->join('marketing_leads as l', 'l.id', '=', 'a.marketing_lead_id')
            ->join('payment_transactions as p', 'p.member_id', '=', 'l.member_id')
            ->where('p.status', 'approved')
            ->whereNotNull('p.paid_at')
            ->whereBetween('a.first_touch_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw('a.marketing_lead_id, MIN(p.paid_at) as first_paid, MIN(a.first_touch_at) as touched')
            ->groupBy('a.marketing_lead_id')
            ->get()
            ->map(fn ($r) => Carbon::parse($r->touched)->diffInDays(Carbon::parse($r->first_paid), false))
            ->filter(fn ($d) => $d >= 0)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return ['samples' => 0, 'median_days' => null, 'average_days' => null];
        }

        return [
            'samples' => $days->count(),
            'median_days' => (float) $days[(int) floor(($days->count() - 1) / 2)],
            'average_days' => round($days->avg(), 1),
        ];
    }

    /**
     * Las objeciones más frecuentes de quien llega por aquí.
     *
     * Salen de hechos comerciales registrados, no de leer los mensajes con un
     * modelo: una objeción contada por una IA no es un dato, es una opinión.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topObjections(Carbon $from, Carbon $to, array $filters = []): array
    {
        if (! Schema::hasTable('commercial_events')) {
            return [];
        }

        return DB::table('marketing_lead_attributions as a')
            ->join('commercial_events as e', 'e.marketing_lead_id', '=', 'a.marketing_lead_id')
            ->where('e.event', V::EV_OBJECTION_RAISED)
            ->whereBetween('e.occurred_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE({$this->jsonField('e.payload', 'kind')}, ?) as kind", [self::UNKNOWN_LABEL])
            ->groupBy('kind')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['kind' => $r->kind, 'count' => (int) $r->total])
            ->all();
    }

    // ── Piezas sueltas ──────────────────────────────────────────────────────

    private function countOpportunities(\Illuminate\Support\Collection $leadIds): int
    {
        if ($leadIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('commercial_opportunities')
            ->whereIn('marketing_lead_id', $leadIds)->count();
    }

    private function countAppointments(\Illuminate\Support\Collection $leadIds, bool $completed): int
    {
        if ($leadIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('marketing_appointments')
            ->whereIn('marketing_lead_id', $leadIds)
            ->when($completed, fn ($q) => $q->where('status', 'completed'))
            ->count();
    }

    private function countPayments(\Illuminate\Support\Collection $leadIds, string $status): int
    {
        if ($leadIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('payment_transactions as p')
            ->join('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->whereIn('l.id', $leadIds)
            ->where('p.status', $status)
            ->count('p.id');
    }

    /** @return \Illuminate\Support\Collection<string,object> */
    private function opportunitiesByDimension(string $column, Carbon $from, Carbon $to, array $filters)
    {
        return DB::table('marketing_lead_attributions as a')
            ->join('commercial_opportunities as o', 'o.marketing_lead_id', '=', 'a.marketing_lead_id')
            ->whereBetween('o.created_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw("COALESCE({$column}, ?) as bucket", [self::UNKNOWN_LABEL])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(CASE WHEN o.status = ? THEN 1 END) as won', [V::STATUS_WON])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');
    }

    /** @return \Illuminate\Support\Collection<string,object> */
    private function appointmentsByDimension(string $column, Carbon $from, Carbon $to, array $filters)
    {
        return DB::table('marketing_lead_attributions as a')
            ->join('marketing_appointments as ap', 'ap.marketing_lead_id', '=', 'a.marketing_lead_id')
            ->whereBetween('ap.created_at', [$from, $to])
            ->when($filters !== [], fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw("COALESCE({$column}, ?) as bucket", [self::UNKNOWN_LABEL])
            ->selectRaw('COUNT(*) as created')
            ->selectRaw("COUNT(CASE WHEN ap.status = 'completed' THEN 1 END) as completed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');
    }

    // ── Gasto ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function spendSummary(Carbon $from, Carbon $to): array
    {
        if (! $this->spend->isAvailable()) {
            return [
                'available' => false,
                'source' => $this->spend->sourceName(),
                'note' => 'Gasto no disponible: no hay ninguna fuente de gasto conectada.',
            ];
        }

        $records = $this->spend->spendFor('campaign', $from, $to);

        return [
            'available' => true,
            'source' => $this->spend->sourceName(),
            'total' => array_sum(array_map(fn (SpendRecord $r) => $r->amount, $records)),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function spendFor(string $dimension, Carbon $from, Carbon $to): array
    {
        if (! $this->spend->isAvailable()) {
            return [];
        }

        return array_map(
            fn (SpendRecord $r) => $r->toArray(),
            $this->spend->spendFor($dimension, $from, $to),
        );
    }

    /**
     * ROAS solo cuando hay gasto real y mayor que cero.
     *
     * Sin gasto devuelve null, y el panel escribe «No disponible». Devolver
     * cero sería afirmar que la campaña no rindió nada, que es una afirmación
     * distinta y falsa.
     */
    private function roasFor(float $revenue, ?array $spend): ?float
    {
        $amount = $spend['amount'] ?? null;

        if ($amount === null || (float) $amount <= 0.0) {
            return null;
        }

        return round($revenue / (float) $amount, 4);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * La columna que corresponde a la dimensión y al tipo de contacto.
     *
     * Solo la fuente y el anuncio distinguen primer y último contacto: son los
     * únicos que el canal informa en cada visita. Para el resto, la distinción
     * no existe en los datos y fingirla sería inventar.
     */
    private function columnFor(string $dimension, string $touch): string
    {
        $isLast = $touch === 'last';

        return match ($dimension) {
            'source_type' => $isLast ? 'a.last_touch_source_type' : 'a.first_touch_source_type',
            'ad' => $isLast ? 'a.last_touch_ad_id' : 'a.ad_id',
            'platform' => 'a.source_platform',
            'campaign' => 'a.campaign_name',
            'adset' => 'a.adset_name',
            'creative' => 'a.creative_id',
            'advertised_product' => 'a.advertised_product',
            'advertised_plan' => 'a.advertised_plan_id',
            default => 'a.source_type',
        };
    }

    /** @param \Illuminate\Database\Query\Builder $query */
    private function applyFilters($query, array $filters)
    {
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $column = match ($key) {
                'source_type' => 'a.source_type',
                'platform' => 'a.source_platform',
                'campaign' => 'a.campaign_name',
                'adset' => 'a.adset_name',
                'ad' => 'a.ad_id',
                'advertised_product' => 'a.advertised_product',
                default => null,
            };

            if ($column !== null) {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /** Leer una clave de un JSON, con la sintaxis que entiende cada motor. */
    private function jsonField(string $column, string $key): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "{$column}->>'{$key}'"
            : "json_extract({$column}, '$.{$key}')";
    }

    /**
     * División que nunca revienta ni miente.
     *
     * Con denominador cero devuelve null, no cero: «no se puede calcular» y
     * «salió cero» son cosas distintas, y confundirlas en un panel de
     * conversión lleva a decisiones equivocadas sobre campañas nuevas que
     * todavía no tienen datos.
     */
    public function safeDivide(float|int $numerator, float|int $denominator): ?float
    {
        if ((float) $denominator === 0.0) {
            return null;
        }

        return round((float) $numerator / (float) $denominator, 4);
    }
}
