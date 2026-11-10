<?php

namespace Tests\Feature\Outreach\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LA VIDA DE LAS FILAS DE LA EJECUCIÓN (Hito 9, DB9; `outreach_*`), en los dos
 * motores: pasadas (se cierran una vez), cursores (solo crecen),
 * inscripciones (D9-10: active ⇄ waiting y de ahí a un final; una ocurrencia →
 * una inscripción; una abierta por estrategia, persona y modo; el sello de una
 * versión viva) y trabajos (D9-8: planned → claimed → dispatching → final; un
 * trabajo vivo por persona y modo; «una vez»; en sombra nada sale). Lo
 * esperado sale del contrato (§6.2, D9-8, D9-10) y está escrito aquí.
 */
class OutreachLifecycleTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const P = 'outreach: ';

    private const RUN_STARTS = self::P.'una pasada nace running, sin cerrar (INSERT en outreach_runs)';

    private const RUN_FORWARD = self::P.'una pasada solo avanza: running → completed, failed o aborted (UPDATE en outreach_runs)';

    private const RUN_FINAL = self::P.'una pasada cerrada es definitiva (UPDATE en outreach_runs)';

    private const RUN_FROZEN = self::P.'una pasada fija al empezar su tipo, su modo y su alcance (UPDATE en outreach_runs)';

    private const CURSOR_FORWARD = self::P.'un cursor solo avanza (last_id nunca baja) (UPDATE en outreach_cursors)';

    private const CURSOR_FROZEN = self::P.'un cursor no cambia de fuente (UPDATE en outreach_cursors)';

    private const ENR_STARTS = self::P.'una inscripción nace active o waiting, en el paso 0, sin contactos ni resultado (INSERT en outreach_enrollments)';

    private const ENR_FORWARD = self::P.'una inscripción solo avanza: active ⇄ waiting y de ahí a un final (UPDATE en outreach_enrollments)';

    private const ENR_FINAL = self::P.'una inscripción cerrada es definitiva (UPDATE en outreach_enrollments)';

    private const ENR_FROZEN = self::P.'una inscripción fija al nacer su persona, su ciclo, su finalidad, su ventana y su sello (UPDATE en outreach_enrollments)';

    private const ENR_COUNTERS = self::P.'el paso y los contactos de una inscripción solo crecen (UPDATE en outreach_enrollments)';

    private const ENR_SEAL = self::P.'una inscripción lleva el sello exacto de una versión viva (CANARY o PUBLISHED) de su estrategia (INSERT en outreach_enrollments)';

    private const ENR_PURPOSE = self::P.'una inscripción tiene la finalidad de su estrategia (INSERT en outreach_enrollments)';

    private const ENR_PUBLICATION = self::P.'una inscripción nombra una entrada de la cadena de su estrategia (INSERT en outreach_enrollments)';

    private const JOB_STARTS = self::P.'un trabajo nace planned, sin reclamar ni salir (INSERT en outreach_jobs)';

    private const JOB_FORWARD = self::P.'un trabajo solo avanza: planned → claimed → dispatching → un final, o se cancela o vence antes de salir (UPDATE en outreach_jobs)';

    private const JOB_FINAL = self::P.'un trabajo en un estado final no cambia de estado (solo recibe, una vez, la reclamación, la referencia del canal o la liberación de la reserva) (UPDATE en outreach_jobs)';

    private const JOB_FROZEN = self::P.'un trabajo fija al planificarse su persona, su paso, su intento, su canal, su plantilla, su reserva y su sello (UPDATE en outreach_jobs)';

    private const JOB_ONCE = self::P.'un trabajo fija una sola vez su reclamación, las referencias del canal y la liberación de su reserva (UPDATE en outreach_jobs)';

    private const JOB_NOT_BEFORE = self::P.'un trabajo solo se aplaza (not_before nunca adelanta) y solo mientras está planned (UPDATE en outreach_jobs)';

    private const JOB_SEAL = self::P.'un trabajo es de una inscripción abierta y lleva su persona, su modo y su sello (INSERT en outreach_jobs)';

    private const JOB_PUBLICATION = self::P.'un trabajo nombra una entrada de la cadena de su estrategia (INSERT en outreach_jobs)';

    private const JOB_TEMPLATE = self::P.'un trabajo usa una plantilla aprobada, de su huella exacta y de su canal (INSERT en outreach_jobs)';

    private const ENROLLMENT_FINALS = ['completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];

    private const JOB_FINALS = ['started', 'simulated', 'failed_to_start', 'ambiguous', 'canceled', 'expired'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── Pasadas y cursores ──────────────────────────────────────────────────

    public function test_una_pasada_nace_running_se_cierra_una_vez_y_fija_lo_que_es(): void
    {
        $this->assertGuarded(fn () => $this->insertRun(['status' => 'completed', 'finished_at' => now()]), self::RUN_STARTS, 'nace cerrada');
        $this->assertGuarded(fn () => $this->insertRun(['error_code' => 'boom']), self::RUN_STARTS, 'nace con error');
        foreach (['completed' => [], 'failed' => ['error_code' => 'source_unavailable'], 'aborted' => ['error_code' => 'outreach_brake_global']] as $final => $extra) {
            $id = $this->insertRun();
            $this->assertGuarded(fn () => $this->db()->table(self::T_RUNS)->where('id', $id)->update(['status' => 'queued']), self::RUN_FORWARD, 'running → queued');
            $this->assertAccepted(fn () => $this->db()->table(self::T_RUNS)->where('id', $id)->update(['status' => $final, 'finished_at' => '2026-11-12 15:05:00', 'counts' => '{"evaluated":12}'] + $extra), "running → {$final}");
            foreach ([['status' => 'running'], ['counts' => '{}'], ['finished_at' => now()], ['updated_at' => now()]] as $cambio) {
                $this->assertGuarded(fn () => $this->db()->table(self::T_RUNS)->where('id', $id)->update($cambio), self::RUN_FINAL, "{$final}: ".json_encode(array_keys($cambio)));
            }
        }
        $id = $this->insertRun();
        foreach (['id', 'uuid', 'kind', 'mode', 'strategy_id', 'trigger_key', 'started_at', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_RUNS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::RUN_FROZEN, $columna);
        }
        $this->assertGuarded(fn () => $this->db()->table(self::T_RUNS)->where('id', $id)->delete(), self::P.'las pasadas no se borran (DELETE en outreach_runs)');
    }

    public function test_un_cursor_solo_avanza_y_no_cambia_de_fuente(): void
    {
        $this->db()->table(self::T_CURSORS)->insert(['source' => 'commercial_events', 'last_id' => 10, 'created_at' => now()]);
        $mover = fn (int $a): int => $this->db()->table(self::T_CURSORS)->where('source', 'commercial_events')->update(['last_id' => $a, 'updated_at' => now()]);
        $this->assertGuarded(fn () => $mover(9), self::CURSOR_FORWARD, 'baja');
        $this->assertAccepted(fn () => $mover(10), 'el mismo');
        $this->assertAccepted(fn () => $mover(500), 'avanza');
        $this->assertGuarded(fn () => $this->db()->table(self::T_CURSORS)->where('source', 'commercial_events')->update(['source' => 'commercial_events_v2']), self::CURSOR_FROZEN, 'otra fuente');
        $this->assertGuarded(fn () => $this->db()->table(self::T_CURSORS)->where('source', 'commercial_events')->delete(), self::P.'los cursores solo avanzan (DELETE en outreach_cursors)');
        // Un cursor nuevo (p. ej., el de una versión restaurada) es una fila nueva, nunca bajar el que había.
        $this->assertAccepted(fn () => $this->db()->table(self::T_CURSORS)->insert(['source' => 'commercial_events:estrategia-7:v3', 'last_id' => 0, 'created_at' => now()]), 'un cursor con alcance');
        $this->assertSame(500, (int) $this->db()->table(self::T_CURSORS)->where('source', 'commercial_events')->value('last_id'));
    }

    // ── Inscripciones ───────────────────────────────────────────────────────

    public function test_una_inscripcion_nace_abierta_en_el_paso_cero_y_sin_resultado(): void
    {
        $live = $this->liveVersion();
        foreach ([['status' => 'completed', 'ended_at' => now(), 'exit_reason' => 'converted'], ['status' => 'paused'], ['step_index' => 1], ['touches' => 1],
            ['last_outcome' => 'blocked'], ['exit_reason' => 'converted'], ['redacted_at' => now()]] as $nace) {
            $this->assertGuarded(fn () => $this->insertEnrollment($live, $nace), self::ENR_STARTS, 'nace '.json_encode(array_keys($nace)));
        }
        $this->assertAccepted(fn () => $this->insertEnrollment($live, ['status' => 'waiting', 'next_due_at' => '2026-11-14 14:00:00']), 'nace esperando el retraso del primer paso');
    }

    /** @return array<string, array{string, string, bool}> */
    public static function enrollmentMoves(): array
    {
        $estados = ['active', 'waiting', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];
        $finales = ['completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];
        $grafo = ['active' => ['waiting', ...$finales], 'waiting' => ['active', ...$finales]];
        $casos = [];
        foreach ($estados as $desde) {
            foreach ([...$estados, 'paused'] as $hacia) {
                $casos["{$desde} → {$hacia}"] = [$desde, $hacia, in_array($hacia, $grafo[$desde] ?? [], true)];
            }
        }

        return $casos;
    }

    #[DataProvider('enrollmentMoves')]
    public function test_una_inscripcion_solo_avanza(string $desde, string $hacia, bool $admitida): void
    {
        $live = $this->liveVersion();
        $id = $this->insertEnrollment($live, ['status' => $desde === 'waiting' ? 'waiting' : 'active']);
        if (in_array($desde, self::ENROLLMENT_FINALS, true)) {
            $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['status' => $desde, 'ended_at' => now(), 'exit_reason' => 'context_changed']);
        }
        $cambio = ['status' => $hacia, 'status_changed_at' => now()]
            + (in_array($hacia, self::ENROLLMENT_FINALS, true) ? ['ended_at' => now(), 'exit_reason' => 'success_reached'] : []);
        $mover = fn (): int => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update($cambio);

        if ($admitida) {
            $this->assertAccepted($mover, "{$desde} → {$hacia}");

            return;
        }
        if (in_array($desde, self::ENROLLMENT_FINALS, true)) {
            $this->assertGuarded($mover, self::ENR_FINAL, "{$desde} es final");
        } elseif ($desde === $hacia) {
            $this->assertAccepted($mover, 'el mismo estado');
        } else {
            $this->assertGuarded($mover, self::ENR_FORWARD, "{$desde} → {$hacia}");
        }
        $this->assertSame($desde, $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->value('status'));
    }

    public function test_una_inscripcion_fija_su_persona_su_ciclo_y_su_sello_y_sus_contadores_solo_crecen(): void
    {
        $id = $this->insertEnrollment($this->liveVersion());
        foreach (['id', 'uuid', 'strategy_id', 'strategy_version_id', 'content_hash', 'publication_seq', 'part', 'studio_ref_kind', 'studio_version_uuid', 'studio_content_hash',
            'catalog_version', 'evaluator_version', 'mode', 'subject_key', 'subject_ref', 'member_id', 'lead_id', 'cycle_key', 'purpose', 'enrolled_at', 'window_ends_at',
            'redacted_at', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_ENROLLMENTS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::ENR_FROZEN, $columna);
        }
        $this->assertAccepted(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['step_index' => 1, 'touches' => 1, 'status' => 'waiting']), 'el siguiente paso');
        $this->assertGuarded(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['step_index' => 0]), self::ENR_COUNTERS, 'el paso baja');
        $this->assertGuarded(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['touches' => 0]), self::ENR_COUNTERS, 'los contactos bajan');
        $this->assertAccepted(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['touches' => 1, 'next_due_at' => now()]), 'retry_step: el mismo paso');
        $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['status' => 'exited', 'ended_at' => now(), 'exit_reason' => 'not_interested']);
        $this->assertGuarded(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['touches' => 0]), self::ENR_FINAL, 'cerrada: los contactos bajan');
        $this->assertGuarded(fn () => $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->delete(), self::P.'las inscripciones no se borran (DELETE en outreach_enrollments)');
    }

    public function test_una_ocurrencia_una_inscripcion_y_una_abierta_por_estrategia_persona_y_modo(): void
    {
        $live = $this->liveVersion();
        $id = $this->insertEnrollment($live, ['subject_key' => 'm:41', 'member_id' => 41, 'subject_ref' => $this->hex('m:41')]);
        $misma = fn (array $o = []): int => $this->insertEnrollment($live, $o + ['subject_key' => 'm:41', 'member_id' => 41, 'subject_ref' => $this->hex('m:41')]);
        // La misma ocurrencia entregada dos veces (prueba 14): el UNIQUE de la ocurrencia, aunque la primera ya esté cerrada.
        $this->db()->table(self::T_ENROLLMENTS)->where('id', $id)->update(['status' => 'expired', 'ended_at' => now(), 'exit_reason' => 'no_response']);
        $this->assertUniqueRejects(fn () => $misma(), 'oenr_occurrence_unique', self::T_ENROLLMENTS, ['strategy_id', 'subject_key', 'cycle_key', 'mode'], 'la misma ocurrencia');
        // Sombra y live no chocan (el modo es parte de la clave).
        $this->assertAccepted(fn () => $misma(['mode' => 'live']), 'la misma ocurrencia en live');
        // Otro ciclo, abierta (esperando su primer paso): una sola abierta, active o waiting, por estrategia, persona y modo.
        $this->assertAccepted(fn () => $misma(['cycle_key' => 'exp:2026-12-20', 'status' => 'waiting', 'next_due_at' => '2026-12-14 14:00:00']), 'otro ciclo con la anterior cerrada');
        $this->assertUniqueRejects(fn () => $misma(['cycle_key' => 'exp:2027-01-20']), 'oenr_one_open', self::T_ENROLLMENTS, ['strategy_id', 'subject_key', 'mode'], 'dos abiertas');
        // La misma persona en OTRA estrategia sí (D9-8: varias estrategias por persona; la prioridad la arbitra el servicio).
        $this->assertAccepted(fn () => $this->insertEnrollment($this->liveVersion(), ['subject_key' => 'm:41', 'member_id' => 41, 'subject_ref' => $this->hex('m:41')]), 'otra estrategia');
    }

    public function test_una_inscripcion_lleva_el_sello_de_una_version_viva_de_su_estrategia(): void
    {
        $live = $this->liveVersion();
        $otra = $this->liveVersion();
        foreach ([
            'otra huella' => ['content_hash' => $this->hex('otra')],
            'otro catálogo' => ['catalog_version' => 'outreach-catalog/2026-11-11.1'],
            'otro evaluador' => ['evaluator_version' => 'outreach-evaluator/2'],
            'otra referencia comercial' => ['studio_content_hash' => $this->hex('otra-linea-base')],
            'la versión de otra estrategia' => ['strategy_version_id' => $otra['version']],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => $this->insertEnrollment($live, $cambio), self::ENR_SEAL, $que);
        }
        $this->assertGuarded(fn () => $this->insertEnrollment($live, ['purpose' => 'collections']), self::ENR_PURPOSE, 'otra finalidad');
        $this->assertGuarded(fn () => $this->insertEnrollment($live, ['publication_seq' => $otra['seq']]), self::ENR_PUBLICATION, 'la entrada de otra estrategia');

        // Una versión que ya no está viva no inscribe (revertida), ni una aprobada sin publicar.
        $this->setVersionStatus($live['version'], 'ROLLED_BACK');
        $this->assertGuarded(fn () => $this->insertEnrollment($live), self::ENR_SEAL, 'revertida');
        $aprobada = $this->insertVersion($otra['strategy']);
        $this->approveVersion($aprobada);
        $this->assertGuarded(fn () => $this->insertEnrollment($otra, ['strategy_version_id' => $aprobada,
            'content_hash' => (string) $this->db()->table(self::T_VERSIONS)->where('id', $aprobada)->value('content_hash')]), self::ENR_SEAL, 'aprobada sin publicar');
        $this->assertAccepted(fn () => $this->insertEnrollment($otra), 'la viva de la otra estrategia');
    }

    // ── Trabajos ────────────────────────────────────────────────────────────

    public function test_un_trabajo_nace_planned_sin_reclamar_ni_salir(): void
    {
        $e = $this->insertEnrollment($this->liveVersion());
        foreach ([['status' => 'claimed', 'claimed_at' => now()], ['status' => 'started'], ['claimed_at' => now()], ['cancel_reason' => 'strategy_moved'],
            ['budget_released_at' => now()], ['marketing_message_id' => 7], ['voice_call_id' => 7], ['assisted_claimed_at' => now()], ['redacted_at' => now()]] as $nace) {
            $this->assertGuarded(fn () => $this->insertJob($e, $nace), self::JOB_STARTS, 'nace '.json_encode(array_keys($nace)));
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function jobMoves(): array
    {
        $estados = ['planned', 'claimed', 'dispatching', 'started', 'simulated', 'failed_to_start', 'ambiguous', 'canceled', 'expired'];
        $grafo = [
            'planned' => ['claimed', 'canceled', 'expired'],
            'claimed' => ['dispatching', 'canceled', 'expired', 'ambiguous'],
            'dispatching' => ['started', 'simulated', 'failed_to_start', 'ambiguous'],
        ];
        $casos = [];
        foreach ($estados as $desde) {
            foreach ([...$estados, 'queued'] as $hacia) {
                $casos["{$desde} → {$hacia}"] = [$desde, $hacia, in_array($hacia, $grafo[$desde] ?? [], true)];
            }
        }

        return $casos;
    }

    #[DataProvider('jobMoves')]
    public function test_un_trabajo_solo_avanza(string $desde, string $hacia, bool $admitida): void
    {
        // En sombra (canal simulado) solo se llega a `simulated`; los que salen, en live por el asistido.
        $sombra = $desde === 'simulated' || ($hacia === 'simulated' && ! in_array($desde, ['started', 'failed_to_start', 'ambiguous'], true));
        $live = $this->liveVersion();
        $id = $sombra ? $this->insertJob($this->insertEnrollment($live)) : $this->insertAssistedJob($this->insertEnrollment($live, ['mode' => 'live']));
        $camino = match ($desde) {
            'planned' => [],
            'claimed' => ['claimed'],
            'dispatching' => ['claimed', 'dispatching'],
            'canceled' => ['canceled'],
            'expired' => ['expired'],
            default => ['claimed', 'dispatching', $desde],
        };
        foreach ($camino as $paso) {
            $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => $paso]
                + ($paso === 'claimed' ? ['claimed_at' => now()] : []) + ($paso === 'canceled' ? ['cancel_reason' => 'strategy_moved'] : []));
        }
        $cambio = ['status' => $hacia, 'status_changed_at' => now()]
            + ($hacia === 'claimed' && $desde === 'planned' ? ['claimed_at' => now()] : [])
            + ($hacia === 'canceled' ? ['cancel_reason' => 'success_reached'] : []);
        $mover = fn (): int => $this->db()->table(self::T_JOBS)->where('id', $id)->update($cambio);

        if ($admitida) {
            $this->assertAccepted($mover, "{$desde} → {$hacia}");

            return;
        }
        if (in_array($desde, self::JOB_FINALS, true)) {
            $this->assertGuarded($mover, self::JOB_FINAL, "{$desde} es final");
        } elseif ($desde === $hacia) {
            $this->assertAccepted($mover, 'el mismo estado');
        } else {
            $this->assertGuarded($mover, self::JOB_FORWARD, "{$desde} → {$hacia}");
        }
        $this->assertSame($desde, $this->db()->table(self::T_JOBS)->where('id', $id)->value('status'));
    }

    public function test_un_trabajo_fija_su_planificacion_y_recibe_una_vez_lo_que_llega_despues(): void
    {
        $live = $this->liveVersion();
        $id = $this->insertAssistedJob($this->insertEnrollment($live, ['mode' => 'live']));
        foreach (['id', 'uuid', 'enrollment_id', 'strategy_id', 'strategy_version_id', 'content_hash', 'publication_seq', 'part', 'studio_ref_kind', 'studio_version_uuid',
            'studio_content_hash', 'catalog_version', 'evaluator_version', 'mode', 'subject_key', 'subject_ref', 'phone_ref', 'step_index', 'attempt', 'channel', 'medium',
            'template_version_id', 'template_hash', 'idempotency_key', 'expires_at', 'budget_day', 'reserved_units', 'reserved_micros', 'redacted_at', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_JOBS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::JOB_FROZEN, $columna);
        }

        // Aplazar sí, adelantar no; y solo mientras está planned.
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['not_before' => '2026-11-13 13:00:00']), self::JOB_NOT_BEFORE, 'adelantar');
        $this->assertAccepted(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['not_before' => '2026-11-13 15:00:00']), 'aplazar');
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => 'claimed', 'claimed_at' => '2026-11-13 15:00:01']);
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['not_before' => '2026-11-13 16:00:00']), self::JOB_NOT_BEFORE, 'aplazar uno reclamado');
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['claimed_at' => '2026-11-13 15:00:02']), self::JOB_ONCE, 'reclamarlo otra vez');

        // El asistido sale y lo reclama una persona, una vez.
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => 'dispatching']);
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => 'started']);
        $reclama = fn (int $quien): int => $this->db()->table(self::T_JOBS)->where('id', $id)->update([
            'assisted_claimed_by_admin_id' => $quien, 'assisted_claimed_by_name' => self::NAMES[$quien], 'assisted_claimed_at' => '2026-11-13 15:10:00']);
        $this->assertAccepted(fn () => $reclama(3), 'Caro lo reclama');
        $this->assertGuarded(fn () => $reclama(2), self::JOB_ONCE, 'Beto lo reclama después');
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['not_before' => '2026-11-14 15:00:00']), self::JOB_FINAL, 'salido: aplazar');

        // Cancelado antes de salir: la reserva se libera una vez.
        $otro = $this->insertAssistedJob($this->insertEnrollment($live, ['mode' => 'live']));
        $this->db()->table(self::T_JOBS)->where('id', $otro)->update(['status' => 'canceled', 'cancel_reason' => 'context_changed']);
        $this->assertAccepted(fn () => $this->db()->table(self::T_JOBS)->where('id', $otro)->update(['budget_released_at' => '2026-11-13 15:20:00']), 'liberar la reserva');
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $otro)->update(['budget_released_at' => '2026-11-13 15:21:00']), self::JOB_ONCE, 'liberarla dos veces');
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $otro)->update(['cancel_reason' => 'strategy_moved']), self::JOB_FINAL, 'cambiar el motivo');

        // La referencia del canal (W9), una vez.
        $plantilla = $this->approvedTemplate();
        $whatsapp = $this->insertJob($this->insertEnrollment($live, ['mode' => 'live']), ['channel' => 'whatsapp_template', 'template_version_id' => $plantilla,
            'template_hash' => (string) $this->db()->table(self::T_TEMPLATES)->where('id', $plantilla)->value('content_hash'), 'reserved_units' => 1, 'reserved_micros' => 15000]);
        $this->walkJob($whatsapp, 'started', ['marketing_message_id' => 501]);
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $whatsapp)->update(['marketing_message_id' => 502]), self::JOB_ONCE, 'otra referencia');
        $this->assertGuarded(fn () => $this->db()->table(self::T_JOBS)->where('id', $whatsapp)->delete(), self::P.'los trabajos no se borran (DELETE en outreach_jobs)');
        if ($this->isPostgres()) {
            $this->assertRejected(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['budget_released_at' => now()]), '23514', 'ojob_release_chk');
            $this->assertRejected(fn () => $this->db()->table(self::T_JOBS)->where('id', $id)->update(['marketing_message_id' => 9]), '23514', 'ojob_refs_chk');
        }
    }

    public function test_un_trabajo_es_de_una_inscripcion_abierta_con_su_sello_su_entrada_y_su_plantilla(): void
    {
        $live = $this->liveVersion();
        $otra = $this->liveVersion();
        $e = $this->insertEnrollment($live, ['mode' => 'live']);
        $asistida = $this->approvedTemplate(['channel' => 'assisted']);
        $huella = fn (int $t): string => (string) $this->db()->table(self::T_TEMPLATES)->where('id', $t)->value('content_hash');
        $asistido = ['channel' => 'assisted', 'medium' => 'voice', 'template_version_id' => $asistida, 'template_hash' => $huella($asistida), 'reserved_units' => 1];
        foreach ([
            'otra persona' => ['subject_key' => 'm:999999'],
            'otro seudónimo' => ['subject_ref' => $this->hex('otro')],
            'otro modo' => ['mode' => 'shadow', 'reserved_units' => 0],
            'otra huella' => ['content_hash' => $this->hex('otra')],
            'otro evaluador' => ['evaluator_version' => 'outreach-evaluator/9'],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => $this->insertJob($e, $cambio + $asistido), self::JOB_SEAL, $que);
        }
        $this->assertGuarded(fn () => $this->insertJob($e, ['publication_seq' => $otra['seq']] + $asistido), self::JOB_PUBLICATION, 'la entrada de otra estrategia');

        $borrador = $this->insertTemplate(['channel' => 'assisted']);
        $whatsapp = $this->approvedTemplate();
        foreach ([
            'una plantilla sin aprobar' => ['template_version_id' => $borrador, 'template_hash' => $huella($borrador)],
            'otra huella de la plantilla' => ['template_hash' => $this->hex('plantilla-falsa')],
            'una de WhatsApp por el asistido' => ['template_version_id' => $whatsapp, 'template_hash' => $huella($whatsapp)],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => $this->insertJob($e, $cambio + $asistido), self::JOB_TEMPLATE, $que);
        }
        $this->assertAccepted(fn () => $this->insertJob($e, $asistido), 'el asistido bueno');

        // Una inscripción cerrada no planifica.
        $cerrada = $this->insertEnrollment($live, ['mode' => 'live']);
        $this->db()->table(self::T_ENROLLMENTS)->where('id', $cerrada)->update(['status' => 'opted_out', 'ended_at' => now(), 'exit_reason' => 'opted_out']);
        $this->assertGuarded(fn () => $this->insertJob($cerrada, $asistido), self::JOB_SEAL, 'de una inscripción cerrada');
    }

    public function test_un_trabajo_vivo_por_persona_y_modo_y_cada_intento_una_fila(): void
    {
        $persona = ['subject_key' => 'm:77', 'member_id' => 77, 'subject_ref' => $this->hex('m:77')];
        $a = $this->insertEnrollment($this->liveVersion(), $persona);
        $b = $this->insertEnrollment($this->liveVersion(), $persona);
        $primero = $this->insertJob($a);
        // Dos estrategias sobre la misma persona (prueba 5): un solo trabajo vivo.
        $this->assertUniqueRejects(fn () => $this->insertJob($b), 'ojob_one_live', self::T_JOBS, ['subject_key', 'mode'], 'otro vivo de la misma persona');
        foreach (['claimed', 'dispatching'] as $estado) {
            $this->db()->table(self::T_JOBS)->where('id', $primero)->update(['status' => $estado] + ($estado === 'claimed' ? ['claimed_at' => now()] : []));
            $this->assertUniqueRejects(fn () => $this->insertJob($b), 'ojob_one_live', self::T_JOBS, ['subject_key', 'mode'], "con el primero {$estado}");
        }
        $this->db()->table(self::T_JOBS)->where('id', $primero)->update(['status' => 'simulated']);
        $this->assertAccepted(fn () => $this->insertJob($b), 'el primero ya salió (simulado)');

        // Cada intento es una fila; el mismo intento o la misma clave, no.
        $c = $this->insertEnrollment($this->liveVersion());
        $uno = $this->insertJob($c, ['idempotency_key' => $this->hex('clave-1')]);
        $this->db()->table(self::T_JOBS)->where('id', $uno)->update(['status' => 'canceled', 'cancel_reason' => 'outreach_brake_global']);
        $this->assertUniqueRejects(fn () => $this->insertJob($c), 'ojob_attempt_unique', self::T_JOBS, ['enrollment_id', 'step_index', 'attempt'], 'el mismo intento');
        $this->assertUniqueRejects(fn () => $this->insertJob($c, ['attempt' => 2, 'idempotency_key' => $this->hex('clave-1')]), 'ojob_idempotency_unique', self::T_JOBS, ['idempotency_key'], 'la misma clave');
        $this->assertAccepted(fn () => $this->insertJob($c, ['attempt' => 2]), 'el intento 2 (retry_step)');
        // Sombra y live no chocan.
        $d = $this->insertEnrollment($this->liveVersion(), ['mode' => 'live'] + $persona);
        $this->assertAccepted(fn () => $this->insertAssistedJob($d), 'la misma persona en live');
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badRows(): array
    {
        $plantilla = ['template_version_id' => 1, 'template_hash' => hash('sha256', 'p')];

        return [
            // Pasadas (la base: un barrido en sombra, running).
            'pasada: tipo desconocido' => [self::T_RUNS, ['kind' => 'cleanup'], ['orun_kind_chk']],
            'pasada: modo off' => [self::T_RUNS, ['mode' => 'off'], ['orun_mode_chk']],
            'pasada: estado desconocido' => [self::T_RUNS, ['status' => 'paused'], ['orun_closed_chk', 'orun_error_chk', 'orun_status_chk']],
            'pasada: completada sin fin' => [self::T_RUNS, ['status' => 'completed'], ['orun_closed_chk']],
            'pasada: abierta con fin' => [self::T_RUNS, ['finished_at' => '2026-11-12 16:00:00'], ['orun_closed_chk']],
            'pasada: termina antes de empezar' => [self::T_RUNS, ['status' => 'completed', 'finished_at' => '2026-11-12 14:00:00'], ['orun_closed_chk']],
            'pasada: falla sin código' => [self::T_RUNS, ['status' => 'failed', 'finished_at' => '2026-11-12 16:00:00'], ['orun_error_chk']],
            'pasada: completada con código' => [self::T_RUNS, ['status' => 'completed', 'finished_at' => '2026-11-12 16:00:00', 'error_code' => 'x'], ['orun_error_chk']],
            'pasada: código en texto libre' => [self::T_RUNS, ['status' => 'failed', 'finished_at' => '2026-11-12 16:00:00', 'error_code' => 'Se cayó'], ['orun_error_chk']],
            'pasada: recuentos que no son objeto' => [self::T_RUNS, ['counts' => '[1,2]'], ['orun_counts_chk']],
            'pasada: disparador con mayúsculas' => [self::T_RUNS, ['trigger_key' => 'Membership.Expiring'], ['orun_trigger_key_chk']],
            // Cursores.
            'cursor: fuente con mayúsculas' => [self::T_CURSORS, ['source' => 'CommercialEvents'], ['ocur_source_chk']],
            'cursor: negativo' => [self::T_CURSORS, ['last_id' => -1], ['ocur_last_id_chk']],
            // Inscripciones (la base: un socio en sombra, active).
            'inscripción: clave suprimida sin suprimir' => [self::T_ENROLLMENTS, ['subject_key' => 'x:1'], ['oenr_subject_chk']],
            'inscripción: clave de otro socio' => [self::T_ENROLLMENTS, ['subject_key' => 'm:123456789'], ['oenr_subject_chk']],
            'inscripción: lead con socio' => [self::T_ENROLLMENTS, ['subject_key' => 'l:5', 'lead_id' => 5], ['oenr_subject_chk']],
            'inscripción: seudónimo en mayúsculas' => [self::T_ENROLLMENTS, ['subject_ref' => strtoupper(hash('sha256', 'r'))], ['oenr_subject_chk']],
            'inscripción: suprimida con la persona' => [self::T_ENROLLMENTS, ['redacted_at' => '2028-05-12 00:00:00'], ['oenr_subject_chk']],
            'inscripción: ciclo con espacios' => [self::T_ENROLLMENTS, ['cycle_key' => 'vence el 20'], ['oenr_cycle_chk']],
            'inscripción: ventana de 61 días' => [self::T_ENROLLMENTS, ['window_ends_at' => '2027-01-12 15:00:00'], ['oenr_window_chk']],
            'inscripción: ventana al revés' => [self::T_ENROLLMENTS, ['window_ends_at' => '2026-11-12 14:00:00'], ['oenr_window_chk']],
            'inscripción: paso 6' => [self::T_ENROLLMENTS, ['step_index' => 6], ['oenr_counters_chk']],
            'inscripción: 7 contactos' => [self::T_ENROLLMENTS, ['touches' => 7], ['oenr_counters_chk']],
            'inscripción: cerrada sin fin' => [self::T_ENROLLMENTS, ['status' => 'completed'], ['oenr_closed_chk']],
            'inscripción: abierta con motivo' => [self::T_ENROLLMENTS, ['exit_reason' => 'converted'], ['oenr_closed_chk']],
            'inscripción: motivo en texto libre' => [self::T_ENROLLMENTS, ['status' => 'exited', 'ended_at' => '2026-11-13 00:00:00', 'exit_reason' => 'No quiso'], ['oenr_closed_chk']],
            'inscripción: resultado fuera del conjunto (provider_error)' => [self::T_ENROLLMENTS, ['last_outcome' => 'provider_error'], ['oenr_last_outcome_chk']],
            'inscripción: estado desconocido' => [self::T_ENROLLMENTS, ['status' => 'paused'], ['oenr_closed_chk', 'oenr_status_chk']],
            'inscripción: modo off' => [self::T_ENROLLMENTS, ['mode' => 'off'], ['oenr_mode_chk']],
            'inscripción: parte desconocida' => [self::T_ENROLLMENTS, ['part' => 'all'], ['oenr_part_chk']],
            'inscripción: finalidad desconocida' => [self::T_ENROLLMENTS, ['purpose' => 'marketing'], ['oenr_purpose_chk']],
            'inscripción: catálogo ajeno' => [self::T_ENROLLMENTS, ['catalog_version' => '2026-10-19.1'], ['oenr_seal_chk']],
            'inscripción: entrada 0' => [self::T_ENROLLMENTS, ['publication_seq' => 0], ['oenr_seal_chk']],
            'inscripción: referencia de versión sin uuid' => [self::T_ENROLLMENTS, ['studio_ref_kind' => 'version'], ['oenr_seal_chk']],
            // Trabajos (la base: uno simulado en sombra, planned).
            'trabajo: intento 0' => [self::T_JOBS, ['attempt' => 0], ['ojob_attempt_chk']],
            'trabajo: intento 4' => [self::T_JOBS, ['attempt' => 4], ['ojob_attempt_chk']],
            'trabajo: paso 6' => [self::T_JOBS, ['step_index' => 6], ['ojob_attempt_chk']],
            'trabajo: simulado con medio' => [self::T_JOBS, ['medium' => 'voice'], ['ojob_medium_chk']],
            'trabajo: asistido sin medio ni plantilla' => [self::T_JOBS, ['channel' => 'assisted'], ['ojob_medium_chk', 'ojob_template_chk']],
            'trabajo: huella de plantilla sin plantilla' => [self::T_JOBS, ['template_hash' => hash('sha256', 'p')], ['ojob_template_chk']],
            'trabajo: vence antes de poder salir' => [self::T_JOBS, ['expires_at' => '2026-11-13 13:00:00'], ['ojob_window_chk']],
            'trabajo: sombra con reserva' => [self::T_JOBS, ['reserved_units' => 1], ['ojob_budget_chk']],
            'trabajo: reserva negativa' => [self::T_JOBS, ['mode' => 'live', 'channel' => 'assisted', 'medium' => 'voice', 'reserved_units' => -1] + $plantilla, ['ojob_budget_chk']],
            'trabajo: cancelado sin motivo' => [self::T_JOBS, ['status' => 'canceled'], ['ojob_cancel_chk']],
            'trabajo: planned con motivo' => [self::T_JOBS, ['cancel_reason' => 'context_changed'], ['ojob_cancel_chk']],
            'trabajo: reclamado sin cuándo' => [self::T_JOBS, ['status' => 'claimed'], ['ojob_claimed_chk']],
            'trabajo: planned reclamado' => [self::T_JOBS, ['claimed_at' => '2026-11-13 14:00:00'], ['ojob_claimed_chk']],
            'trabajo: referencia de WhatsApp en el simulado' => [self::T_JOBS, ['status' => 'simulated', 'claimed_at' => '2026-11-13 14:00:00', 'marketing_message_id' => 9], ['ojob_refs_chk']],
            'trabajo: reclamación a medias' => [self::T_JOBS, ['mode' => 'live', 'channel' => 'assisted', 'medium' => 'voice', 'status' => 'started', 'claimed_at' => '2026-11-13 14:00:00',
                'assisted_claimed_by_admin_id' => 3] + $plantilla, ['ojob_refs_chk']],
            'trabajo: liberar la de uno planned' => [self::T_JOBS, ['budget_released_at' => '2026-11-13 14:00:00'], ['ojob_release_chk']],
            'trabajo: en sombra sale' => [self::T_JOBS, ['status' => 'started', 'claimed_at' => '2026-11-13 14:00:00'], ['ojob_shadow_chk']],
            'trabajo: en live, simulado por el asistido' => [self::T_JOBS, ['mode' => 'live', 'channel' => 'assisted', 'medium' => 'voice', 'status' => 'simulated',
                'claimed_at' => '2026-11-13 14:00:00'] + $plantilla, ['ojob_shadow_chk']],
            'trabajo: clave corta' => [self::T_JOBS, ['idempotency_key' => 'abc'], ['ojob_idempotency_chk']],
            'trabajo: seudónimo corto' => [self::T_JOBS, ['subject_ref' => 'abc'], ['ojob_subject_chk']],
            'trabajo: teléfono en claro' => [self::T_JOBS, ['phone_ref' => '573001234567'], ['ojob_phone_chk']],
            'trabajo: suprimido con su teléfono' => [self::T_JOBS, ['redacted_at' => '2028-05-12 00:00:00', 'subject_key' => 'x:999999', 'subject_ref' => str_repeat('0', 64),
                'phone_ref' => hash('sha256', 't')], ['ojob_phone_chk']],
            'trabajo: modo off' => [self::T_JOBS, ['mode' => 'off'], ['ojob_mode_chk']],
            'trabajo: canal desconocido' => [self::T_JOBS, ['channel' => 'sms'], ['ojob_channel_chk', 'ojob_template_chk']],
            'trabajo: estado desconocido' => [self::T_JOBS, ['status' => 'queued'], ['ojob_claimed_chk', 'ojob_status_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badRows')]
    public function test_solo_en_postgres_cada_check_de_forma(string $tabla, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL (en SQLite los vigila el servicio).');

        $this->assertCheckShape($tabla, $this->validRow($tabla), $cambio, $esperados);
    }

    public function test_solo_en_postgres_la_forma_suprimida_de_la_supresion_1581_cabe(): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL.');

        // La sonda usa el id 999999: la forma suprimida es 'x:<id>', 64 ceros y, en la inscripción, sin socio ni lead.
        $suprimida = ['redacted_at' => '2028-05-12 00:00:00', 'subject_key' => 'x:999999', 'subject_ref' => str_repeat('0', 64)];
        $this->assertSame([], $this->violatedChecks(self::T_ENROLLMENTS, array_merge($this->validRow(self::T_ENROLLMENTS), $suprimida, ['member_id' => null])));
        $this->assertSame(['oenr_subject_chk'], $this->violatedChecks(self::T_ENROLLMENTS, array_merge($this->validRow(self::T_ENROLLMENTS), $suprimida)), 'sin quitar el socio');
        $this->assertSame([], $this->violatedChecks(self::T_JOBS, array_merge($this->validRow(self::T_JOBS), $suprimida)));
        $this->assertSame(['ojob_subject_chk'], $this->violatedChecks(self::T_JOBS, array_merge($this->validRow(self::T_JOBS), $suprimida, ['subject_key' => 'x:5'])), 'otra fila');
        // Un lead sin socio es una persona válida.
        $this->assertSame([], $this->violatedChecks(self::T_ENROLLMENTS, array_merge($this->validRow(self::T_ENROLLMENTS), ['subject_key' => 'l:5', 'lead_id' => 5, 'member_id' => null])));
    }

    /** @return array<string, mixed> la fila válida de cada tabla, sin insertarla */
    private function validRow(string $tabla): array
    {
        DB::beginTransaction();
        try {
            if ($tabla === self::T_CURSORS) {
                DB::table(self::T_CURSORS)->insert(['source' => 'commercial_events', 'last_id' => 0, 'created_at' => now()]);
                $fila = (array) DB::table(self::T_CURSORS)->where('source', 'commercial_events')->first();

                return $fila;
            }
            $id = match ($tabla) {
                self::T_RUNS => $this->insertRun(),
                self::T_ENROLLMENTS => $this->insertEnrollment($this->liveVersion()),
                self::T_JOBS => $this->insertJob($this->insertEnrollment($this->liveVersion())),
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
