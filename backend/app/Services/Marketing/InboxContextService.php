<?php

namespace App\Services\Marketing;

use App\Models\CommercialEvent;
use App\Models\CommercialOpportunity;
use App\Models\CommercialSegment;
use App\Models\CommercialToolInvocation;
use App\Models\ElectronicInvoice;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\MembershipService;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Support\Facades\Schema;

/**
 * Todo lo que hay que saber de la persona con la que se está hablando.
 *
 * El panel derecho del Inbox necesita cruzar datos de siete sitios distintos.
 * Hacerlo con siete peticiones desde el navegador daría una cascada visible: el
 * panel se iría rellenando a trozos mientras quien atiende ya está leyendo, y
 * cada sección aparecería en un orden distinto según la latencia. Se resuelve
 * en una sola respuesta.
 *
 * Es **estrictamente de lectura**. No decide, no ejecuta, no escribe. Todo lo
 * que devuelve sale de servicios que ya estaban probados: no hay aquí una
 * segunda versión de la verdad sobre si alguien tiene membresía activa.
 *
 * Cada bloque va envuelto en su propio try: que falte la tabla de facturación o
 * que falle una consulta de pagos no puede dejar sin panel a quien está
 * atendiendo. Un panel incompleto es molesto; un panel que no carga impide
 * trabajar.
 */
class InboxContextService
{
    /**
     * Tablas ya comprobadas en esta peticion.
     *
     * `Schema::hasTable()` no es gratis: cada llamada consulta el catalogo del
     * sistema de PostgreSQL. Con una comprobacion por bloque, abrir el panel
     * costaba OCHO consultas a `pg_class` que no devuelven ni un dato del
     * cliente. El esquema no cambia a mitad de peticion, asi que se pregunta
     * una vez por tabla.
     *
     * @var array<string,bool>
     */
    private array $tableExists = [];

    public function __construct(private readonly MembershipService $memberships) {}

    /** Igual que `Schema::hasTable()`, pero preguntando una sola vez. */
    private function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }

    /**
     * @param  bool  $includeDiagnostics  solo para quien tiene permiso: expone
     *                                    identificadores de correlación y
     *                                    estados internos que a recepción no le
     *                                    dicen nada y solo añaden ruido.
     */
    public function build(MarketingConversation $conversation, bool $includeDiagnostics = false): array
    {
        $lead = $conversation->lead_id ? MarketingLead::find($conversation->lead_id) : null;
        $member = $lead?->member_id ? Member::with('user')->find($lead->member_id) : null;

        $context = [
            'customer' => $this->customer($conversation, $lead, $member),
            'tags' => $this->tags($conversation),
            'attribution' => $this->attribution($lead),
            'commercial' => $this->commercial($lead, $member),
            'opportunity' => $this->opportunity($lead, $member),
            'payments' => $this->payments($member),
            'membership' => $this->membership($member),
            'agenda' => $this->agenda($lead),
            'invoicing' => $this->invoicing($member),
            'app' => $this->app($member),
            'activity' => $this->activity($conversation, $lead, $member),
        ];

        if ($includeDiagnostics) {
            $context['diagnostics'] = $this->diagnostics($conversation, $lead, $member);
        }

        return $context;
    }

    // ── 1. Cliente ──────────────────────────────────────────────────────────

    private function customer(MarketingConversation $conversation, ?MarketingLead $lead, ?Member $member): array
    {
        return $this->safe(fn () => [
            'lead_id' => $lead?->id,
            'member_id' => $member?->id,
            'name' => $member?->full_name ?? $lead?->name,
            'phone' => $lead?->phone ?? $member?->phone,
            'document_number' => $member?->document_number,
            'email' => $member?->email ?? data_get($lead?->metadata, 'email'),
            'is_member' => $member !== null,
            'member_status' => $member?->status,
            'do_not_contact' => (bool) ($lead?->do_not_contact ?? false),
            'assigned_to' => $conversation->assigned_to_admin_id,
            // Identidades que se parecen pero no están enlazadas. Se enseñan
            // para que una persona decida: fusionar mal dos fichas es mucho más
            // caro de reparar que dejarlas separadas.
            'ambiguous_matches' => $this->ambiguousMatches($lead, $member),
        ], []);
    }

    /** @return array<int,array<string,mixed>> */
    private function ambiguousMatches(?MarketingLead $lead, ?Member $member): array
    {
        if ($lead === null || $member !== null || blank($lead->phone)) {
            return [];
        }

        return Member::query()
            ->where('phone', $lead->phone)
            ->limit(3)
            ->get(['id', 'full_name', 'document_number'])
            ->map(fn (Member $m) => [
                'member_id' => $m->id,
                'name' => $m->full_name,
                'document_number' => $m->document_number,
                'matched_by' => 'phone',
            ])->all();
    }

    /**
     * Etiquetas con su origen y su evidencia.
     *
     * Van completas aqui -no las dos de la lista- porque el panel es donde se
     * mira cuando ya se eligio a quien atender.
     */
    private function tags(MarketingConversation $conversation): array
    {
        return $this->safe(
            fn () => app(MarketingConversationTagService::class)->detailed($conversation),
            [],
        );
    }

    /**
     * De donde vino esta persona.
     *
     * Se entrega la lectura normalizada y NO el payload crudo: el panel
     * necesita saber que anuncio la trajo, no los identificadores internos.
     */
    /**
     * Lo que ha pagado esta persona, sumado.
     *
     * Solo pagos APROBADOS y solo si el lead esta enlazado a un miembro. Sin
     * ese enlace no hay forma honesta de decir que este origen produjo dinero,
     * y se devuelve null -no cero-: son cosas distintas.
     */
    private function attributedRevenueFor(?MarketingLead $lead): ?float
    {
        if ($lead?->member_id === null) {
            return null;
        }

        return $this->safe(function () use ($lead) {
            $total = \Illuminate\Support\Facades\DB::table('payment_transactions')
                ->where('member_id', $lead->member_id)
                ->where('status', 'approved')
                ->sum('amount');

            return $total > 0 ? round((float) $total, 2) : null;
        }, null);
    }

    private function attribution(?MarketingLead $lead): ?array
    {
        return $this->safe(function () use ($lead) {
            if ($lead === null || ! $this->hasTable('marketing_lead_attributions')) {
                return null;
            }

            $a = \App\Models\MarketingLeadAttribution::query()
                ->where('marketing_lead_id', $lead->id)
                ->first();

            if ($a === null) {
                return null;
            }

            return [
                'source_type' => $a->source_type,
                'platform' => $a->source_platform,
                'ad_id' => $a->ad_id,
                'campaign_name' => $a->campaign_name,
                'headline' => $a->headline,
                'source_url' => $a->source_url,
                'confidence' => $a->attribution_confidence,
                'first_touch_at' => $a->first_touch_at?->toIso8601String(),
                'first_touch_source' => $a->first_touch_source_type,
                'last_touch_at' => $a->last_touch_at?->toIso8601String(),
                'last_touch_source' => $a->last_touch_source_type,
                'adset_name' => $a->adset_name,
                'ad_name' => $a->ad_name,
                'advertised_product' => $a->advertised_product,
                'evidence' => $a->evidence,
                // El texto del anuncio lo escribio alguien fuera del sistema.
                // Se marca para que la interfaz no lo presente como palabra
                // nuestra ni el agente lo tome por una instruccion.
                'untrusted_text' => $a->headline !== null,
                // Lo que esta persona ha pagado, atribuible a esta llegada.
                // Va aqui y no en un endpoint aparte porque quien atiende lo
                // mira junto al resto: cuanto vale ya este cliente.
                'attributed_revenue' => $this->attributedRevenueFor($lead),
            ];
        }, null);
    }

    // ── 2. Comercial ────────────────────────────────────────────────────────

    private function commercial(?MarketingLead $lead, ?Member $member): array
    {
        return $this->safe(function () use ($lead, $member) {
            $subject = CommercialSubject::build($lead, $member);

            $segments = $this->hasTable('commercial_segments')
                ? CommercialSegment::query()
                    ->when($lead !== null, fn ($q) => $q->where('marketing_lead_id', $lead->id))
                    ->when($lead === null && $member !== null, fn ($q) => $q->where('member_id', $member->id))
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('segment')->all()
                : [];

            return [
                'segments' => $segments,
                'objective' => $subject->objective,
                'temperature' => $subject->temperature,
                'lead_stage' => $lead?->lead_stage,
                'price_objections' => $subject->priceObjections,
                'weekly_attendance_rate' => round($subject->weeklyAttendanceRate(), 2),
                'contactable' => $subject->isContactable(),
                'needs_human' => $subject->needsHuman,
                'last_inbound_at' => $subject->lastInboundAt?->toIso8601String(),
                'days_since_last_message' => $subject->daysSinceLastMessage,
            ];
        }, []);
    }

    // ── 3. Oportunidad ──────────────────────────────────────────────────────

    private function opportunity(?MarketingLead $lead, ?Member $member): ?array
    {
        return $this->safe(function () use ($lead, $member) {
            if (! $this->hasTable('commercial_opportunities')) {
                return null;
            }

            $opportunity = CommercialOpportunity::query()
                ->whereIn('status', V::OPEN_STATUSES)
                ->where(function ($q) use ($lead, $member): void {
                    if ($lead !== null) {
                        $q->orWhere('marketing_lead_id', $lead->id);
                    }
                    if ($member !== null) {
                        $q->orWhere('member_id', $member->id);
                    }
                })
                ->orderByDesc('priority')
                ->first();

            if ($opportunity === null) {
                return null;
            }

            return [
                'id' => $opportunity->id,
                'goal' => $opportunity->goal,
                'status' => $opportunity->status,
                'next_action' => $opportunity->next_action,
                'next_offer' => $opportunity->next_offer,
                'reason' => $opportunity->reason,
                'confidence' => $opportunity->confidence !== null ? (float) $opportunity->confidence : null,
                'priority' => $opportunity->priority,
                // La evidencia y las exclusiones son lo que hace auditable la
                // decisión: sin ellas el panel muestra una orden, no un criterio.
                'evidence' => $opportunity->evidence,
                'exclusions' => $opportunity->exclusions,
                'offer_plan_id' => $opportunity->offer_plan_id,
                'alternative_plan_id' => $opportunity->alternative_plan_id,
                'floor_plan_id' => $opportunity->floor_plan_id,
                'estimated_value' => $opportunity->estimated_value !== null ? (float) $opportunity->estimated_value : null,
                'act_after' => $opportunity->act_after?->toIso8601String(),
                'attempts' => $opportunity->attempts,
                'max_attempts' => $opportunity->max_attempts,
                'actionable' => $opportunity->isActionable(),
            ];
        }, null);
    }

    // ── 4. Pagos ────────────────────────────────────────────────────────────

    private function payments(?Member $member): array
    {
        return $this->safe(function () use ($member) {
            if ($member === null) {
                return ['transactions' => [], 'has_pending_link' => false];
            }

            $transactions = PaymentTransaction::query()
                ->where('member_id', $member->id)
                ->latest('id')->limit(5)
                ->get(['id', 'reference', 'status', 'amount', 'currency', 'plan_id',
                    'checkout_url', 'paid_at', 'expires_at', 'failure_reason', 'created_at']);

            return [
                'transactions' => $transactions->map(fn (PaymentTransaction $t) => [
                    'id' => $t->id,
                    'reference' => $t->reference,
                    'status' => $t->status,
                    'confirmed' => (string) $t->status === SM::APPROVED,
                    // El importe sale de la transacción, que lo tomó del
                    // catálogo. Nunca de nada que se haya escrito en el chat.
                    'amount' => (float) $t->amount,
                    'currency' => $t->currency,
                    'has_link' => filled($t->checkout_url),
                    'paid_at' => $t->paid_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                    'failure_reason' => $t->failure_reason,
                    'created_at' => $t->created_at?->toIso8601String(),
                ])->all(),
                'has_pending_link' => $transactions->contains(
                    fn ($t) => in_array((string) $t->status, SM::IN_FLIGHT, true) && filled($t->checkout_url),
                ),
            ];
        }, ['transactions' => [], 'has_pending_link' => false]);
    }

    // ── 5. Membresía ────────────────────────────────────────────────────────

    private function membership(?Member $member): array
    {
        return $this->safe(function () use ($member) {
            $user = $member?->user;

            if ($user === null) {
                return ['is_member' => $member !== null, 'active' => false];
            }

            $subject = CommercialSubject::build(null, $member);

            return [
                'is_member' => true,
                'active' => $this->memberships->isActive($user),
                'status' => $this->memberships->status($user),
                'plan' => $user->plan,
                'starts_at' => $user->membership_start_date,
                'ends_at' => $user->membership_end_date,
                'days_remaining' => $this->memberships->daysRemaining($user),
                'attendances_last_30_days' => $subject->attendancesLast30Days,
                'last_attendance_at' => $subject->lastAttendanceAt?->toIso8601String(),
                'weekly_rate' => round($subject->weeklyAttendanceRate(), 2),
            ];
        }, ['is_member' => false, 'active' => false]);
    }

    // ── 6. Agenda ───────────────────────────────────────────────────────────

    private function agenda(?MarketingLead $lead): array
    {
        return $this->safe(function () use ($lead) {
            if ($lead === null) {
                return ['appointments' => []];
            }

            return [
                'appointments' => MarketingAppointment::query()
                    ->where('marketing_lead_id', $lead->id)
                    ->orderByDesc('scheduled_at')->limit(5)
                    ->get(['id', 'type', 'status', 'title', 'scheduled_at'])
                    ->map(fn (MarketingAppointment $a) => [
                        'id' => $a->id,
                        'type' => $a->type,
                        'status' => $a->status,
                        'title' => $a->title,
                        'scheduled_at' => $a->scheduled_at?->toIso8601String(),
                    ])->all(),
                // Se declara explícitamente lo que el frontend NO debe ofrecer
                // como botón. Sin esta lista, la interfaz inventa capacidades.
                'pending_authorization' => ['reschedule', 'cancel'],
            ];
        }, ['appointments' => []]);
    }

    // ── 7. Facturación ──────────────────────────────────────────────────────

    private function invoicing(?Member $member): array
    {
        return $this->safe(function () use ($member) {
            if ($member === null || ! $this->hasTable('electronic_invoices')) {
                return ['invoices' => [], 'requires_human_approval' => true];
            }

            $paymentIds = Payment::query()->where('member_id', $member->id)->pluck('id');

            $invoices = $paymentIds->isEmpty() ? collect() : ElectronicInvoice::query()
                ->where('source_type', Payment::class)
                ->whereIn('source_id', $paymentIds)
                ->latest('id')->limit(5)
                ->get(['id', 'status', 'full_number', 'issued_at', 'failure_reason',
                    'pdf_path', 'pdf_url', 'xml_path', 'xml_url']);

            return [
                'invoices' => $invoices->map(fn (ElectronicInvoice $i) => [
                    'id' => $i->id,
                    'status' => $i->status,
                    'full_number' => $i->full_number,
                    'issued_at' => $i->issued_at?->toIso8601String(),
                    'failure_reason' => $i->failure_reason,
                    'has_pdf' => filled($i->pdf_path) || filled($i->pdf_url),
                    'has_xml' => filled($i->xml_path) || filled($i->xml_url),
                ])->all(),
                // Emitir es acción fiscal sensible. La bandera existe para que
                // la interfaz muestre «requiere aprobación» en lugar de un botón.
                'requires_human_approval' => true,
            ];
        }, ['invoices' => [], 'requires_human_approval' => true]);
    }

    // ── 8. Aplicación ───────────────────────────────────────────────────────

    private function app(?Member $member): array
    {
        return $this->safe(function () use ($member) {
            $user = $member?->user;

            if ($member === null) {
                return ['has_account' => false, 'membership_synced' => false, 'issue' => null];
            }

            if ($user === null) {
                return [
                    'has_account' => false,
                    'membership_synced' => false,
                    'issue' => 'no_account_linked',
                ];
            }

            $active = $this->memberships->isActive($user);

            return [
                'has_account' => true,
                'user_id' => $user->id,
                'membership_synced' => $active,
                // El caso que se resuelve mal por defecto: tiene cuenta pero no
                // ve su membresía. Pedirle que se registre otra vez le crea un
                // segundo usuario y empeora justo lo que venía a reportar.
                'issue' => $active ? null : 'membership_not_reflected',
            ];
        }, ['has_account' => false, 'membership_synced' => false, 'issue' => null]);
    }

    // ── 9. Actividad ────────────────────────────────────────────────────────

    /**
     * Una sola línea de tiempo con todo lo que le ha pasado a esta persona.
     *
     * Mezcla hechos comerciales y herramientas ejecutadas porque la pregunta
     * real de quien supervisa no es «¿qué eventos hubo?» sino «¿qué hizo el
     * agente y por qué?», y esa respuesta está repartida entre las dos tablas.
     */
    private function activity(MarketingConversation $conversation, ?MarketingLead $lead, ?Member $member): array
    {
        return $this->safe(function () use ($conversation, $lead, $member) {
            $items = [];

            if ($this->hasTable('commercial_events')) {
                foreach ($this->scopedQuery(CommercialEvent::query(), $lead, $member)
                    ->latest('occurred_at')->limit(20)->get() as $event) {
                    $items[] = [
                        'kind' => 'event',
                        'at' => $event->occurred_at?->toIso8601String(),
                        'label' => $event->event,
                        'detail' => null,
                        'status' => null,
                    ];
                }
            }

            if ($this->hasTable('commercial_tool_invocations')) {
                foreach ($this->scopedQuery(CommercialToolInvocation::query(), $lead, $member)
                    ->latest('id')->limit(20)->get() as $invocation) {
                    $items[] = [
                        'kind' => 'tool',
                        'at' => $invocation->created_at?->toIso8601String(),
                        'label' => $invocation->tool,
                        'detail' => $invocation->reason ?? $invocation->error_message,
                        'status' => $invocation->status,
                    ];
                }
            }

            if ($conversation->human_takeover) {
                $items[] = [
                    'kind' => 'takeover',
                    'at' => $conversation->updated_at?->toIso8601String(),
                    'label' => 'human_takeover',
                    'detail' => $conversation->human_takeover_source,
                    'status' => null,
                ];
            }

            usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

            return ['items' => array_slice($items, 0, 30)];
        }, ['items' => []]);
    }

    // ── 10. Diagnóstico ─────────────────────────────────────────────────────

    private function diagnostics(MarketingConversation $conversation, ?MarketingLead $lead, ?Member $member): array
    {
        return $this->safe(function () use ($conversation, $lead, $member) {
            $lastInvocation = $this->hasTable('commercial_tool_invocations')
                ? $this->scopedQuery(CommercialToolInvocation::query(), $lead, $member)->latest('id')->first()
                : null;

            return [
                'conversation_id' => $conversation->id,
                'correlation_id' => $lastInvocation?->correlation_id,
                'last_tool' => $lastInvocation?->tool,
                'last_tool_status' => $lastInvocation?->status,
                'last_tool_error' => $lastInvocation?->error_message,
                'last_tool_retryable' => (bool) ($lastInvocation?->retryable ?? false),
                'attempts' => $lastInvocation?->attempts,
                'unevaluated_events' => $this->hasTable('commercial_events')
                    ? $this->scopedQuery(CommercialEvent::query(), $lead, $member)->whereNull('evaluated_at')->count()
                    : 0,
                'flags' => [
                    'commercial_enabled' => (bool) config('commercial.enabled'),
                    'events_enabled' => (bool) config('commercial.events_enabled'),
                    'autonomy_enabled' => (bool) config('commercial.autonomy_enabled'),
                    'meta_enabled' => (bool) config('meta.enabled', false),
                ],
            ];
        }, []);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** Cerca una consulta al sujeto: nunca puede devolver datos de otra persona. */
    private function scopedQuery($query, ?MarketingLead $lead, ?Member $member)
    {
        return $query->where(function ($q) use ($lead, $member): void {
            if ($lead !== null) {
                $q->orWhere('marketing_lead_id', $lead->id);
            }
            if ($member !== null) {
                $q->orWhere('member_id', $member->id);
            }
            if ($lead === null && $member === null) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Ejecuta un bloque y devuelve su valor por defecto si falla.
     *
     * Un panel incompleto es molesto; uno que no carga impide atender a un
     * cliente que está esperando. Se prefiere lo primero, siempre.
     */
    private function safe(callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
