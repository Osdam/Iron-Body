<?php

namespace Tests\Feature\Telephony\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `voice_calls` (Hito 8, DB8): los estados CERRADOS de D8-13 SOLO HACIA
 * DELANTE, en los dos motores, con SQL a pelo. El grafo esperado está escrito
 * aquí desde el contrato, la Enmienda 1 y las decisiones del fixture de TEST8-a
 * (`tests/fixtures/voice/state-transitions.json`: DF-S2, DF-S3, DF-S4, DF-S8),
 * no tomado de la migración:
 *
 *  · Nace `requested` la saliente que pide Laravel; `active` o `denied` (E1-2)
 *    la entrante; el TRAMO DE TRANSFERENCIA (E1-2), en el estado en que lo ve
 *    el proveedor. Ninguna nace con herramientas usadas ni con el sello
 *    observado.
 *  · La matriz COMPLETA desde cada estado alcanzable a cada uno de los 12: lo
 *    que no está en el grafo es P0001 en PG y 23000 en SQLite; lo que sí, pasa.
 *    E1-1: una saliente contestada pasa de `dialing` a `active`. Un final no
 *    cambia (`denied` incluido).
 *  · Lo que se fija al empezar (dirección, destino, quién, la madre de un
 *    tramo, el SELLO de D8-8 y la reserva) no cambia; la conversación, la
 *    llamada de Twilio, la observación y el motivo, una vez; el cupo de
 *    herramientas (D8-10) solo crece, hasta 20, y solo en `dialing` o `active`.
 *  · UNIQUE de D8-12 y una sola saliente viva por número (los tramos no
 *    cuentan).
 *  · Solo en PG, cada CHECK de forma, atribuido a su nombre evaluando su
 *    expresión real (`violatedChecks()`), y que los rechaza de verdad (23514).
 */
class VoiceCallTransitionsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    private const STATUSES = ['requested', 'canceled', 'failed_to_start', 'ambiguous', 'dialing', 'active', 'denied', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    /** Las salientes: D8-13, DF-S2 (el webhook antes que el job), DF-S3 (la ambigua se resuelve hacia delante) y E1-1. */
    private const OUTBOUND_GRAPH = [
        'requested' => ['canceled', 'failed_to_start', 'ambiguous', 'dialing', 'completed', 'busy', 'no_answer', 'failed'],
        'ambiguous' => ['dialing', 'completed', 'busy', 'no_answer', 'failed'],
        'dialing' => ['active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'],
        'active' => ['completed', 'failed', 'hung_up'],
        'canceled' => [], 'failed_to_start' => [], 'completed' => [], 'busy' => [], 'no_answer' => [], 'failed' => [], 'hung_up' => [],
    ];

    /** Las entrantes: aceptada (y su final, DF-S4) o denegada (E1-2), que ya es final. */
    private const INBOUND_GRAPH = ['active' => ['completed', 'failed', 'hung_up'], 'denied' => [], 'completed' => [], 'failed' => [], 'hung_up' => []];

    /** Los tramos de transferencia (E1-2): desde lo que ve el proveedor, el mismo grafo hacia delante. */
    private const LEG_GRAPH = ['dialing' => ['active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'], 'active' => ['completed', 'failed', 'hung_up'], 'completed' => [], 'hung_up' => []];

    /** Cómo se llega a cada estado desde el inicial (pasos permitidos). */
    private const PATHS = [
        'outbound' => [
            'requested' => [], 'ambiguous' => ['ambiguous'], 'dialing' => ['dialing'], 'active' => ['dialing', 'active'], 'canceled' => ['canceled'],
            'failed_to_start' => ['failed_to_start'], 'completed' => ['dialing', 'completed'], 'busy' => ['dialing', 'busy'], 'no_answer' => ['dialing', 'no_answer'],
            'failed' => ['dialing', 'failed'], 'hung_up' => ['dialing', 'hung_up'],
        ],
        'inbound' => ['active' => [], 'denied' => [], 'completed' => ['completed'], 'failed' => ['failed'], 'hung_up' => ['hung_up']],
        'leg' => ['dialing' => [], 'active' => ['active'], 'completed' => ['active', 'completed'], 'hung_up' => ['active', 'hung_up']],
    ];

    private const FORWARD = 'una llamada solo avanza por los estados de D8-13 y un estado final no cambia (UPDATE en voice_calls)';

    private const STARTS = 'una llamada nace requested (saliente), active o denied (entrante) o, si es un tramo de transferencia, en el estado en que lo ve el proveedor; sin herramientas usadas ni observación del sello (INSERT en voice_calls)';

    private const FROZEN = 'una llamada fija al empezar su dirección, su destino, quién la pidió, su sello y su reserva (UPDATE en voice_calls)';

    private const ONCE = 'una llamada fija una sola vez su conversación, su llamada de Twilio, la observación del sello y el motivo de la política, y su cupo de herramientas solo crece, hasta 20, y solo con la llamada en curso (dialing o active) (UPDATE en voice_calls)';

    /** D8-8, D8-12 y E1-2: lo que una llamada fija al empezar. */
    private const FROZEN_COLUMNS = [
        'uuid', 'call_ref', 'direction', 'target_source', 'canary_number_uuid', 'voice_contact_id', 'remote_hash', 'remote_last4', 'caller_authorized',
        'client_request_id', 'requested_by_type', 'requested_by_admin_id', 'requested_by_name', 'requested_by_role', 'attempt', 'parent_call_id', 'transfer_of_call_id',
        'voice_publication_seq', 'part', 'stamp_state', 'studio_version_uuid', 'studio_content_hash', 'voice_profile_hash', 'spec_hash',
        'payload_hash', 'compiler_version', 'provider', 'provider_agent_id', 'budget_day', 'reserved_micros', 'created_at', 'id',
    ];

    /** Protección 3 del H8: ninguna prueba de voz sale a la red, tampoco las de esquema (`VoiceIsolationTest`). */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── Nacer ───────────────────────────────────────────────────────────────

    public function test_una_llamada_nace_en_su_estado_inicial_y_nada_mas(): void
    {
        $this->assertAccepted(fn () => $this->insertOutbound(), 'saliente requested');
        $this->assertAccepted(fn () => $this->insertInbound(), 'entrante active');
        $this->assertAccepted(fn () => $this->insertDenied(), 'entrante denied (E1-2)');
        $madre = $this->insertInbound();
        foreach (['dialing', 'active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'] as $estado) {
            $o = in_array($estado, ['dialing', 'active'], true) ? ['status' => $estado, 'ended_at' => null, 'duration_seconds' => null, 'carrier_cost_micros' => null] : ['status' => $estado];
            $this->assertAccepted(fn () => $this->insertLeg($madre, $o), "tramo que nace {$estado}");
        }

        foreach (self::STATUSES as $estado) {
            if ($estado !== 'requested') {
                $this->assertGuarded(fn () => $this->insertOutbound(['status' => $estado]), self::STARTS, "saliente que nace {$estado}");
            }
            if (! in_array($estado, ['active', 'denied'], true)) {
                $this->assertGuarded(fn () => $this->insertInbound(['status' => $estado]), self::STARTS, "entrante que nace {$estado}");
            }
            if (in_array($estado, ['requested', 'canceled', 'failed_to_start', 'ambiguous', 'denied'], true)) {
                $this->assertGuarded(fn () => $this->insertLeg($madre, ['status' => $estado, 'ended_at' => null]), self::STARTS, "tramo que nace {$estado}");
            }
        }
        foreach ([
            'con herramientas usadas' => ['tool_calls' => 1],
            'con el sello ya verificado' => ['seal_verified' => true],
            'con el agente ya observado' => ['observed_agent_id' => 'agent_canary_1'],
            'ya terminada' => ['ended_at' => now()],
        ] as $que => $o) {
            $this->assertGuarded(fn () => $this->insertOutbound($o), self::STARTS, "saliente {$que}");
            $this->assertGuarded(fn () => $this->insertInbound($o), self::STARTS, "entrante {$que}");
            $this->assertGuarded(fn () => $this->insertDenied($o), self::STARTS, "denegada {$que}");
        }
        $this->assertSame(11, DB::table('voice_calls')->count(), 'las 3 de arriba, la madre y los 7 tramos');
    }

    // ── La matriz de transiciones ───────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function fromStates(): array
    {
        $out = [];
        foreach (['outbound' => self::OUTBOUND_GRAPH, 'inbound' => self::INBOUND_GRAPH, 'leg' => self::LEG_GRAPH] as $tipo => $grafo) {
            foreach (array_keys($grafo) as $estado) {
                $out["{$tipo} en {$estado}"] = [$tipo, $estado];
            }
        }

        return $out;
    }

    #[DataProvider('fromStates')]
    public function test_desde_cada_estado_solo_se_va_a_lo_que_el_grafo_permite(string $tipo, string $desde): void
    {
        $grafo = ['outbound' => self::OUTBOUND_GRAPH, 'inbound' => self::INBOUND_GRAPH, 'leg' => self::LEG_GRAPH][$tipo];
        $permitidos = 0;

        foreach (self::STATUSES as $hacia) {
            if ($hacia === $desde) {
                continue;
            }
            $id = $this->reach($tipo, $desde);
            $cambio = fn () => DB::table('voice_calls')->where('id', $id)->update($this->patchFor($id, $hacia));

            if (in_array($hacia, $grafo[$desde], true)) {
                $this->assertAccepted($cambio, "{$tipo}: {$desde} → {$hacia}");
                $this->assertSame($hacia, DB::table('voice_calls')->where('id', $id)->value('status'));
                $permitidos++;
            } else {
                $this->assertGuarded($cambio, self::FORWARD, "{$tipo}: {$desde} → {$hacia}");
                $this->assertSame($desde, DB::table('voice_calls')->where('id', $id)->value('status'));
            }
        }
        $this->assertSame(count($grafo[$desde]), $permitidos);
    }

    public function test_escribir_el_mismo_estado_pasa_y_un_final_admite_lo_que_llega_despues(): void
    {
        foreach (['requested', 'dialing', 'active', 'completed', 'canceled'] as $estado) {
            $id = $this->reach('outbound', $estado);
            $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['status' => $estado, 'updated_at' => now()]), $estado);
        }
        $id = $this->reach('outbound', 'completed');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['carrier_cost_micros' => 37700, 'provider_cost_micros' => 80000]), 'coste tras completed');
        // La denegada: su post-llamada (duración, coste, observación, fin) sí se escribe; su estado, no.
        $denegada = $this->insertDenied();
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $denegada)->update(['duration_seconds' => 9, 'carrier_cost_micros' => 14180, 'ended_at' => now(),
            'observed_agent_id' => 'agent_canary_1', 'seal_verified' => true, 'termination_reason' => 'end_call_tool']), 'la post-llamada de una denegada');
    }

    // ── Lo que se fija al empezar y lo que se fija una vez ──────────────────

    public function test_lo_que_la_llamada_fija_al_empezar_no_cambia_ni_con_el_mismo_valor(): void
    {
        foreach (['saliente' => $this->insertOutbound(), 'tramo' => $this->insertLeg($this->insertInbound())] as $que => $id) {
            foreach (self::FROZEN_COLUMNS as $columna) {
                $this->assertGuarded(fn () => DB::statement("UPDATE voice_calls SET {$columna} = {$columna} WHERE id = ?", [$id]), self::FROZEN, "{$que}: {$columna}");
            }
        }
    }

    public function test_lo_que_llega_despues_se_escribe(): void
    {
        $id = $this->insertOutbound();
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update([
            'status' => 'dialing', 'provider_conversation_id' => 'conv_saliente_1', 'provider_call_sid' => $this->callSid('saliente-1'),
            'started_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]), 'a dialing');
        // E1-1: la primera herramienta la contesta.
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['status' => 'active', 'tool_calls' => 1]), 'la primera herramienta: dialing → active');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => 3]), 'más herramientas');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update([
            'status' => 'completed', 'ended_at' => now(), 'duration_seconds' => 95, 'termination_reason' => 'end_call_tool',
            'observed_agent_id' => 'agent_canary_1', 'observed_version_id' => 'agtvrsn_1', 'observed_branch_id' => 'agtbrch_1', 'seal_verified' => true,
            'provider_cost_micros' => 126667,
        ]), 'a completed con la observación');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['carrier_cost_micros' => 59700]), 'la conciliación');

        $fallida = $this->reach('outbound', 'dialing');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $fallida)->update(['status' => 'failed', 'failure_code' => 'unknown', 'ended_at' => now()]), 'failed');
        // DF-S2: la post-llamada llega antes de que el job guarde dialing.
        $temprana = $this->insertOutbound();
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $temprana)->update(['status' => 'busy', 'provider_conversation_id' => 'conv_temprana', 'ended_at' => now()]), 'requested → busy');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $temprana)->update(['status' => 'dialing']), self::FORWARD, 'el job, tarde: dialing');
    }

    public function test_la_conversacion_la_llamada_de_twilio_la_observacion_y_el_motivo_se_fijan_una_vez(): void
    {
        $id = $this->reach('outbound', 'dialing');
        $fila = DB::table('voice_calls')->where('id', $id)->first();
        foreach (['provider_conversation_id' => 'conv_otra', 'provider_call_sid' => $this->callSid('otra')] as $columna => $otro) {
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update([$columna => $otro]), self::ONCE, "{$columna} cambiado");
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update([$columna => null]), self::ONCE, "{$columna} borrado");
            $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update([$columna => $fila->{$columna}]), "{$columna} con el mismo valor");
        }

        DB::table('voice_calls')->where('id', $id)->update(['status' => 'completed', 'ended_at' => now(), 'observed_agent_id' => 'agent_canary_1', 'seal_verified' => true]);
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['observed_agent_id' => 'agent_otro', 'seal_verified' => false]), self::ONCE, 'la observación');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['seal_verified' => false]), self::ONCE, 'el veredicto del sello');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['observed_version_id' => 'agtvrsn_1']), 'la versión observada llega después: de NULL a un valor');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['observed_version_id' => 'agtvrsn_2']), self::ONCE, 'la versión observada, cambiada');

        $cancelada = $this->reach('outbound', 'canceled');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $cancelada)->update(['policy_reason' => 'voice_braked']), self::ONCE, 'el motivo de una cancelada');
        $denegada = $this->insertDenied();
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $denegada)->update(['policy_reason' => 'voice_braked']), self::ONCE, 'el motivo de una denegada');
    }

    public function test_el_cupo_de_herramientas_solo_crece_hasta_20_y_solo_con_la_llamada_en_curso(): void
    {
        $id = $this->insertInbound();
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => 5]));
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => 4]), self::ONCE, 'baja');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => 0]), self::ONCE, 'se reinicia');
        // La forma atómica de B8: sumar uno con la condición del cupo y del estado en el WHERE.
        for ($i = 6; $i <= 20; $i++) {
            $this->assertSame(1, DB::table('voice_calls')->where('id', $id)->whereIn('status', ['dialing', 'active'])->where('tool_calls', '<', 20)->increment('tool_calls'));
        }
        $this->assertSame(0, DB::table('voice_calls')->where('id', $id)->whereIn('status', ['dialing', 'active'])->where('tool_calls', '<', 20)->increment('tool_calls'), 'la 21 no encuentra la fila');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->increment('tool_calls'), self::ONCE, 'la 21 a pelo');
        $this->assertSame(20, (int) DB::table('voice_calls')->where('id', $id)->value('tool_calls'));

        // E1-1: en una saliente vale en dialing; fuera de curso, no.
        $saliente = $this->reach('outbound', 'dialing');
        $this->assertAccepted(fn () => DB::table('voice_calls')->where('id', $saliente)->increment('tool_calls'), 'en dialing');
        foreach (['requested' => $this->insertOutbound(), 'ambiguous' => $this->reach('outbound', 'ambiguous'), 'completed' => $this->reach('outbound', 'completed'),
            'denied' => $this->insertDenied(), 'hung_up' => $this->reach('inbound', 'hung_up')] as $estado => $llamada) {
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $llamada)->increment('tool_calls'), self::ONCE, "en {$estado}");
        }
    }

    // ── E1-2: la denegada y el tramo ────────────────────────────────────────

    public function test_un_tramo_de_transferencia_apunta_a_su_llamada_madre_que_no_se_borra(): void
    {
        $madre = $this->insertInbound();
        $tramo = $this->insertLeg($madre);
        $this->assertSame([$tramo], DB::table('voice_calls')->where('transfer_of_call_id', $madre)->pluck('id')->map(static fn ($v): int => (int) $v)->all());
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $madre)->delete(), 'las llamadas no se borran (DELETE en voice_calls)', 'la madre');

        $this->isPostgres()
            ? $this->assertRejected(fn () => $this->insertLeg(999999), '23503', 'voice_calls_transfer_of_call_id_foreign', 'una madre que no existe')
            : $this->assertRejected(fn () => $this->insertLeg(999999), '23000', 'FOREIGN KEY constraint failed', 'una madre que no existe');
        // Dos tramos a la vez al mismo destino (dos transferencias a recepción) son legítimos: no cuentan como «saliente viva».
        $destino = $this->hex('recepcion');
        foreach ([1, 2] as $vez) {
            $this->assertAccepted(fn () => $this->insertLeg($this->insertInbound(), ['status' => 'dialing', 'remote_hash' => $destino, 'ended_at' => null, 'duration_seconds' => null, 'carrier_cost_micros' => null]), "tramo vivo {$vez}");
        }
    }

    // ── UNIQUE e índices parciales ──────────────────────────────────────────

    public function test_una_sola_saliente_viva_por_numero(): void
    {
        $hash = $this->hex('mismo-numero');
        $primera = $this->insertOutbound(['remote_hash' => $hash]);
        foreach (['requested' => [], 'ambiguous' => ['ambiguous'], 'dialing' => ['dialing'], 'active' => ['dialing', 'active']] as $estado => $pasos) {
            DB::beginTransaction();
            try {
                foreach ($pasos as $paso) {
                    DB::table('voice_calls')->where('id', $primera)->update($this->patchFor($primera, $paso));
                }
                $this->assertUniqueRejects(fn () => $this->insertOutbound(['remote_hash' => $hash]), 'vc_one_live_outbound', 'voice_calls', ['remote_hash'], "con la primera en {$estado}");
                // Ni una entrante ni un tramo del mismo número cuentan.
                $this->assertAccepted(fn () => $this->insertInbound(['remote_hash' => $hash]), "entrante con la saliente en {$estado}");
                $this->assertAccepted(fn () => $this->insertLeg($primera, ['remote_hash' => $hash]), "tramo con la saliente en {$estado}");
            } finally {
                DB::rollBack();
            }
        }
        foreach (['canceled' => ['canceled'], 'failed_to_start' => ['failed_to_start'], 'completed' => ['dialing', 'completed'], 'no_answer' => ['dialing', 'no_answer'], 'hung_up' => ['dialing', 'active', 'hung_up']] as $estado => $pasos) {
            DB::beginTransaction();
            try {
                foreach ($pasos as $paso) {
                    DB::table('voice_calls')->where('id', $primera)->update($this->patchFor($primera, $paso));
                }
                $this->assertAccepted(fn () => $this->insertOutbound(['remote_hash' => $hash]), "con la primera en {$estado}");
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_los_unique_de_d8_12(): void
    {
        $cri = (string) Str::uuid();
        $this->insertOutbound(['client_request_id' => $cri, 'requested_by_admin_id' => 1]);
        $this->assertUniqueRejects(fn () => $this->insertOutbound(['client_request_id' => $cri, 'requested_by_admin_id' => 1]),
            'vc_client_request_unique', 'voice_calls', ['requested_by_admin_id', 'client_request_id'], 'misma persona, misma petición');
        $this->assertAccepted(fn () => $this->insertOutbound(['client_request_id' => $cri, 'requested_by_admin_id' => 2, 'requested_by_name' => 'Beto']), 'otra persona, el mismo id: es otra petición');

        $entrante = $this->insertInbound();
        $fila = DB::table('voice_calls')->where('id', $entrante)->first();
        $this->assertUniqueRejects(fn () => $this->insertInbound(['provider_conversation_id' => $fila->provider_conversation_id]),
            'voice_calls_provider_conversation_id_unique', 'voice_calls', ['provider_conversation_id'], 'conversación');
        $this->assertUniqueRejects(fn () => $this->insertInbound(['provider_call_sid' => $fila->provider_call_sid]),
            'voice_calls_provider_call_sid_unique', 'voice_calls', ['provider_call_sid'], 'call_sid');
        $this->assertUniqueRejects(fn () => $this->insertLeg($entrante, ['provider_call_sid' => $fila->provider_call_sid]),
            'voice_calls_provider_call_sid_unique', 'voice_calls', ['provider_call_sid'], 'un tramo con el call_sid de la madre');
        $this->assertUniqueRejects(fn () => $this->insertInbound(['call_ref' => $fila->call_ref]),
            'voice_calls_call_ref_unique', 'voice_calls', ['call_ref'], 'call_ref');

        // Los reintentos de negocio son filas nuevas: (padre, intento) una vez.
        $padre = $this->reach('outbound', 'no_answer');
        $reintento = ['parent_call_id' => $padre, 'attempt' => 2, 'requested_by_type' => 'system', 'requested_by_admin_id' => null, 'requested_by_name' => null, 'requested_by_role' => null, 'client_request_id' => null];
        $this->assertAccepted(fn () => $this->insertOutbound($reintento), 'el reintento 2');
        $this->assertUniqueRejects(fn () => $this->insertOutbound($reintento), 'vc_retry_unique', 'voice_calls', ['parent_call_id', 'attempt'], 'el reintento 2 otra vez');
    }

    public function test_las_llamadas_no_se_borran(): void
    {
        $id = $this->insertOutbound();
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->delete(), 'las llamadas no se borran (DELETE en voice_calls)');
        $this->assertGuarded(fn () => DB::table('voice_calls')->delete(), 'las llamadas no se borran (DELETE en voice_calls)', 'DELETE sin WHERE');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE voice_calls CASCADE'), 'las llamadas no se borran (TRUNCATE en voice_calls)');
        }
        $this->assertSame(1, DB::table('voice_calls')->count());
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badCalls(): array
    {
        $hex = hash('sha256', 'x');
        $fin = '2026-11-03 12:00:00';

        return [
            // [de qué fila se parte (outbound | inbound | denied | leg), lo que se cambia, los CHECK que la rechazan]
            'dirección desconocida' => ['outbound', ['direction' => 'sideways'], ['vc_authorization_chk', 'vc_direction_chk', 'vc_direction_status_chk', 'vc_provider_binding_chk', 'vc_requester_chk', 'vc_target_chk']],
            'estado desconocido' => ['outbound', ['status' => 'ringing'], ['vc_ended_chk', 'vc_provider_binding_chk', 'vc_status_chk']],
            'saliente denied' => ['outbound', ['status' => 'denied', 'provider_conversation_id' => 'conv_1'], ['vc_direction_status_chk']],
            'entrante dialing' => ['inbound', ['status' => 'dialing'], ['vc_direction_status_chk']],
            'saliente sin origen' => ['outbound', ['target_source' => null], ['vc_target_chk']],
            'saliente desde marketing_calls' => ['outbound', ['target_source' => 'marketing_calls'], ['vc_target_chk']],
            'saliente a un destino de transferencia sin madre' => ['outbound', ['target_source' => 'transfer_destination', 'canary_number_uuid' => null], ['vc_target_chk']],
            'canario sin su uuid' => ['outbound', ['canary_number_uuid' => null], ['vc_target_chk']],
            'contacto con uuid de canario' => ['outbound', ['target_source' => 'voice_contacts', 'voice_contact_id' => 7], ['vc_target_chk']],
            'entrante con origen' => ['inbound', ['target_source' => 'canary_registry'], ['vc_target_chk']],
            'huella que no es hex' => ['outbound', ['remote_hash' => strtoupper($hex)], ['vc_remote_chk']],
            '4 dígitos que no son dígitos' => ['outbound', ['remote_last4' => '07a7'], ['vc_remote_chk']],
            'huella sin 4 dígitos' => ['outbound', ['remote_last4' => null], ['vc_remote_chk']],
            'saliente sin huella' => ['outbound', ['remote_hash' => null, 'remote_last4' => null], ['vc_remote_chk']],
            'persona sin petición' => ['outbound', ['client_request_id' => null], ['vc_requester_chk']],
            'persona sin id' => ['outbound', ['requested_by_admin_id' => null], ['vc_requester_chk']],
            'el sistema en el primer intento' => ['outbound', ['requested_by_type' => 'system', 'requested_by_admin_id' => null], ['vc_requester_chk']],
            'saliente sin quién' => ['outbound', ['requested_by_type' => null], ['vc_requester_chk']],
            'entrante con quién' => ['inbound', ['requested_by_type' => 'admin', 'requested_by_admin_id' => 1, 'requested_by_name' => 'Ana'], ['vc_requester_chk']],
            'intento 0' => ['outbound', ['attempt' => 0], ['vc_attempt_chk']],
            'intento 2 sin padre' => ['outbound', ['attempt' => 2], ['vc_attempt_chk']],
            'intento 1 con padre' => ['outbound', ['parent_call_id' => 1], ['vc_attempt_chk']],
            'reintento de sí mismo' => ['outbound', ['attempt' => 2, 'parent_call_id' => 999999, 'requested_by_type' => 'system', 'requested_by_admin_id' => null, 'client_request_id' => null], ['vc_attempt_chk']],
            'entrante en el intento 2' => ['inbound', ['attempt' => 2, 'parent_call_id' => 1], ['vc_attempt_chk']],
            'sello desconocido' => ['outbound', ['stamp_state' => 'maybe'], ['vc_stamp_chk']],
            'parte desconocida' => ['outbound', ['part' => 'beta'], ['vc_stamp_chk']],
            'sello aplicado sin spec' => ['outbound', ['spec_hash' => null], ['vc_stamp_chk']],
            'sello aplicado sin seq' => ['outbound', ['voice_publication_seq' => null], ['vc_stamp_chk']],
            'seq 0' => ['inbound', ['voice_publication_seq' => 0], ['vc_stamp_chk']],
            'saliente con el sello apagado' => ['outbound', ['stamp_state' => 'off', 'part' => null, 'provider_agent_id' => null, 'studio_version_uuid' => null, 'studio_content_hash' => null,
                'voice_profile_hash' => null, 'spec_hash' => null, 'payload_hash' => null, 'compiler_version' => null], ['vc_stamp_chk']],
            'sello apagado con agente' => ['denied', ['stamp_state' => 'off', 'policy_reason' => 'no_publication'], ['vc_stamp_chk']],
            'sello heredado sin madre' => ['denied', ['stamp_state' => 'inherited', 'voice_publication_seq' => null, 'part' => null, 'provider_agent_id' => null, 'studio_version_uuid' => null,
                'studio_content_hash' => null, 'voice_profile_hash' => null, 'spec_hash' => null, 'payload_hash' => null, 'compiler_version' => null], ['vc_stamp_chk']],
            'huella del perfil en mayúsculas' => ['outbound', ['voice_profile_hash' => strtoupper($hex)], ['vc_hashes_chk']],
            'versión del compilador ajena' => ['outbound', ['compiler_version' => 'otro-compilador/1'], ['vc_hashes_chk']],
            'otro proveedor' => ['outbound', ['provider' => 'twilio'], ['vc_provider_chk']],
            'call_sid sin forma' => ['inbound', ['provider_call_sid' => 'CA123'], ['vc_provider_ids_chk']],
            'conversación con espacios' => ['inbound', ['provider_conversation_id' => 'conv 1'], ['vc_provider_ids_chk']],
            'agente con un teléfono' => ['outbound', ['provider_agent_id' => '+573000000099'], ['vc_provider_ids_chk']],
            'entrante sin call_sid' => ['inbound', ['provider_call_sid' => null], ['vc_provider_binding_chk']],
            'requested con conversación' => ['outbound', ['provider_conversation_id' => 'conv_1'], ['vc_provider_binding_chk']],
            'completed sin ids' => ['outbound', ['status' => 'completed', 'ended_at' => $fin], ['vc_provider_binding_chk']],
            'final sin ended_at' => ['outbound', ['status' => 'canceled', 'policy_reason' => 'publication_moved'], ['vc_ended_chk']],
            'viva con ended_at' => ['inbound', ['ended_at' => $fin], ['vc_ended_chk']],
            '21 herramientas' => ['inbound', ['tool_calls' => 21], ['vc_counters_chk']],
            'denegada con herramientas' => ['denied', ['tool_calls' => 2], ['vc_counters_chk']],
            'reserva negativa' => ['outbound', ['reserved_micros' => -1], ['vc_counters_chk']],
            'sello verificado sin observar' => ['inbound', ['seal_verified' => true], ['vc_seal_chk']],
            'sello verificado con otro agente' => ['inbound', ['seal_verified' => true, 'observed_agent_id' => 'agent_otro'], ['vc_seal_chk']],
            'sello no verificado con el mismo agente' => ['inbound', ['seal_verified' => false, 'observed_agent_id' => 'agent_canary_1'], ['vc_seal_chk']],
            'motivo en texto libre' => ['denied', ['policy_reason' => 'No es un canario'], ['vc_codes_chk']],
            'terminación en texto libre' => ['inbound', ['termination_reason' => 'Call ended by remote party'], ['vc_codes_chk']],
            'entrante sin decisión' => ['inbound', ['caller_authorized' => null], ['vc_authorization_chk']],
            'entrante autorizada con motivo' => ['inbound', ['policy_reason' => 'not_an_active_voice_canary'], ['vc_authorization_chk']],
            'activa no autorizada' => ['inbound', ['caller_authorized' => false, 'policy_reason' => 'not_an_active_voice_canary'], ['vc_authorization_chk']],
            'denegada autorizada' => ['denied', ['caller_authorized' => true], ['vc_authorization_chk']],
            'denegada sin motivo' => ['denied', ['policy_reason' => null], ['vc_authorization_chk']],
            'denegada autorizada sin motivo' => ['denied', ['caller_authorized' => true, 'policy_reason' => null], ['vc_authorization_chk']],
            'saliente con decisión de entrante' => ['outbound', ['caller_authorized' => true], ['vc_authorization_chk']],
            'cancelada sin motivo' => ['outbound', ['status' => 'canceled', 'ended_at' => $fin], ['vc_authorization_chk']],
            'requested con motivo' => ['outbound', ['policy_reason' => 'publication_moved'], ['vc_authorization_chk']],
            // E1-2: los tramos.
            'tramo entrante' => ['leg', ['direction' => 'inbound'], ['vc_authorization_chk', 'vc_provider_binding_chk', 'vc_target_chk']],
            'tramo a un canario' => ['leg', ['target_source' => 'canary_registry', 'canary_number_uuid' => '7b0e6c4e-1111-4222-8333-444455556666'], ['vc_target_chk']],
            'tramo pedido por una persona' => ['leg', ['requested_by_type' => 'admin', 'requested_by_admin_id' => 1, 'requested_by_name' => 'Ana', 'client_request_id' => '7b0e6c4e-1111-4222-8333-444455556667'], ['vc_requester_chk']],
            'tramo en un reintento' => ['leg', ['attempt' => 2, 'parent_call_id' => 1], ['vc_attempt_chk']],
            'tramo con el sello aplicado' => ['leg', ['stamp_state' => 'applied'], ['vc_stamp_chk']],
            'tramo con conversación propia' => ['leg', ['provider_conversation_id' => 'conv_tramo'], ['vc_provider_binding_chk']],
            'tramo sin su llamada de Twilio' => ['leg', ['provider_call_sid' => null], ['vc_provider_binding_chk']],
            'tramo con herramientas' => ['leg', ['tool_calls' => 3], ['vc_transfer_leg_chk']],
            'tramo con reserva propia' => ['leg', ['reserved_micros' => 100], ['vc_transfer_leg_chk']],
            'tramo con el sello observado' => ['leg', ['observed_agent_id' => 'agent_canary_1'], ['vc_transfer_leg_chk']],
            'tramo de sí mismo' => ['leg', ['transfer_of_call_id' => 999999], ['vc_transfer_leg_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badCalls')]
    public function test_solo_en_postgres_cada_check_de_forma_de_una_llamada(string $partida, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK de la telefonía solo existen en PostgreSQL (en SQLite los vigila el servicio).');

        $base = match ($partida) {
            'outbound' => $this->outboundRow(),
            'inbound' => $this->inboundRow(),
            'denied' => $this->deniedRow(),
            'leg' => $this->legRow(1),
        };
        $this->assertSame([], $this->violatedChecks('voice_calls', $base), 'la fila de partida es válida');
        $fila = array_merge($base, $cambio);
        $this->assertSame($esperados, $this->violatedChecks('voice_calls', $fila));

        // Y la base la rechaza de verdad, también sin los disparadores (la última capa). El id fijo hace
        // que «de sí mismo» lo sea de verdad; las FKs se comprueban después de los CHECK.
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE voice_calls DISABLE TRIGGER USER');
            $this->assertRejected(fn () => DB::table('voice_calls')->insert(['id' => 999999] + $fila), '23514', $esperados[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_solo_en_postgres_las_filas_validas_de_cada_estado_pasan_todos_los_check(): void
    {
        $this->requirePostgres('los CHECK de la telefonía solo existen en PostgreSQL.');

        foreach (self::PATHS as $tipo => $caminos) {
            foreach (array_keys($caminos) as $estado) {
                $id = $this->reach($tipo, $estado);
                $fila = (array) DB::table('voice_calls')->where('id', $id)->first();
                unset($fila['id']);
                $this->assertSame([], $this->violatedChecks('voice_calls', $fila), "{$tipo} en {$estado}");
            }
        }
        foreach ([
            'denegada con la cabeza ilegible (fallback)' => $this->deniedRow(['stamp_state' => 'fallback', 'policy_reason' => 'publication_unreadable', 'part' => null, 'provider_agent_id' => null,
                'studio_version_uuid' => null, 'studio_content_hash' => null, 'voice_profile_hash' => null, 'spec_hash' => null, 'payload_hash' => null, 'compiler_version' => null]),
            'denegada de un número oculto' => $this->deniedRow(['remote_hash' => null, 'remote_last4' => null]),
            'denegada ya conciliada' => $this->deniedRow(['ended_at' => '2026-11-03 12:00:00', 'carrier_cost_micros' => 14180, 'duration_seconds' => 9]),
            'reintento del sistema' => $this->outboundRow(['attempt' => 2, 'parent_call_id' => 1, 'requested_by_type' => 'system', 'requested_by_admin_id' => null, 'requested_by_name' => null, 'client_request_id' => null]),
            'saliente a un contacto' => $this->outboundRow(['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => 1]),
            'tramo sin tarificar' => $this->legRow(1, ['carrier_cost_micros' => null]),
        ] as $que => $fila) {
            $this->assertSame([], $this->violatedChecks('voice_calls', $fila), $que);
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Una llamada (`outbound`, `inbound` o un tramo `leg`) en `$estado`, a la que se llega por pasos permitidos. */
    private function reach(string $tipo, string $estado): int
    {
        $id = match ($tipo) {
            'outbound' => $this->insertOutbound(),
            'inbound' => $estado === 'denied' ? $this->insertDenied() : $this->insertInbound(),
            'leg' => $this->insertLeg($this->insertInbound(), ['status' => 'dialing', 'ended_at' => null, 'duration_seconds' => null, 'carrier_cost_micros' => null]),
        };
        foreach (self::PATHS[$tipo][$estado] as $paso) {
            DB::table('voice_calls')->where('id', $id)->update($this->patchFor($id, $paso));
        }
        $this->assertSame($estado, DB::table('voice_calls')->where('id', $id)->value('status'));

        return $id;
    }

    /**
     * Lo que acompaña a cada estado para que la fila siga siendo válida en PG:
     * los ids del proveedor (una vez) al marcar o al resolver, el motivo al
     * cancelar y `ended_at` en los finales.
     *
     * @return array<string, mixed>
     */
    private function patchFor(int $id, string $hacia): array
    {
        $fila = DB::table('voice_calls')->where('id', $id)->first();
        $patch = ['status' => $hacia, 'status_changed_at' => now(), 'updated_at' => now()];
        $conIds = in_array($hacia, ['dialing', 'active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'], true);
        if ($conIds && $fila->transfer_of_call_id === null && $fila->provider_conversation_id === null && $fila->direction === 'outbound') {
            $patch['provider_conversation_id'] = 'conv_'.$id;
            $patch['provider_call_sid'] = $this->callSid("llamada-{$id}");
            $patch['started_at'] = now();
        }
        // Solo si no lo tiene: el motivo se fija una vez (así cada caso viola una sola guarda).
        if ($hacia === 'canceled' && $fila->policy_reason === null) {
            $patch['policy_reason'] = 'publication_moved';
        }
        if ($hacia === 'failed_to_start') {
            $patch['failure_code'] = 'provider_rejected';
        }
        if (in_array($hacia, ['canceled', 'failed_to_start', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'], true)) {
            $patch['ended_at'] = now();
        }

        return $patch;
    }
}
