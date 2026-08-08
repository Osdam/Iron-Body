<?php

namespace Tests\Feature\Cycle;

use App\Models\CommercialEvent;
use App\Models\CommercialOpportunity;
use App\Models\MarketingAiAction;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\SupervisionService;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Marketing\SalesConversationMemoryService;
use Illuminate\Support\Facades\DB;

/**
 * Memoria, atribución y auditoría · lo que queda escrito del ciclo.
 *
 * Las otras pruebas comprueban qué DECIDE el sistema. Esta comprueba qué RECUERDA
 * y qué puede DEMOSTRAR, que es lo que queda cuando el cliente reclama o cuando
 * hay que decidir cuánto invertir en pauta.
 *
 * Las dos mitades son distintas y las dos son fáciles de hacer mal. Una memoria
 * que guarda de menos hace que el agente repita preguntas ya contestadas. Una
 * que guarda de más —que anota como hecho lo que el modelo dedujo— es peor:
 * convierte una suposición en historial, y a partir de ahí nadie distingue lo
 * que el cliente dijo de lo que un modelo creyó entender.
 */
class CycleMemoryAuditTest extends CommercialCycleTestCase
{
    private Plan $mensual;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = $this->plan('Mensual', 90000, 30);
        $this->anual = $this->plan('Anual', 900000, 365);
    }

    // ══ 8 · Memoria: hechos sí, interpretaciones no ═════════════════════

    /**
     * La memoria guarda lo verificable y no asciende una inferencia a hecho.
     *
     * La frontera no es filosófica, es operativa: un hecho es algo que se puede
     * señalar en una fila —pagó esto, dijo que no a aquello, un asesor prometió
     * escribir el martes—. Una inferencia es una lectura del modelo sobre lo
     * anterior. Si las dos acaban en el mismo sitio con el mismo aspecto, dentro
     * de tres meses nadie sabrá si el cliente dijo que le interesaba el anual o
     * si un modelo lo dedujo de un «gracias».
     */
    public function test_la_memoria_conserva_hechos_y_no_asciende_inferencias(): void
    {
        $this->day(0, note: 'llega, compra y va dejando historia');
        $lead = $this->newLead('3170000001', 'Memoria');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);

        $conversation = $this->conversationOf($lead);

        // ── Hechos que tienen que sobrevivir ────────────────────────────
        $lead->forceFill(['objective' => 'salud'])->save();

        MarketingAiAction::create([
            'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
            'action_type' => 'register_objection', 'reason' => 'le parece caro el anual',
            'status' => 'executed', 'metadata' => ['objection' => 'price'],
        ]);

        $rechazo = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_LOST,
            'next_action' => V::ACTION_OFFER_UPGRADE, 'offer_plan_id' => $this->anual->id,
            'outcome' => 'declined', 'outcome_reason' => 'prefiere seguir mensual',
            'reason' => 'adherencia alta', 'closed_at' => now(),
            'max_attempts' => 1, 'created_by' => 'engine',
        ]);

        $this->day(5, note: 'un asesor promete escribir la próxima semana');
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'customer_asked');
        MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_HUMAN, 'sender_user_id' => $this->admin->id,
            'body' => 'Te escribimos la próxima semana con la propuesta.', 'status' => 'sent',
        ]);
        MarketingAiAction::create([
            'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
            'action_type' => 'human_promise', 'reason' => 'Te escribimos la próxima semana.',
            'status' => 'executed', 'metadata' => ['due_at' => now()->addDays(7)->toIso8601String()],
        ]);
        app(MarketingManualTakeoverService::class)->release($conversation, $this->admin->id);

        // ── Lo que TODO esto tiene que poder recuperarse ────────────────
        $hechos = $this->recallableFacts($lead, $member);

        foreach ([
            'plan comprado' => 'Mensual',
            'objetivo declarado' => 'salud',
            'objeción registrada' => 'price',
            'negativa al anual' => 'prefiere seguir mensual',
            'promesa humana' => 'próxima semana',
        ] as $qué => $aguja) {
            $this->assertStringContainsString($aguja, $hechos, sprintf(
                "La memoria perdió un hecho verificable (%s). El agente repetiría "
                ."algo que el cliente ya dijo.\n\nMemoria:\n%s",
                $qué, $hechos,
            ));
        }

        $this->assertStringContainsString('90000', preg_replace('/[.,]/', '', $hechos),
            'No queda rastro consultable de cuánto pagó.');

        // ── Y lo que NO puede ascender a hecho ──────────────────────────
        //
        // El modelo produce lecturas: «parece interesado», «está dudando por
        // precio», «se le ve motivado». Son útiles dentro de un turno y no son
        // historial. Se comprueba que no acaban guardadas como preferencia,
        // objeción ni hecho del cliente.
        $inferencias = [
            'parece muy interesado en el anual',
            'creo que está dudando por dinero',
            'se le ve poco motivado últimamente',
        ];

        app(SalesConversationMemoryService::class)->remember(
            $conversation->fresh(),
            [
                'intent' => 'unknown',
                'confidence' => 0.4,
                'extracted_fields' => [],
                'missing_fields' => [],
                // Esto es lo que el modelo OPINA, y llega por el mismo camino
                // que los datos buenos: por eso hay que comprobarlo.
                'reply' => $inferencias[0],
                'risk_flags' => ['speculative'],
                'notes' => $inferencias[1],
                'assessment' => $inferencias[2],
            ],
            'Gracias, lo pienso',
        );

        // Ninguna inferencia se convirtió en objeción, preferencia ni negativa.
        $objeciones = MarketingAiAction::where('lead_id', $lead->id)
            ->where('action_type', 'register_objection')->count();
        $this->assertSame(1, $objeciones, sprintf(
            'Las opiniones del modelo crearon %d objeción(es) de más. Una lectura '
            .'del modelo no es algo que dijera el cliente.',
            $objeciones - 1,
        ));

        $this->assertSame($rechazo->outcome_reason, $rechazo->fresh()->outcome_reason,
            'Una inferencia del modelo reescribió el motivo real de una negativa.');
        $this->assertSame('salud', $lead->fresh()->objective,
            'Una inferencia del modelo sobrescribió el objetivo que declaró el cliente.');

        $this->assertSame(0, MarketingAiAction::where('lead_id', $lead->id)
            ->whereIn('action_type', ['commercial_opt_out', 'product_excluded'])->count(),
            'Una opinión del modelo acabó registrada como preferencia del cliente.');

        // Si el modelo quiere dejar constancia, queda como lo que es: una
        // decisión suya con su confianza, distinguible de un hecho del cliente.
        $anotado = MarketingAiAction::where('lead_id', $lead->id)
            ->whereNotIn('action_type', ['register_objection', 'human_promise', 'human_takeover', 'reactivate'])
            ->get();

        foreach ($anotado as $a) {
            $this->assertNotSame('register_objection', $a->action_type);
        }

        $this->note('memoria', 'hechos conservados · 3 inferencias del modelo no ascendieron a hecho');
    }

    // ══ 9 · Atribución y contribución del agente ════════════════════════

    /**
     * First touch estable, last touch con evidencia, y el mérito del agente
     * clasificado por lo que de verdad pasó.
     *
     * La tentación aquí es contable: llamar «venta del agente» a cualquier venta
     * que ocurrió en una conversación donde alguna vez escribió el agente. Con
     * ese criterio el agente se lleva el mérito de todo lo que cierra el equipo,
     * y la cifra deja de servir para decidir nada.
     */
    public function test_atribucion_estable_y_contribucion_del_agente_clasificada(): void
    {
        $desde = $this->origin->copy()->subDay();

        // ── AUTONOMOUS: el agente actuó y nadie más tocó la conversación ──
        $this->day(0, note: 'A llega por un anuncio; solo habla con el agente');
        $a = $this->newLead('3170000002', 'Autónoma');
        $atribucionA = MarketingLeadAttribution::create([
            'marketing_lead_id' => $a->id, 'source_type' => 'ad', 'ad_id' => 'AD-PRIMERO',
            'first_touch_at' => now(), 'first_touch_source_type' => 'ad', 'first_touch_ad_id' => 'AD-PRIMERO',
            'last_touch_at' => now(), 'last_touch_source_type' => 'ad', 'last_touch_ad_id' => 'AD-PRIMERO',
            'headline' => 'Mensual desde 90.000',
        ]);
        $memberA = $this->makeMember($a);
        MarketingMessage::create([
            'conversation_id' => $this->conversationOf($a)->id, 'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'Te cuento los planes', 'status' => 'sent',
        ]);
        $this->day(1, note: 'A paga sin que intervenga nadie');
        $this->approvePayment($memberA, $this->mensual);

        // ── ASSISTED: actuaron el agente y una persona ───────────────────
        $this->day(2, note: 'B habla con el agente y luego con un asesor');
        $b = $this->newLead('3170000003', 'Asistida');
        $memberB = $this->makeMember($b);
        $convB = $this->conversationOf($b);
        MarketingMessage::create([
            'conversation_id' => $convB->id, 'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'Te cuento los planes', 'status' => 'sent',
        ]);
        MarketingMessage::create([
            'conversation_id' => $convB->id, 'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_HUMAN, 'sender_user_id' => $this->admin->id,
            'body' => 'Hola, soy Ana, te ayudo con el pago', 'status' => 'sent',
        ]);
        $this->day(3, note: 'B paga tras hablar con la asesora');
        $this->approvePayment($memberB, $this->mensual);

        // ── INFLUENCED: el agente actuó antes, pero al pagar mandaba una
        //    persona ───────────────────────────────────────────────────────
        $this->day(4, note: 'C habló con el agente y un asesor tomó el control');
        $c = $this->newLead('3170000004', 'Influida');
        $memberC = $this->makeMember($c);
        $convC = $this->conversationOf($c);
        MarketingMessage::create([
            'conversation_id' => $convC->id, 'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'Te cuento los planes', 'status' => 'sent',
        ]);
        app(MarketingManualTakeoverService::class)->takeover($convC, $this->admin->id, 'customer_asked');
        $this->day(5, note: 'C paga con la conversación en manos de la asesora');
        $this->approvePayment($memberC, $this->mensual);

        // ── La clasificación ─────────────────────────────────────────────
        $hasta = now()->copy()->addDay();
        $revenue = app(SupervisionService::class)->agentRevenue($desde, $hasta);
        $clas = $revenue['classification'];

        $this->assertSame(1, $clas['autonomous']['sales'], sprintf(
            "Ventas autónomas esperadas 1, contadas %d. No se puede llamar autónoma "
            ."a una venta en la que intervino una persona.\n%s",
            $clas['autonomous']['sales'], json_encode($clas),
        ));
        $this->assertSame(1, $clas['assisted']['sales'], sprintf(
            'Ventas asistidas esperadas 1, contadas %d.', $clas['assisted']['sales'],
        ));
        $this->assertSame(1, $clas['influenced']['sales'], sprintf(
            "Ventas influidas esperadas 1, contadas %d. Una venta cerrada por una "
            ."persona no es del agente por haber escrito antes.\n%s",
            $clas['influenced']['sales'], json_encode($clas),
        ));

        // Sin doble conteo: tres ventas, tres clasificaciones.
        $total = array_sum(array_column($clas, 'sales'));
        $this->assertSame(3, $total, sprintf('Tres ventas se contaron %d veces.', $total));

        $this->assertEqualsWithDelta(
            270000.0,
            array_sum(array_column($clas, 'revenue')), 0.01,
            'El ingreso clasificado no cuadra con las tres ventas.',
        );

        // ── First touch estable, last touch con evidencia ────────────────
        $this->day(30, note: 'A vuelve por otro anuncio: cambia el último toque');

        $atribucionA->forceFill([
            'last_touch_at' => now(),
            'last_touch_source_type' => 'ad',
            'last_touch_ad_id' => 'AD-SEGUNDO',
        ])->save();

        $atribucionA->refresh();

        $this->assertSame('AD-PRIMERO', $atribucionA->first_touch_ad_id,
            'El primer toque cambió: se perdió por dónde llegó de verdad.');
        $this->assertSame('AD-SEGUNDO', $atribucionA->last_touch_ad_id,
            'El último toque no se movió con evidencia nueva.');
        $this->assertTrue($atribucionA->last_touch_at->greaterThan($atribucionA->first_touch_at));

        // Una sola fila de atribución por persona: sin duplicar el mérito.
        $this->assertSame(1, MarketingLeadAttribution::where('marketing_lead_id', $a->id)->count(),
            'Hay dos filas de atribución para la misma persona: el mérito se cuenta dos veces.');

        $this->note('atribución', sprintf(
            'autónoma %d · asistida %d · influida %d · first touch intacto',
            $clas['autonomous']['sales'], $clas['assisted']['sales'], $clas['influenced']['sales'],
        ));
    }

    // ══ 10 · Auditoría reconstruible ════════════════════════════════════

    /**
     * Reconstruir el ciclo entero SIN mirar las variables de la prueba.
     *
     * Es la comprobación que decide si todo lo demás sirve para algo. Un sistema
     * puede tomar decisiones perfectas y ser indefendible: si dentro de seis
     * meses alguien pregunta «¿por qué le ofrecieron el anual a esta persona en
     * abril?», la respuesta tiene que salir de la base de datos, no de la
     * memoria de quien estaba ese día.
     *
     * Por eso esta prueba solo conoce un teléfono. Todo lo demás lo averigua
     * consultando, como lo haría una auditoría de verdad.
     */
    public function test_el_ciclo_completo_se_reconstruye_solo_desde_la_persistencia(): void
    {
        $telefono = '3170000005';

        // ── Se vive el ciclo ────────────────────────────────────────────
        $this->day(0, note: 'llega por un anuncio');
        $lead = $this->newLead($telefono, 'Auditada');
        MarketingLeadAttribution::create([
            'marketing_lead_id' => $lead->id, 'source_type' => 'ad', 'ad_id' => 'AD-AUD',
            'first_touch_at' => now(), 'first_touch_source_type' => 'ad', 'first_touch_ad_id' => 'AD-AUD',
            'headline' => 'Mensual desde 90.000', 'campaign_name' => 'Marzo Neiva',
        ]);
        MarketingMessage::create([
            'conversation_id' => $this->conversationOf($lead)->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'body' => 'Vi el anuncio, cuánto vale el mensual',
            'status' => 'received', 'correlation_id' => 'aud-corr-1',
        ]);

        $this->day(1, note: 'paga el mensual');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);
        $this->recordAndEvaluate('payment.approved', $lead, $member, ['plan_id' => $this->mensual->id]);

        $this->day(10, note: 'asiste tres veces');
        $this->attendRegularly($member, perWeek: 3, weeks: 1);

        $this->day(20, note: 'le ofrecen el anual y dice que no');
        CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_LOST,
            'next_action' => V::ACTION_OFFER_UPGRADE, 'offer_plan_id' => $this->anual->id,
            'outcome' => 'declined', 'outcome_reason' => 'prefiere seguir mensual',
            'reason' => 'adherencia sostenida', 'closed_at' => now(),
            'max_attempts' => 1, 'created_by' => 'engine', 'correlation_id' => 'aud-corr-2',
        ]);
        MarketingAiAction::create([
            'lead_id' => $lead->id, 'conversation_id' => $this->conversationOf($lead)->id,
            'action_type' => 'register_objection', 'reason' => 'no quiere compromiso largo',
            'status' => 'executed', 'metadata' => ['objection' => 'commitment'],
        ]);

        $this->day(25, note: 'un asesor toma el control y promete escribir');
        app(MarketingManualTakeoverService::class)->takeover($this->conversationOf($lead), $this->admin->id, 'customer_asked');
        MarketingAiAction::create([
            'lead_id' => $lead->id, 'conversation_id' => $this->conversationOf($lead)->id,
            'action_type' => 'human_promise', 'reason' => 'Te escribimos el lunes.',
            'status' => 'executed', 'metadata' => ['due_at' => now()->addDays(5)->toIso8601String()],
        ]);
        app(MarketingManualTakeoverService::class)->release($this->conversationOf($lead), $this->admin->id);

        $this->day(31, note: 'renueva');
        $this->approvePayment($member, $this->mensual);

        // ══ La auditoría: a partir de aquí, solo consultas ══════════════

        $reconstruido = $this->auditByPhone($telefono);

        // Todo lo que una auditoría tiene que poder responder.
        foreach ([
            'lead', 'attribution', 'commercial_events', 'opportunities', 'decisions',
            'messages', 'payments', 'membership', 'objections', 'human_takeover',
            'promises', 'revenue',
        ] as $seccion) {
            $this->assertArrayHasKey($seccion, $reconstruido);
            $this->assertNotEmpty($reconstruido[$seccion], sprintf(
                'La auditoría no puede recuperar «%s» desde la persistencia.', $seccion,
            ));
        }

        // Por dónde llegó, y que sigue siendo lo mismo.
        $this->assertSame('AD-AUD', $reconstruido['attribution']['first_touch_ad_id']);
        $this->assertSame('Marzo Neiva', $reconstruido['attribution']['campaign_name']);
        $this->assertStringContainsString('90.000', $reconstruido['attribution']['headline']);

        // Qué compró y por cuánto, dos veces.
        $this->assertCount(2, $reconstruido['payments']);
        $this->assertEqualsWithDelta(180000.0, array_sum(array_column($reconstruido['payments'], 'amount')), 0.01);

        // Qué se le ofreció, y qué contestó.
        $negativa = collect($reconstruido['opportunities'])->firstWhere('goal', V::GOAL_UPGRADE);
        $this->assertNotNull($negativa, 'No consta que se le ofreciera una mejora.');
        $this->assertSame('declined', $negativa['outcome']);
        $this->assertSame('prefiere seguir mensual', $negativa['outcome_reason']);
        $this->assertSame('adherencia sostenida', $negativa['reason'],
            'No consta POR QUÉ se le ofreció: una oferta sin motivo no se puede defender.');

        // Que una persona entró, y qué prometió.
        $this->assertNotEmpty($reconstruido['human_takeover']);
        $this->assertStringContainsString('lunes', json_encode($reconstruido['promises']));

        // La clasificación del dinero.
        $this->assertEqualsWithDelta(90000.0, $reconstruido['revenue']['acquisition'], 0.01);
        $this->assertEqualsWithDelta(90000.0, $reconstruido['revenue']['renewal'], 0.01);

        // ── Y la cronología cuadra con las fechas simuladas ─────────────
        $fechas = collect($reconstruido['timeline'])->pluck('at');

        $this->assertSame(
            $fechas->sort()->values()->all(),
            $fechas->values()->all(),
            'La cronología reconstruida no está en orden: no se puede seguir el caso.',
        );

        $primero = \Illuminate\Support\Carbon::parse($fechas->first());
        $ultimo = \Illuminate\Support\Carbon::parse($fechas->last());

        $this->assertSame($this->origin->toDateString(), $primero->toDateString(),
            'El primer hito reconstruido no coincide con el día 0 simulado.');
        $this->assertSame(31, (int) $this->origin->diffInDays($ultimo), sprintf(
            'El último hito cae en el día %d y la simulación terminó en el 31.',
            (int) $this->origin->diffInDays($ultimo),
        ));

        $this->note('auditoría', sprintf(
            '%d hitos reconstruidos desde la base, del %s al %s',
            count($reconstruido['timeline']), $primero->toDateString(), $ultimo->toDateString(),
        ));
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    /**
     * Lo que se puede recuperar de una persona sin preguntarle otra vez.
     *
     * Se compone leyendo la persistencia, igual que lo haría el constructor del
     * prompt: si algo no está aquí, el agente no lo sabe.
     */
    private function recallableFacts(MarketingLead $lead, Member $member): string
    {
        $partes = [];

        $partes[] = 'plan: '.($member->fresh()->user->plan ?? '—');
        $partes[] = 'objetivo: '.($lead->fresh()->objective ?? '—');

        foreach (PaymentTransaction::where('member_id', $member->id)->where('status', 'approved')->get() as $p) {
            $partes[] = sprintf('pago: %s el %s', $p->amount, $p->paid_at);
        }

        foreach (MarketingAiAction::where('lead_id', $lead->id)->get() as $a) {
            $partes[] = sprintf('%s: %s %s', $a->action_type, $a->reason, json_encode($a->metadata));
        }

        foreach (CommercialOpportunity::where('marketing_lead_id', $lead->id)->get() as $o) {
            $partes[] = sprintf('oportunidad %s (%s): %s', $o->goal, $o->status, $o->outcome_reason ?? '');
        }

        $partes[] = 'resumen: '.($this->conversationOf($lead)->fresh()->summary ?? '');

        return implode("\n", $partes);
    }

    /**
     * Reconstruye el ciclo de una persona a partir de UN teléfono.
     *
     * Deliberadamente no recibe ids ni modelos: empieza por lo único que
     * tendría una auditoría real —un número de teléfono en una reclamación— y
     * llega al resto consultando.
     *
     * @return array<string,mixed>
     */
    private function auditByPhone(string $phone): array
    {
        $lead = MarketingLead::where('phone', $phone)->orWhere('meta_user_id', '57'.$phone)->firstOrFail();
        $member = $lead->member_id ? Member::find($lead->member_id) : Member::where('phone', $phone)->first();
        $conversationIds = DB::table('marketing_conversations')->where('lead_id', $lead->id)->pluck('id');

        $atribucion = MarketingLeadAttribution::where('marketing_lead_id', $lead->id)->first();

        $pagos = PaymentTransaction::where('member_id', $member?->id)
            ->where('status', 'approved')->orderBy('paid_at')
            ->get(['id', 'reference', 'amount', 'plan_id', 'paid_at'])
            ->map(fn ($p) => ['reference' => $p->reference, 'amount' => (float) $p->amount,
                'plan_id' => $p->plan_id, 'at' => (string) $p->paid_at])->all();

        $oportunidades = CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->orderBy('id')->get()
            ->map(fn ($o) => ['goal' => $o->goal, 'status' => $o->status, 'reason' => $o->reason,
                'outcome' => $o->outcome, 'outcome_reason' => $o->outcome_reason,
                'correlation_id' => $o->correlation_id, 'at' => (string) $o->created_at])->all();

        $acciones = MarketingAiAction::where('lead_id', $lead->id)->orderBy('id')->get();

        $mensajes = MarketingMessage::whereIn('conversation_id', $conversationIds)
            ->orderBy('id')->get(['direction', 'sender_type', 'body', 'created_at']);

        $eventos = CommercialEvent::where('marketing_lead_id', $lead->id)->orderBy('id')->get(['event', 'created_at']);

        // Cronología: todo lo anterior en una sola línea de tiempo ordenada.
        $timeline = collect();
        $timeline->push(['at' => (string) $lead->created_at, 'what' => 'lead creado']);
        if ($atribucion) {
            $timeline->push(['at' => (string) $atribucion->first_touch_at, 'what' => 'primer toque: '.$atribucion->first_touch_ad_id]);
        }
        foreach ($mensajes as $m) {
            $timeline->push(['at' => (string) $m->created_at, 'what' => 'mensaje '.$m->direction.' ('.$m->sender_type.')']);
        }
        foreach ($eventos as $e) {
            $timeline->push(['at' => (string) $e->created_at, 'what' => 'hecho comercial: '.$e->event]);
        }
        foreach ($oportunidades as $o) {
            $timeline->push(['at' => $o['at'], 'what' => 'oportunidad '.$o['goal'].' ('.$o['status'].')']);
        }
        foreach ($acciones as $a) {
            $timeline->push(['at' => (string) $a->created_at, 'what' => 'acción '.$a->action_type]);
        }
        foreach ($pagos as $p) {
            $timeline->push(['at' => $p['at'], 'what' => 'pago '.$p['amount']]);
        }

        $ventas = Payment::where('member_id', $member?->id)->orderBy('id')->get(['reference', 'amount', 'paid_at']);

        return [
            'lead' => ['id' => $lead->id, 'phone' => $lead->phone, 'status' => $lead->status],
            'attribution' => [
                'first_touch_ad_id' => $atribucion?->first_touch_ad_id,
                'campaign_name' => $atribucion?->campaign_name,
                'headline' => $atribucion?->headline,
            ],
            'commercial_events' => $eventos->pluck('event')->all(),
            'opportunities' => $oportunidades,
            'decisions' => $oportunidades,
            'messages' => $mensajes->map(fn ($m) => $m->direction.':'.$m->sender_type)->all(),
            'payments' => $pagos,
            'membership' => [
                'plan' => $member?->user?->plan,
                'start' => (string) $member?->user?->membership_start_date,
                'end' => (string) $member?->user?->membership_end_date,
            ],
            'objections' => $acciones->where('action_type', 'register_objection')->pluck('reason')->all(),
            'human_takeover' => $acciones->whereIn('action_type', ['human_takeover', 'reactivate'])->pluck('action_type')->all(),
            'promises' => $acciones->where('action_type', 'human_promise')
                ->map(fn ($a) => ['text' => $a->reason, 'due' => data_get($a->metadata, 'due_at')])->values()->all(),
            'revenue' => $this->classifyRevenue($ventas),
            'timeline' => $timeline->sortBy('at')->values()->all(),
        ];
    }

    /** @return array{acquisition:float,renewal:float} */
    private function classifyRevenue($ventas): array
    {
        $out = ['acquisition' => 0.0, 'renewal' => 0.0];

        foreach ($ventas->values() as $i => $v) {
            $out[$i === 0 ? 'acquisition' : 'renewal'] += (float) $v->amount;
        }

        return $out;
    }
}
