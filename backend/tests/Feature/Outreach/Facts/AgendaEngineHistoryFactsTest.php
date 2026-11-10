<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\ClassFacts;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Facts\ValuationFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;
use Tests\TestCase;

/**
 * B9-facts · AGENDA (clases y valoraciones), MOTOR COMERCIAL (objetivo,
 * eventos y transiciones) e HISTORIAL DE CONTACTOS (contrato H9, D9-3, D9-4,
 * D9-7; prueba 7 en su parte de hechos). Una fuente apagada es «sin datos»
 * (null), no una lista vacía; las respuestas no son contactos. Las filas del
 * H9 son reales, del esquema de DB9 (`OutreachSchemaFixtures`).
 */
class AgendaEngineHistoryFactsTest extends TestCase
{
    use FactsWorld;
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    /** Lunes 09-11-2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-09 15:00:00';

    public function test_reserva_en_los_proximos_7_dias_y_tipo_de_clase_de_los_ultimos_60(): void
    {
        $spinning = $this->klass(['type' => 'Spinning']);
        $cross = $this->klass(['type' => 'Cross Training', 'day_of_week' => 'Jueves']);
        $inactiva = $this->klass(['status' => 'inactive']);
        $hoy = $this->socio();
        $mas7 = $this->socio();
        $mas8 = $this->socio();
        $heredada = $this->socio();
        $deInactiva = $this->socio();
        $this->reservation($spinning, $hoy['member'], '2026-11-09');
        $this->reservation($spinning, $mas7['member'], '2026-11-16');
        $this->reservation($spinning, $mas8['member'], '2026-11-17');
        $this->reservation($spinning, $mas8['member'], '2026-11-08'); // ayer: no es próxima
        $this->reservation($spinning, $heredada['member'], null);     // sin fecha: ocupa todas las ocurrencias
        $this->reservation($inactiva, $deInactiva['member'], '2026-11-10');
        // Asistencia: 60 días justos (10-09-2026) cuenta; 61, no; un «ausente» no es haber ido.
        $this->classAttendance($cross, $hoy['member'], '2026-09-10');
        $this->classAttendance($spinning, $hoy['member'], '2026-09-09');
        $this->classAttendance($spinning, $mas7['member'], '2026-11-02', 'absent');
        $this->classAttendance($spinning, $mas8['member'], '2026-11-05', 'late');

        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$hoy, $mas7, $mas8, $heredada, $deInactiva]);
        $foto = $this->snap([...$claves, SubjectKey::lead($this->lead())], self::NOW, self::only(ClassFacts::FACTS));
        $this->assertSame([true, true, false, true, false], array_map(fn (string $k) => $foto->subject($k)->fact('class.reserved_next_7d'), $claves));
        $this->assertSame('cross-training', $foto->subject($claves[0])->fact('class.attended_type_last_60d'));
        $this->assertNull($foto->subject($claves[1])->fact('class.attended_type_last_60d'));
        $this->assertSame('spinning', $foto->subject($claves[2])->fact('class.attended_type_last_60d'));
        $this->assertNull(array_values($foto->subjects)[5]->fact('class.reserved_next_7d'));
    }

    public function test_ocurrencias_con_cupo_cuentan_las_reservas_heredadas_y_saltan_las_iniciadas(): void
    {
        $llena = $this->klass(['type' => 'Spinning', 'day_of_week' => 'Miércoles', 'max_capacity' => 2]);
        $conCupo = $this->klass(['type' => 'Yoga', 'day_of_week' => 'Jueves', 'max_capacity' => 3]);
        $iniciada = $this->klass(['type' => 'Boxeo', 'day_of_week' => 'Lunes', 'max_capacity' => 5]);
        $this->klass(['type' => 'Pilates', 'day_of_week' => 'Martes', 'status' => 'inactive']);
        $unica = $this->klass(['type' => 'Funcional', 'is_recurring' => false, 'day_of_week' => 'Viernes', 'date_time' => '2026-11-13 10:00:00', 'max_capacity' => 1]);
        $a = $this->socio();
        $b = $this->socio();
        $this->reservation($llena, $a['member'], '2026-11-11');
        $this->reservation($llena, $b['member'], null);
        $this->reservation($conCupo, $a['member'], '2026-11-12');
        DB::table('class_sessions')->insert(['class_id' => $iniciada, 'session_date' => '2026-11-09', 'started_at' => self::utc('2026-11-09 13:00:00'),
            'created_at' => self::utc('2026-11-09 13:00:00'), 'updated_at' => self::utc('2026-11-09 13:00:00')]);

        $o = (new ClassFacts)->upcomingWithCapacity(self::at(self::NOW), 7);
        $this->assertSame(
            [['yoga', '2026-11-12', 2], ['funcional', '2026-11-13', 1], ['boxeo', '2026-11-16', 5]],
            array_map(fn (array $x) => [$x['class_type'], $x['session_date'], $x['available']], $o),
        );
        $this->assertNull($o[0]['class_session_id']);

        // A quién se podría invitar: socios activos que no la tienen reservada (ni heredada).
        $c = $this->socio();
        $r = (new ClassFacts)->candidatesForOccurrence(self::at(self::NOW), $conCupo, '2026-11-12', null);
        $quienes = array_map(fn ($x) => $x->subjectKey, $r['candidates']);
        $this->assertNotContains(SubjectKey::member($a['member']), $quienes);
        $this->assertContains(SubjectKey::member($b['member']), $quienes);
        $this->assertContains(SubjectKey::member($c['member']), $quienes);
        // L9-27 (Q-2): la clave de ciclo es `cls:{class_id}:{session_date}`; las piezas van siempre, haya o no
        // fila de `class_sessions` (que solo nace al iniciar la clase).
        $this->assertSame(['class_id' => $conCupo, 'class_session_id' => null, 'session_date' => '2026-11-12'], $r['candidates'][0]->toArray()['cycleParts']);
    }

    public function test_valoracion_vigente_caducada_y_proxima(): void
    {
        $t = $this->trainer();
        $reciente = $this->socio();
        $this->valuation($reciente['member'], $t, 'completed', '2026-10-10 15:00:00', '2026-10-10 16:00:00');
        $this->physicalEvaluation($reciente['member'], '2026-10-30 15:00:00');
        $this->valuation($reciente['member'], $t, 'scheduled', '2026-11-10 15:00:00');
        $this->valuation($reciente['member'], $t, 'cancelled', '2026-11-09 20:00:00');
        $enCurso = $this->socio();
        $this->valuation($enCurso['member'], $t, 'in_progress', '2026-11-09 14:30:00');
        $nunca = $this->socio();

        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$reciente, $enCurso, $nunca]);
        $foto = $this->snap($claves, self::NOW, self::only(ValuationFacts::FACTS));
        $r = $foto->subject($claves[0]);
        $this->assertSame(10, $r->fact('valuation.days_since_last'));
        $this->assertTrue($r->fact('valuation.has_upcoming'));
        $this->assertSame(1, $r->fact('valuation.next_date'));
        $this->assertSame(0, $foto->subject($claves[1])->fact('valuation.next_date'));
        $this->assertNull($foto->subject($claves[2])->fact('valuation.days_since_last'));
        $this->assertFalse($foto->subject($claves[2])->fact('valuation.has_upcoming'));
        $this->assertNull($foto->subject($claves[2])->fact('valuation.next_date'));
    }

    public function test_prefiltro_de_valoracion_caducada(): void
    {
        $t = $this->trainer();
        $justo = $this->socio();
        $this->valuation($justo['member'], $t, 'completed', '2026-10-10 15:00:00');   // 30 días
        $casi = $this->socio();
        $this->physicalEvaluation($casi['member'], '2026-10-11 05:00:00');             // 29 días (00:00 del 11 en Bogotá)
        $conCita = $this->socio();
        $this->valuation($conCita['member'], $t, 'completed', '2026-09-01 15:00:00');
        $this->valuation($conCita['member'], $t, 'confirmed', '2026-11-20 15:00:00');
        $this->socio(); // nunca: desconocido, no entra (DF-K3)

        $r = (new ValuationFacts)->outdated(self::at(self::NOW), 30);
        $this->assertSame([SubjectKey::member($justo['member'])], array_map(fn ($c) => $c->subjectKey, $r['candidates']));
        $id = (int) DB::table('valuation_appointments')->where('member_id', $justo['member'])->value('id');
        $this->assertSame('val:'.$id, OutreachCatalog::cycleKey('valuation.outdated', $r['candidates'][0]->cycleParts, self::at(self::NOW)));
    }

    public function test_objetivo_abierto_del_motor_por_prioridad_y_nulo_sin_objetivo(): void
    {
        $socio = $this->socio();
        $this->opportunity(['member_id' => $socio['member'], 'goal' => 'renew', 'priority' => 50]);
        $this->opportunity(['member_id' => $socio['member'], 'goal' => 'collect_payment', 'priority' => 80, 'status' => 'in_progress']);
        $this->opportunity(['member_id' => $socio['member'], 'goal' => 'escalate', 'priority' => 95, 'status' => 'won']);
        $lead = $this->lead();
        $this->opportunity(['marketing_lead_id' => $lead, 'goal' => 'close_plan']);
        $sin = $this->socio();
        $foto = $this->snap([SubjectKey::member($socio['member']), SubjectKey::lead($lead), SubjectKey::member($sin['member'])], self::NOW, self::only(['nba.open_goal']));
        $this->assertSame('collect_payment', $foto->subject(SubjectKey::member($socio['member']))->fact('nba.open_goal'));
        $this->assertSame('close_plan', $foto->subject(SubjectKey::lead($lead))->fact('nba.open_goal'));
        $this->assertNull($foto->subject(SubjectKey::member($sin['member']))->fact('nba.open_goal'));
    }

    public function test_eventos_y_transiciones_con_la_fuente_apagada_son_desconocidos(): void
    {
        config(['commercial.events_enabled' => false]);
        $s = $this->socio(['membership_end_date' => '2026-11-01']);
        $this->commercialEvent('human_requested', '2026-11-08 15:00:00', $s['member']);
        $foto = $this->snap([SubjectKey::member($s['member'])], self::NOW, new FactSelection([], ['human_requested'], false, false, false, true));
        $p = $foto->subject(SubjectKey::member($s['member']));
        $this->assertNull($p->events);
        $this->assertNull($p->transitions);
        $this->assertFalse($foto->sources['commercial_events']);
    }

    public function test_eventos_con_ventana_atributo_de_plan_y_transiciones_del_estado(): void
    {
        config(['commercial.events_enabled' => true]);
        $s = $this->socio(['membership_end_date' => '2026-11-01']);
        $lead = $this->lead(['member_id' => $s['member']]);
        $this->commercialEvent('human_requested', '2026-11-08 15:00:00', $s['member'], $lead);   // socio y prospecto: una vez
        $this->commercialEvent('human_requested', '2024-11-08 15:00:00', $s['member']);          // fuera de los 730 días
        $this->commercialEvent('human_requested', '2026-11-09 16:00:00', $s['member']);          // futuro
        DB::table('commercial_events')->insert(['event' => 'payment_approved', 'member_id' => $s['member'], 'occurred_at' => self::utc('2026-11-05 15:00:00'),
            'payload' => json_encode(['plan_id' => 7, 'amount' => 123456, 'reference' => 'REF-SECRETA']), 'created_at' => self::utc('2026-11-05 15:00:00'), 'updated_at' => self::utc('2026-11-05 15:00:00')]);
        $congelado = $this->socio(['membership_end_date' => '2026-11-08', 'membership_frozen_at' => '2026-11-09']);

        $sel = new FactSelection([], ['human_requested', 'payment_approved'], false, false, false, true);
        $foto = $this->snap([SubjectKey::member($s['member']), SubjectKey::member($congelado['member'])], self::NOW, $sel);
        $p = $foto->subject(SubjectKey::member($s['member']));
        $this->assertSame([
            ['at' => '2026-11-08T15:00:00Z', 'attrs' => [], 'key' => 'human_requested'],
            ['at' => '2026-11-05T15:00:00Z', 'attrs' => ['plan_id' => 7], 'key' => 'payment_approved'],
        ], $p->events);
        $this->assertSame([
            ['at' => '2026-11-05T15:00:00Z', 'entity' => 'payment', 'from' => 'pending', 'to' => 'approved'],
            // Vencida el 01-11: pasó a vencida a las 00:00 de Bogotá del 02-11 (05:00 UTC).
            ['at' => '2026-11-02T05:00:00Z', 'entity' => 'membership', 'from' => 'active', 'to' => 'expired'],
        ], $p->transitions);
        $this->assertSame([['at' => '2026-11-09T05:00:00Z', 'entity' => 'membership', 'from' => 'active', 'to' => 'frozen']],
            $foto->subject(SubjectKey::member($congelado['member']))->transitions);
        $this->assertStringNotContainsString('123456', $foto->canonicalJson());
        $this->assertStringNotContainsString('REF-SECRETA', $foto->canonicalJson());
    }

    public function test_historial_cuenta_salientes_proactivos_llamadas_que_salieron_y_asistidos_registrados_en_vivo(): void
    {
        $s = $this->socio();
        $lead = $this->lead(['member_id' => $s['member'], 'phone' => '3005550001']);
        $conv = $this->conversation($lead);
        $this->aiMessage($conv, '2026-11-08 15:00:00', 'followup');
        $this->aiMessage($conv, '2026-11-07 15:00:00', 'reply');                 // respuesta: no cuenta
        $this->aiMessage($conv, '2026-11-06 15:00:00', null, 'human');           // de una persona: no cuenta
        $this->aiMessage($conv, '2026-11-05 15:00:00', null, 'lead', 'inbound'); // entrante: no cuenta
        $this->voiceContact('member', $s['member'], '+573005550001');
        $this->outboundCall('+573005550001', 'completed', '2026-11-04 15:00:00');
        $this->outboundCall('+573005550001', 'canceled', '2026-11-03 15:00:00'); // no salió
        $this->outboundCall('+573005559999', 'completed', '2026-11-02 15:00:00'); // otro número
        // H9 (DB9): el contacto asistido en vivo que una persona registró cuenta, con su estrategia; el del canal simulado en sombra, no.
        $vivo = $this->liveVersion();
        $estrategia = (string) DB::table('ultron_studio_strategies')->where('id', $vivo['strategy'])->value('uuid');
        $this->contactoAsistido($this->inscripcion($vivo, $s['member'], 'live'), 'answered', '2026-11-03 16:00:00');
        $sombra = $this->inscripcion($vivo, $s['member'], 'shadow');
        $simulado = $this->insertJob($sombra, ['created_at' => self::utc('2026-11-01 15:00:00')]);
        $this->walkJob($simulado, 'simulated');
        DB::table('outreach_outcomes')->insert($this->simulatedOutcomeRow($sombra, $simulado, ['occurred_at' => self::utc('2026-11-01 15:00:00')]));

        $foto = $this->snap([SubjectKey::member($s['member'])], self::NOW, new FactSelection([], [], true));
        $c = $foto->subject(SubjectKey::member($s['member']))->contacts;
        $this->assertSame([
            ['at' => '2026-11-08T15:00:00Z', 'medium' => 'whatsapp', 'outcome' => null, 'purpose' => 'commercial', 'source' => 'ai_proactive', 'status' => null, 'strategy' => null],
            ['at' => '2026-11-04T15:00:00Z', 'medium' => 'voice', 'outcome' => null, 'purpose' => 'commercial', 'source' => 'voice_call', 'status' => 'completed', 'strategy' => null],
            ['at' => '2026-11-03T16:00:00Z', 'medium' => 'whatsapp', 'outcome' => 'answered', 'purpose' => 'commercial', 'source' => 'outreach', 'status' => null, 'strategy' => $estrategia],
        ], $c);
        // DB9 está en el árbol: la fuente del H9 existe y la foto lo dice (antes de sus migraciones era false).
        $this->assertTrue(Schema::hasTable('outreach_outcomes'));
        $this->assertTrue($foto->sources['outreach_history']);
        // Ni cuerpos ni números.
        $this->assertStringNotContainsString('TEXTO-PRIVADO', $foto->canonicalJson());
        $this->assertStringNotContainsString('573001112233', $foto->canonicalJson());
    }

    public function test_sin_las_tablas_del_h9_la_fuente_no_esta_y_el_historial_sigue_contando_lo_demas(): void
    {
        $s = $this->socio();
        $lead = $this->lead(['member_id' => $s['member'], 'phone' => '3005550002']);
        $this->aiMessage($this->conversation($lead), '2026-11-08 15:00:00', 'followup');

        // El estado anterior a DB9 por su propia vuelta atrás (la 100002, que se niega si hay filas: aquí no las hay), en una
        // transacción que se deshace al salir, como hacen las pruebas de esquema. La detección es por proceso: se olvida antes y después.
        ContactHistoryFacts::forgetOutreachSource();
        DB::beginTransaction();
        try {
            $this->migrationNamed(self::EXECUTION_MIGRATION)->down();
            $this->assertFalse(Schema::hasTable('outreach_outcomes'));
            $foto = $this->snap([SubjectKey::member($s['member'])], self::NOW, new FactSelection([], [], true));
            $this->assertFalse($foto->sources['outreach_history']);
            $this->assertSame(['ai_proactive'], array_column($foto->subject(SubjectKey::member($s['member']))->contacts, 'source'));
        } finally {
            DB::rollBack();
            ContactHistoryFacts::forgetOutreachSource();
        }
        $this->assertTrue(Schema::hasTable('outreach_outcomes'));
        $this->assertTrue((new ContactHistoryFacts)->hasOutreachSource());
    }

    /**
     * Una inscripción activa del socio en el modo dado (una abierta por estrategia, persona y modo: sombra y vivo no chocan).
     *
     * @param  array{strategy: int, version: int, seq: int, purpose: string, v: object}  $vivo
     */
    private function inscripcion(array $vivo, int $memberId, string $mode): int
    {
        $clave = SubjectKey::member($memberId);

        return $this->insertEnrollment($vivo, ['subject_key' => $clave, 'subject_ref' => SubjectRef::of($clave), 'member_id' => $memberId, 'mode' => $mode,
            'enrolled_at' => self::utc('2026-11-01 15:00:00'), 'window_ends_at' => self::utc('2026-12-01 15:00:00')]);
    }

    /** Un trabajo asistido por WhatsApp que una persona reclamó, inició y cerró con `$outcome` (fuente `staff`, sin hora de próxima acción). */
    private function contactoAsistido(int $enrollmentId, string $outcome, string $atUtc): void
    {
        $cuando = self::utc($atUtc);
        $job = $this->insertAssistedJob($enrollmentId, ['not_before' => $cuando, 'expires_at' => self::at($atUtc)->modify('+5 hours')->format('Y-m-d H:i:s'),
            'budget_day' => substr($cuando, 0, 10), 'created_at' => $cuando]);
        $this->walkJob($job, 'started', ['assisted_claimed_by_admin_id' => 1, 'assisted_claimed_by_name' => 'Ana', 'assisted_claimed_at' => $cuando]);
        $this->insertOutcome($enrollmentId, ['job' => $job, 'outcome' => $outcome, 'source' => 'staff', 'source_key' => 'personal:'.Str::uuid(),
            'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana', 'next_action' => 'end_exit', 'occurred_at' => $cuando]);
    }
}
