<?php

namespace Tests\Feature\Outreach\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DECISIONES, RESULTADOS, FRENOS Y RESERVAS DEL HITO 9 EN LA BASE (DB9), en
 * los dos motores:
 *  · decisiones y resultados, de solo inserción, con el sello de su
 *    inscripción y la matriz fuente → resultado de D9-10 (PG);
 *  · frenos y PARADA GLOBAL (D9-14): uno abierto por alcance; se sueltan una
 *    vez, con nota, por otra cuenta y otra persona (P9-20);
 *  · la reserva del día (D9-8, etapa 5): la comprobación va en el mismo UPDATE
 *    y, por debajo, la base no deja pasar del techo.
 * Las carreras con dos conexiones, en `OutreachConcurrencyTest` (PG).
 */
class OutreachLedgerSchemaTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const P = 'outreach: ';

    private const DEC_SEAL = self::P.'una decisión lleva el sello de su inscripción (o, sin ella, el de su versión) y su persona (INSERT en outreach_decisions)';

    private const DEC_JOB = self::P.'una decisión sobre un trabajo es de la inscripción de ese trabajo (INSERT en outreach_decisions)';

    private const DEC_TEMPLATE = self::P.'una decisión cita una plantilla por su huella exacta (INSERT en outreach_decisions)';

    private const DEC_PUBLICATION = self::P.'una decisión nombra una entrada de la cadena de su estrategia (INSERT en outreach_decisions)';

    private const OUT_JOB = self::P.'un resultado de un trabajo es de la inscripción de ese trabajo y de su canal (INSERT en outreach_outcomes)';

    private const OUT_OF = self::P.'un resultado lleva la estrategia y la persona de su inscripción (INSERT en outreach_outcomes)';

    private const BRK_STARTS = self::P.'un freno nace abierto (INSERT en outreach_brakes)';

    private const BRK_FINAL = self::P.'un freno se suelta una sola vez (UPDATE en outreach_brakes)';

    private const BRK_FROZEN = self::P.'un freno fija al abrirse su alcance, su causa, su nota y quién lo abrió (UPDATE en outreach_brakes)';

    private const BRK_FOUR_EYES = self::P.'un freno lo suelta otra cuenta y otra persona que quien lo abrió (P9-20) (UPDATE en outreach_brakes)';

    private const BUD_FROZEN = self::P.'una reserva del día no cambia de día, de alcance ni de modo (UPDATE en outreach_budget_counters)';

    /** D9-10, columna «Fuentes» (escrita aquí desde el contrato, no tomada de la migración). */
    private const SOURCE_OUTCOMES = [
        'simulated' => ['not_attempted'],
        'staff' => ['no_answer', 'answered', 'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked'],
        'crm_fact' => ['appointment_booked', 'converted'],
        'system' => ['blocked', 'failed', 'ambiguous', 'converted', 'no_response'],
        'meta_status' => ['failed', 'sent', 'delivered', 'read'],
        'inbound_message' => ['replied', 'human_requested', 'opted_out'],
        'voice_call' => ['failed', 'sent', 'no_answer', 'answered', 'human_requested', 'wrong_person', 'opted_out'],
        'classifier' => ['callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out'],
    ];

    /** D9-10, «Transiciones permitidas» (escrita aquí desde el contrato; la misma que `OutcomeTable::TABLE`). */
    private const TRANSITIONS = [
        'ambiguous' => [], 'delivered' => [], 'not_attempted' => [], 'read' => [], 'sent' => [],
        'answered' => ['end_exit', 'end_success', 'handoff_human', 'next_step'], 'appointment_booked' => ['end_success', 'next_step'],
        'blocked' => ['end_exit', 'wait'], 'callback_requested' => ['end_exit', 'wait'], 'converted' => ['end_success'],
        'failed' => ['end_exit', 'next_step', 'retry_step'], 'human_requested' => ['handoff_human'], 'no_answer' => ['end_exit', 'next_step', 'retry_step', 'wait'],
        'no_response' => ['end_exit'], 'not_interested' => ['end_exit'], 'opted_out' => ['end_exit'], 'replied' => ['end_exit', 'handoff_human'], 'wrong_person' => ['end_exit'],
    ];

    private const OUTCOMES = ['blocked', 'not_attempted', 'failed', 'ambiguous', 'sent', 'delivered', 'read', 'no_answer', 'answered', 'replied', 'callback_requested',
        'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked', 'converted', 'no_response'];

    /** Lo que sale de un trabajo lleva su trabajo y su canal (la sonda no mira las FK). */
    private const DE_UN_TRABAJO = ['job_id' => 1, 'channel' => 'assisted'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── Decisiones ──────────────────────────────────────────────────────────

    public function test_las_decisiones_son_de_solo_insercion(): void
    {
        $id = $this->insertDecision($this->insertEnrollment($this->liveVersion()));
        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_DECISIONS.' SET level = level WHERE id = ?', [$id]), self::P.'las decisiones son de solo inserción (UPDATE en outreach_decisions)');
        $this->assertGuarded(fn () => $this->db()->table(self::T_DECISIONS)->where('id', $id)->delete(), self::P.'las decisiones son de solo inserción (DELETE en outreach_decisions)');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::T_DECISIONS), self::P.'las decisiones son de solo inserción (TRUNCATE en outreach_decisions)');
        }
        $this->assertSame(1, $this->db()->table(self::T_DECISIONS)->count());
    }

    public function test_una_decision_lleva_el_sello_y_la_persona_de_su_inscripcion_y_cita_bien_trabajo_plantilla_y_entrada(): void
    {
        $live = $this->liveVersion();
        $otra = $this->liveVersion();
        $e = $this->insertEnrollment($live);
        foreach ([
            'otra huella' => ['content_hash' => $this->hex('x')],
            'otra persona' => ['subject_key' => 'm:424242'],
            'otro modo' => ['mode' => 'live'],
            'otra versión' => ['strategy_version_id' => $otra['version']],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => $this->insertDecision($e, $cambio), self::DEC_SEAL, $que);
        }
        // Sin inscripción (p. ej., una negativa de N1 antes de inscribir): el sello es el de su versión.
        $this->assertAccepted(fn () => $this->insertDecision($e, ['enrollment_id' => null, 'kind' => 'deny', 'level' => 'N1', 'reasons' => '["no_consent_for_purpose_channel"]']), 'sin inscripción');
        $this->assertGuarded(fn () => $this->insertDecision($e, ['enrollment_id' => null, 'kind' => 'deny', 'level' => 'N1', 'reasons' => '["subject_minor"]',
            'content_hash' => $this->hex('y')]), self::DEC_SEAL, 'sin inscripción y otra huella');

        $trabajo = $this->insertJob($this->insertEnrollment($live));
        $this->assertGuarded(fn () => $this->insertDecision($e, ['job_id' => $trabajo, 'kind' => 'cancel', 'reasons' => '["strategy_moved"]']), self::DEC_JOB, 'el trabajo de otra inscripción');
        $this->assertGuarded(fn () => $this->insertDecision($e, ['enrollment_id' => null, 'job_id' => $trabajo, 'kind' => 'cancel', 'reasons' => '["strategy_moved"]']), self::DEC_JOB, 'un trabajo sin inscripción');
        $plantilla = $this->approvedTemplate();
        $this->assertGuarded(fn () => $this->insertDecision($e, ['kind' => 'plan', 'template_version_id' => $plantilla, 'template_hash' => $this->hex('otra-plantilla')]), self::DEC_TEMPLATE, 'otra huella de la plantilla');
        $this->assertGuarded(fn () => $this->insertDecision($e, ['publication_seq' => $otra['seq']]), self::DEC_PUBLICATION, 'la entrada de otra estrategia');
        $this->assertAccepted(fn () => $this->insertDecision($e, ['kind' => 'plan', 'template_version_id' => $plantilla,
            'template_hash' => (string) $this->db()->table(self::T_TEMPLATES)->where('id', $plantilla)->value('content_hash')]), 'el plan con su plantilla');
    }

    // ── Resultados ──────────────────────────────────────────────────────────

    public function test_los_resultados_son_de_solo_insercion_idempotentes_por_fuente_y_de_su_inscripcion(): void
    {
        $live = $this->liveVersion();
        $e = $this->insertEnrollment($live);
        $id = $this->insertOutcome($e, ['source_key' => 'job:abc']);   // la ventana vencida (system · no_response → end_exit)
        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_OUTCOMES.' SET outcome = outcome WHERE id = ?', [$id]), self::P.'los resultados son de solo inserción (UPDATE en outreach_outcomes)');
        $this->assertGuarded(fn () => $this->db()->table(self::T_OUTCOMES)->where('id', $id)->delete(), self::P.'los resultados son de solo inserción (DELETE en outreach_outcomes)');
        // Un duplicado de la misma fuente se descarta (T14): UNIQUE, y `insertOrIgnore` no avanza nada.
        $this->assertUniqueRejects(fn () => $this->insertOutcome($e, ['source_key' => 'job:abc']), 'oout_source_unique', self::T_OUTCOMES, ['enrollment_id', 'source', 'source_key'], 'el mismo');
        $this->assertSame(0, $this->db()->table(self::T_OUTCOMES)->insertOrIgnore($this->outcomeRow($e, ['source_key' => 'job:abc'])));
        $this->assertAccepted(fn () => $this->insertOutcome($e, ['source' => 'crm_fact', 'outcome' => 'converted', 'next_action' => 'end_success', 'source_key' => 'job:abc']),
            'la misma clave de otra fuente');
        // L9-3: lleva la estrategia, la persona y el canal de lo que resume.
        $otra = $this->insertEnrollment($live);
        $deOtra = $this->insertJob($otra);
        $this->assertGuarded(fn () => $this->insertOutcome($e, ['job' => $deOtra, 'source' => 'simulated', 'outcome' => 'not_attempted', 'next_action' => null]),
            self::OUT_JOB, 'el trabajo de otra inscripción');
        $propio = $this->insertJob($e);
        $this->assertGuarded(fn () => $this->insertOutcome($e, ['job_id' => $propio, 'channel' => 'assisted', 'source' => 'simulated', 'outcome' => 'not_attempted', 'next_action' => null]),
            self::OUT_JOB, 'otro canal que el de su trabajo');
        foreach ([
            'otra persona' => ['subject_key' => 'm:424242'],
            'otro seudónimo' => ['subject_ref' => $this->hex('otro')],
            'otra estrategia' => ['strategy_id' => $this->insertStrategy()],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => $this->insertOutcome($e, $cambio), self::OUT_OF, $que);
        }
        $this->assertAccepted(fn () => $this->db()->table(self::T_OUTCOMES)->insert($this->simulatedOutcomeRow($e, $propio)), 'el del simulado, con su trabajo y su canal');
        $this->assertSame(3, $this->db()->table(self::T_OUTCOMES)->where('enrollment_id', $e)->count());
    }

    public function test_solo_en_postgres_cada_fuente_solo_da_sus_resultados_de_d9_10(): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL.');

        $base = $this->validRow(self::T_OUTCOMES);
        $casos = 0;
        foreach (array_keys(self::SOURCE_OUTCOMES) as $fuente) {
            foreach ([...self::OUTCOMES, 'provider_error', 'needs_review'] as $resultado) {
                $transicion = self::TRANSITIONS[$resultado][0] ?? null;
                $fila = array_merge($base, ['source' => $fuente, 'outcome' => $resultado, 'next_action' => $transicion,
                    'reason_code' => $resultado === 'blocked' ? 'calendar_sunday' : null], match ($fuente) {
                        'staff' => ['recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro'],
                        'classifier' => ['deterministic' => false, 'confidence' => 0.91],
                        default => [],
                    }, in_array($fuente, ['crm_fact', 'system'], true) ? [] : ['job_id' => 1, 'channel' => 'assisted']);
                $esperados = [];
                if (! in_array($resultado, self::OUTCOMES, true)) {
                    // Un código fuera del conjunto (los de §13.1 #18 y #21, L9-12) no cabe: ni el resultado, ni su fuente, ni su transición.
                    array_push($esperados, 'oout_next_action_chk', 'oout_outcome_chk');
                }
                if ($fuente === 'classifier' && ! in_array($transicion, ['end_exit', 'handoff_human', 'wait'], true)) {
                    // P9-6: lo del clasificador solo frena o deriva (sin transición, o con una que avanza, no cabe).
                    $esperados[] = 'oout_classifier_brakes_chk';
                }
                if (! in_array($resultado, self::SOURCE_OUTCOMES[$fuente], true)) {
                    $esperados[] = 'oout_source_outcome_chk';
                }
                sort($esperados, SORT_STRING);
                $reales = $this->violatedChecks(self::T_OUTCOMES, $fila);
                sort($reales, SORT_STRING);
                $this->assertSame($esperados, $reales, "{$fuente} → {$resultado}");
                $casos++;
            }
        }
        $this->assertSame(160, $casos);
    }

    // ── Frenos y PARADA GLOBAL ──────────────────────────────────────────────

    public function test_un_freno_nace_abierto_uno_por_alcance_y_se_suelta_una_vez(): void
    {
        $this->assertGuarded(fn () => $this->insertBrake(['released_at' => now(), 'released_by_admin_id' => 2, 'released_by_name' => 'Beto', 'release_note' => 'x']), self::BRK_STARTS, 'nace suelto');
        $global = $this->insertBrake();
        $this->assertPartialRejects(fn () => $this->insertBrake(), 'obrk_one_open', 'dos PARADAS GLOBALES abiertas');
        $estrategia = (string) Str::uuid();
        $porEstrategia = fn (string $ref): int => $this->insertBrake(['scope' => 'strategy', 'scope_ref' => $ref, 'note' => 'Pausa de la estrategia']);
        $porEstrategia($estrategia);
        $this->assertPartialRejects(fn () => $porEstrategia($estrategia), 'obrk_one_open', 'dos de la misma estrategia');
        $this->assertAccepted(fn () => $porEstrategia((string) Str::uuid()), 'otra estrategia');
        $this->assertAccepted(fn () => $this->insertBrake(['scope' => 'channel', 'scope_ref' => 'assisted', 'note' => 'Sin personal hoy']), 'el canal asistido');
        // El monitor abre solo (D9-14): sin persona ni nota.
        $this->assertAccepted(fn () => $this->insertBrake(['scope' => 'channel', 'scope_ref' => 'whatsapp_template', 'reason' => 'failure_spike', 'note' => null,
            'opened_by_type' => 'system', 'opened_by_admin_id' => null, 'opened_by_name' => 'monitor', 'opened_by_person_id' => null, 'client_request_id' => null]), 'el monitor');

        $this->assertAccepted(fn () => $this->releaseBrake($global), 'Beto suelta la de Ana');
        $this->assertGuarded(fn () => $this->releaseBrake($global, 3), self::BRK_FINAL, 'soltarla dos veces');
        $this->assertGuarded(fn () => $this->db()->table(self::T_BRAKES)->where('id', $global)->update(['updated_at' => now()]), self::BRK_FINAL, 'tocar una suelta');
        $this->assertAccepted(fn () => $this->insertBrake(), 'una PARADA GLOBAL nueva tras soltar la anterior');
        $this->assertGuarded(fn () => $this->db()->table(self::T_BRAKES)->where('id', $global)->delete(), self::P.'los frenos se sueltan, no se borran (DELETE en outreach_brakes)');
    }

    public function test_nadie_suelta_un_freno_que_abrio_ni_con_otra_cuenta_de_la_misma_persona(): void
    {
        $p = $this->people();
        $this->verifyBinding($this->insertBinding(6, $p[1], ['created_by_admin_id' => 2, 'created_by_name' => 'Beto']), 3, $p[3]);   // la otra cuenta de Ana
        $this->people[6] = $p[1];
        $freno = $this->insertBrake();   // la abre Ana (1)
        foreach ([1 => 'Ana', 6 => 'la otra cuenta de Ana'] as $quien => $que) {
            $this->isPostgres()
                ? $this->assertRejected(fn () => $this->releaseBrake($freno, $quien), '23514', 'obrk_four_eyes_chk', $que)
                : $this->assertRejected(fn () => $this->releaseBrake($freno, $quien), '23000', self::BRK_FOUR_EYES, $que);
        }
        $this->assertNull($this->db()->table(self::T_BRAKES)->where('id', $freno)->value('released_at'));
        $this->assertAccepted(fn () => $this->releaseBrake($freno, 3), 'Caro la suelta');

        $id = $this->insertBrake(['scope' => 'channel', 'scope_ref' => 'voice_call', 'note' => 'Voz parada']);
        foreach (['id', 'uuid', 'client_request_id', 'scope', 'scope_ref', 'reason', 'note', 'opened_by_type', 'opened_by_admin_id', 'opened_by_name', 'opened_by_person_id',
            'opened_at', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_BRAKES." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::BRK_FROZEN, $columna);
        }
    }

    // ── La reserva del día ──────────────────────────────────────────────────

    public function test_la_reserva_se_comprueba_en_el_mismo_update_y_la_base_no_deja_pasar_del_techo(): void
    {
        $c = $this->insertBudget(['cap_units' => 3, 'cap_micros' => 30000]);
        $this->assertSame(1, $this->reserve($c, 1, 10000));
        $this->assertSame(1, $this->reserve($c, 2, 20000), 'exactamente el techo');
        $this->assertSame(0, $this->reserve($c, 1), 'agotado: budget_exhausted');
        $this->assertSame(0, $this->reserve($c, 0, 1), 'agotado en micros');
        $this->assertSame([3, 30000], [(int) $this->db()->table(self::T_BUDGETS)->where('id', $c)->value('reserved_units'), (int) $this->db()->table(self::T_BUDGETS)->where('id', $c)->value('reserved_micros')]);

        // Aunque el UPDATE no lo comprobara, la base no deja pasar del techo ni bajar de cero.
        $techo = $this->isPostgres() ? ['23514', 'obud_limits_chk'] : ['23000', self::P.'una reserva del día no pasa de su techo (UPDATE en outreach_budget_counters)'];
        $this->assertRejected(fn () => DB::statement('UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units + 1 WHERE id = ?', [$c]), ...[...$techo, 'pasar del techo']);
        $this->assertRejected(fn () => DB::statement('UPDATE '.self::T_BUDGETS.' SET reserved_micros = reserved_micros + 1 WHERE id = ?', [$c]), ...[...$techo, 'pasar del techo en micros']);
        $this->assertRejected(fn () => DB::statement('UPDATE '.self::T_BUDGETS.' SET cap_units = 2 WHERE id = ?', [$c]), ...[...$techo, 'bajar el techo por debajo de lo reservado']);
        // Cancelar o vencer libera (prueba 16); nunca por debajo de cero.
        $this->assertSame(1, $this->db()->update('UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units - 3, reserved_micros = reserved_micros - 30000 WHERE id = ?', [$c]));
        $this->assertRejected(fn () => DB::statement('UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units - 1 WHERE id = ?', [$c]), ...[...$techo, 'bajar de cero']);
        $insercion = $this->isPostgres() ? ['23514', 'obud_limits_chk'] : ['23000', self::P.'una reserva del día no pasa de su techo (INSERT en outreach_budget_counters)'];
        $this->assertRejected(fn () => $this->insertBudget(['scope' => 'channel:voice_call', 'cap_units' => 0, 'reserved_units' => 1]), ...[...$insercion, 'nace por encima del techo (presupuesto 0)']);

        // Uno por día, alcance y modo; sombra y live no comparten techo.
        $this->assertUniqueRejects(fn () => $this->insertBudget(['cap_units' => 3, 'cap_micros' => 30000]), 'obud_scope_unique', self::T_BUDGETS, ['budget_day', 'scope', 'mode'], 'otro contador igual');
        $this->assertAccepted(fn () => $this->insertBudget(['mode' => 'shadow']), 'el de sombra');
        $this->assertAccepted(fn () => $this->insertBudget(['scope' => 'strategy:'.Str::uuid()]), 'el de una estrategia');
        // L9-30: el de un teléfono (un contacto al día también por número).
        $telefono = $this->insertBudget(['scope' => 'phone:'.$this->hex('telefono'), 'cap_units' => 1]);
        $this->assertSame([1, 0], [$this->reserve($telefono, 1), $this->reserve($telefono, 1)], 'un contacto al día por teléfono');
        foreach (['id', 'budget_day', 'scope', 'mode', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_BUDGETS." SET {$columna} = {$columna} WHERE id = ?", [$c]), self::BUD_FROZEN, $columna);
        }
        $this->assertGuarded(fn () => $this->db()->table(self::T_BUDGETS)->where('id', $c)->delete(), self::P.'las reservas del día no se borran (DELETE en outreach_budget_counters)');
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badRows(): array
    {
        $diecisiete = '['.implode(',', array_map(static fn (int $i): string => '"motivo_'.$i.'"', range(1, 17))).']';

        return [
            // Decisiones (la base: un `enroll` sin nivel ni motivos).
            'decisión: tipo desconocido' => [self::T_DECISIONS, ['kind' => 'skip'], ['odec_kind_chk', 'odec_level_rule_chk', 'odec_reasons_chk']],
            'decisión: deny sin nivel' => [self::T_DECISIONS, ['kind' => 'deny', 'reasons' => '["subject_minor"]'], ['odec_level_rule_chk']],
            'decisión: enroll con nivel' => [self::T_DECISIONS, ['level' => 'N5'], ['odec_level_rule_chk']],
            'decisión: nivel N8' => [self::T_DECISIONS, ['kind' => 'deny', 'level' => 'N8', 'reasons' => '["x"]'], ['odec_level_chk']],
            'decisión: deny sin motivos' => [self::T_DECISIONS, ['kind' => 'deny', 'level' => 'N1'], ['odec_reasons_chk']],
            'decisión: motivos que no son lista' => [self::T_DECISIONS, ['reasons' => '{"n1":"subject_minor"}'], ['odec_reasons_chk']],
            'decisión: motivo en texto libre' => [self::T_DECISIONS, ['kind' => 'exclude', 'reasons' => '["No tiene permiso"]'], ['odec_reasons_chk']],
            'decisión: 17 motivos' => [self::T_DECISIONS, ['kind' => 'exclude', 'reasons' => $diecisiete], ['odec_reasons_chk']],
            'decisión: motivos con espacios' => [self::T_DECISIONS, ['kind' => 'exclude', 'reasons' => '["a", "b"]'], ['odec_reasons_chk']],
            'decisión: defer sin cuándo' => [self::T_DECISIONS, ['kind' => 'defer', 'level' => 'N1', 'reasons' => '["calendar_sunday"]'], ['odec_retry_chk']],
            'decisión: enroll con cuándo' => [self::T_DECISIONS, ['retry_at' => '2026-11-16 13:00:00'], ['odec_retry_chk']],
            'decisión: hechos que no son objeto' => [self::T_DECISIONS, ['facts_json' => '[7]'], ['odec_facts_chk']],
            'decisión: hechos de más de 4 KB' => [self::T_DECISIONS, ['facts_json' => '{"x":"'.str_repeat('a', 4096).'"}'], ['odec_facts_chk']],
            'decisión: huella de los hechos corta' => [self::T_DECISIONS, ['facts_hash' => 'abc'], ['odec_facts_chk']],
            'decisión: traza que no es lista' => [self::T_DECISIONS, ['trace_json' => '{"path":"0"}'], ['odec_trace_chk']],
            'decisión: traza de más de 16 KB' => [self::T_DECISIONS, ['trace_json' => '["'.str_repeat('a', 16384).'"]'], ['odec_trace_chk']],
            'decisión: plantilla sin huella' => [self::T_DECISIONS, ['template_version_id' => 1], ['odec_template_chk']],
            'decisión: modo off' => [self::T_DECISIONS, ['mode' => 'off'], ['odec_mode_chk']],
            'decisión: seudónimo corto' => [self::T_DECISIONS, ['subject_ref' => 'abc'], ['odec_subject_chk']],
            'decisión: evaluador con espacios' => [self::T_DECISIONS, ['evaluator_version' => 'evaluador 1'], ['odec_seal_chk']],
            // Resultados (la base: la ventana vencida, system · no_response → end_exit, sin trabajo).
            'resultado: el clasificador sin confianza' => [self::T_OUTCOMES, ['source' => 'classifier', 'outcome' => 'opted_out', 'deterministic' => false] + self::DE_UN_TRABAJO, ['oout_classifier_chk']],
            'resultado: el clasificador determinista' => [self::T_OUTCOMES, ['source' => 'classifier', 'outcome' => 'opted_out', 'confidence' => 0.4] + self::DE_UN_TRABAJO, ['oout_classifier_chk']],
            'resultado: confianza de 1,5' => [self::T_OUTCOMES, ['source' => 'classifier', 'outcome' => 'opted_out', 'deterministic' => false, 'confidence' => 1.5] + self::DE_UN_TRABAJO,
                ['oout_classifier_chk']],
            'resultado: el clasificador acelera (P9-6)' => [self::T_OUTCOMES, ['source' => 'classifier', 'outcome' => 'callback_requested', 'deterministic' => false, 'confidence' => 0.95,
                'next_action' => 'next_step'] + self::DE_UN_TRABAJO, ['oout_classifier_brakes_chk', 'oout_next_action_chk']],
            'resultado: el sistema con confianza' => [self::T_OUTCOMES, ['confidence' => 0.5], ['oout_classifier_chk']],
            'resultado: el personal sin quién' => [self::T_OUTCOMES, ['source' => 'staff', 'outcome' => 'answered'] + self::DE_UN_TRABAJO, ['oout_staff_chk']],
            'resultado: el sistema con quién' => [self::T_OUTCOMES, ['recorded_by_admin_id' => 2, 'recorded_by_name' => 'Beto'], ['oout_staff_chk']],
            'resultado: clave con espacios' => [self::T_OUTCOMES, ['source_key' => 'job abc'], ['oout_codes_chk']],
            'resultado: detalle en texto libre' => [self::T_OUTCOMES, ['detail_code' => 'Dijo que no'], ['oout_codes_chk']],
            'resultado: motivo en texto libre' => [self::T_OUTCOMES, ['reason_code' => 'No quiso'], ['oout_codes_chk']],
            'resultado: bloqueado sin motivo' => [self::T_OUTCOMES, ['outcome' => 'blocked', 'next_action' => 'wait'], ['oout_codes_chk']],
            'resultado: fuente desconocida' => [self::T_OUTCOMES, ['source' => 'n8n'], ['oout_links_chk', 'oout_source_chk', 'oout_source_outcome_chk']],
            'resultado: el simulado sin su trabajo' => [self::T_OUTCOMES, ['source' => 'simulated', 'outcome' => 'not_attempted', 'next_action' => null], ['oout_links_chk']],
            'resultado: un trabajo sin canal' => [self::T_OUTCOMES, ['job_id' => 1], ['oout_links_chk']],
            'resultado: canal desconocido' => [self::T_OUTCOMES, ['job_id' => 1, 'channel' => 'sms'], ['oout_channel_chk']],
            'resultado: una baja que sigue (prueba 10)' => [self::T_OUTCOMES, ['source' => 'staff', 'outcome' => 'opted_out', 'recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro',
                'next_action' => 'next_step'] + self::DE_UN_TRABAJO, ['oout_next_action_chk']],
            'resultado: sin su transición' => [self::T_OUTCOMES, ['next_action' => null], ['oout_next_action_chk']],
            'resultado: ambiguo con transición' => [self::T_OUTCOMES, ['outcome' => 'ambiguous', 'next_action' => 'end_exit'], ['oout_next_action_chk']],
            'resultado: una hora para salir' => [self::T_OUTCOMES, ['next_action_at' => '2026-11-14 14:00:00'], ['oout_next_action_chk']],
            'resultado: suprimido con la persona' => [self::T_OUTCOMES, ['redacted_at' => '2028-05-12 00:00:00'], ['oout_subject_chk']],
            // Frenos (la base: la PARADA GLOBAL de Ana, abierta).
            'freno: alcance desconocido' => [self::T_BRAKES, ['scope' => 'person'], ['obrk_scope_chk', 'obrk_scope_ref_chk']],
            'freno: global con referencia' => [self::T_BRAKES, ['scope_ref' => 'assisted'], ['obrk_scope_ref_chk']],
            'freno: de estrategia sin uuid' => [self::T_BRAKES, ['scope' => 'strategy', 'scope_ref' => 'renovacion-7d'], ['obrk_scope_ref_chk']],
            'freno: del canal simulado' => [self::T_BRAKES, ['scope' => 'channel', 'scope_ref' => 'simulated'], ['obrk_scope_ref_chk']],
            'freno: causa desconocida' => [self::T_BRAKES, ['reason' => 'boredom'], ['obrk_opener_chk', 'obrk_reason_chk']],
            'freno: una persona con una causa del monitor' => [self::T_BRAKES, ['reason' => 'unknown_send'], ['obrk_opener_chk']],
            'freno: una persona sin motivo escrito' => [self::T_BRAKES, ['note' => null], ['obrk_opener_chk']],
            'freno: el monitor con una persona' => [self::T_BRAKES, ['opened_by_type' => 'system', 'reason' => 'unknown_send'], ['obrk_opener_chk']],
            'freno: pico de bajas en un canal' => [self::T_BRAKES, ['scope' => 'channel', 'scope_ref' => 'assisted', 'opened_by_type' => 'system', 'opened_by_admin_id' => null,
                'opened_by_person_id' => null, 'reason' => 'opt_out_spike'], ['obrk_cause_scope_chk']],
            'freno: envío desconocido en una estrategia' => [self::T_BRAKES, ['scope' => 'strategy', 'scope_ref' => '00000000-0000-4000-8000-000000000001', 'opened_by_type' => 'system',
                'opened_by_admin_id' => null, 'opened_by_person_id' => null, 'reason' => 'unknown_send'], ['obrk_cause_scope_chk']],
            'freno: suelto sin nota' => [self::T_BRAKES, ['released_at' => '2026-11-12 16:00:00', 'released_by_admin_id' => 2, 'released_by_name' => 'Beto'], ['obrk_release_chk']],
            'freno: suelto antes de abrirse' => [self::T_BRAKES, ['released_at' => '2026-11-12 14:00:00', 'released_by_admin_id' => 2, 'released_by_name' => 'Beto', 'release_note' => 'ok'],
                ['obrk_release_chk']],
            'freno: lo suelta quien lo abrió' => [self::T_BRAKES, ['released_at' => '2026-11-12 16:00:00', 'released_by_admin_id' => 1, 'released_by_name' => 'Ana', 'release_note' => 'ok'],
                ['obrk_four_eyes_chk']],
            // Reservas (la base: el asistido en live, techo 5).
            'reserva: canal que no gasta' => [self::T_BUDGETS, ['scope' => 'channel:simulated'], ['obud_scope_chk']],
            'reserva: teléfono en claro' => [self::T_BUDGETS, ['scope' => 'phone:573001234567'], ['obud_scope_chk']],
            'reserva: estrategia sin uuid' => [self::T_BUDGETS, ['scope' => 'strategy:renovacion-7d'], ['obud_scope_chk']],
            'reserva: modo off' => [self::T_BUDGETS, ['mode' => 'off'], ['obud_mode_chk']],
            'reserva: techo negativo' => [self::T_BUDGETS, ['cap_units' => -1], ['obud_limits_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badRows')]
    public function test_solo_en_postgres_cada_check_de_forma(string $tabla, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL (en SQLite los vigila el servicio, salvo el techo y los cuatro ojos del freno).');

        $this->assertCheckShape($tabla, $this->validRow($tabla), $cambio, $esperados);
    }

    /** @return array<string, mixed> la fila válida de cada tabla, sin insertarla */
    private function validRow(string $tabla): array
    {
        DB::beginTransaction();
        try {
            $id = match ($tabla) {
                self::T_DECISIONS => $this->insertDecision($this->insertEnrollment($this->liveVersion())),
                self::T_OUTCOMES => $this->insertOutcome($this->insertEnrollment($this->liveVersion())),
                self::T_BRAKES => $this->insertBrake(),
                self::T_BUDGETS => $this->insertBudget(),
            };
            $fila = (array) DB::table($tabla)->where('id', $id)->first();
        } finally {
            DB::rollBack();
            $this->people = [];
        }
        unset($fila['id']);

        return $fila;
    }
}
