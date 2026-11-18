<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberValuationFollowUp;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Las reglas del seguimiento (contrato §3) por la API real: precedencia del
 * intervalo, pausa, fecha puntual, cita futura, versión optimista, validación,
 * «última valoración», historial unificado, ajustes del gimnasio y auditoría.
 *
 * Reloj: miércoles 18-11-2026, 12:00 de Bogotá.
 */
class FollowUpRulesTest extends TestCase
{
    use RefreshDatabase;
    use SeguimientoFixtures;

    private Trainer $andres;

    private Trainer $sofia;

    private Member $laura;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-18 12:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($this->andres);
        $this->laura = $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $this->h = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Forma ──────────────────────────────────────────────────────────────

    public function test_forma_de_la_respuesta(): void
    {
        $this->assign($this->laura, $this->andres);
        $v = $this->valoracion($this->laura, $this->andres, '2026-10-01 09:10');
        $this->cita($this->laura, $this->andres, '2026-10-01 09:00', ValuationAppointment::STATUS_COMPLETED, $v, 'trainer_assessment', '2026-10-01 09:10');
        $this->valoracion($this->laura, $this->sofia, '2026-08-20 17:00');

        $r = $this->seguimiento($this->h, $this->laura)->assertOk()->assertJsonPath('ok', true);
        $r->assertJsonStructure(['data' => [
            'member' => ['id', 'name', 'document_tail', 'membership' => ['status', 'label', 'ends_on'], 'assigned_trainer' => ['id', 'name']],
            'last_valuation' => ['at', 'date', 'trainer' => ['id', 'name'], 'source', 'assessment_uuid', 'appointment_uuid'],
            'interval' => ['days', 'source', 'enabled', 'gym_default_days'],
            'due_on_override', 'next_suggested_on', 'days_until_due', 'next_appointment', 'status', 'version', 'can_edit',
            'history' => [['kind', 'at', 'date', 'status', 'trainer', 'appointment_uuid', 'assessment_uuid', 'linked', 'origin', 'completed_at']],
        ]]);
        $d = $r->json('data');
        $this->assertSame('3456', $d['member']['document_tail'], 'solo los 4 últimos del documento');
        $this->assertArrayNotHasKey('phone', $d['member']);
        $this->assertArrayNotHasKey('email', $d['member']);
        $this->assertSame(['status' => 'active', 'label' => 'Activa', 'ends_on' => '2027-01-31'], $d['member']['membership']);
        $this->assertSame('appointment', $d['last_valuation']['source']);
        $this->assertSame($v->uuid, $d['last_valuation']['assessment_uuid'], 'la cita enlazada lleva también su valoración');
        $this->assertSame('2026-10-01T09:10:00-05:00', $d['last_valuation']['at']);
        $this->assertSame(['id' => $this->andres->id, 'name' => 'Andrés Pérez'], $d['last_valuation']['trainer']);
        $this->assertSame('2026-11-30', $d['next_suggested_on'], '01-10 + 60');
        $this->assertSame(12, $d['days_until_due']);
        $this->assertSame('al_dia', $d['status']);
        $this->assertTrue($d['can_edit']);

        if ($salida = getenv('FOLLOW_UP_EXAMPLES_OUT')) {
            file_put_contents($salida, json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    // ── Precedencia e intervalos ───────────────────────────────────────────

    public function test_precedencia_socio_sobre_gimnasio_y_null_hereda(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-11-01 10:00');

        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.interval.days', 60)->assertJsonPath('data.interval.source', 'gym')
            ->assertJsonPath('data.interval.gym_default_days', 60)
            ->assertJsonPath('data.next_suggested_on', '2026-12-31');

        $this->cambiar($this->h, $this->laura, ['interval_days' => 20])->assertOk()
            ->assertJsonPath('data.interval.days', 20)->assertJsonPath('data.interval.source', 'member')
            ->assertJsonPath('data.interval.gym_default_days', 60)
            ->assertJsonPath('data.next_suggested_on', '2026-11-21')
            ->assertJsonPath('data.status', 'proximo')
            ->assertJsonPath('data.version', 1);

        // El gimnasio cambia: el socio con intervalo propio no se entera.
        $this->settings(['follow_up_interval_days' => 15]);
        $this->seguimiento($this->h, $this->laura)->assertJsonPath('data.interval.days', 20);

        // Null vuelve a heredar (ahora 15).
        $this->cambiar($this->h, $this->laura, ['interval_days' => null, 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.interval.source', 'gym')->assertJsonPath('data.interval.days', 15)
            ->assertJsonPath('data.next_suggested_on', '2026-11-16')
            ->assertJsonPath('data.days_until_due', -2)
            ->assertJsonPath('data.status', 'vencido');
    }

    public function test_gimnasio_null_y_socio_sin_intervalo_es_sin_seguimiento(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-11-01 10:00');
        $this->settings(['follow_up_interval_days' => null]);

        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.interval.days', null)
            ->assertJsonPath('data.interval.source', null)
            ->assertJsonPath('data.interval.gym_default_days', null)
            ->assertJsonPath('data.next_suggested_on', null)
            ->assertJsonPath('data.days_until_due', null)
            // Sin estado propio en el contrato para «sin seguimiento»: al día.
            ->assertJsonPath('data.status', 'al_dia');

        // Con intervalo propio, el socio sí tiene seguimiento aunque el gimnasio no.
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk()
            ->assertJsonPath('data.next_suggested_on', '2026-12-01');
    }

    public function test_sin_valoracion(): void
    {
        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.last_valuation', null)
            ->assertJsonPath('data.next_suggested_on', null)
            ->assertJsonPath('data.status', 'sin_valoracion')
            ->assertJsonPath('data.history', []);
    }

    public function test_pausado_no_tiene_proximo_control_y_se_reactiva(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-08-01 10:00');
        $this->seguimiento($this->h, $this->laura)->assertJsonPath('data.status', 'vencido');

        $this->cambiar($this->h, $this->laura, ['enabled' => false])->assertOk()
            ->assertJsonPath('data.status', 'pausado')
            ->assertJsonPath('data.interval.enabled', false)
            ->assertJsonPath('data.next_suggested_on', null)
            ->assertJsonPath('data.days_until_due', null);

        $this->cambiar($this->h, $this->laura, ['enabled' => true, 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.status', 'vencido')
            ->assertJsonPath('data.next_suggested_on', '2026-09-30');
    }

    // ── Fecha puntual ──────────────────────────────────────────────────────

    public function test_due_on_sustituye_al_calculo(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-11-01 10:00');

        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'due_on' => '2026-11-25'])->assertOk()
            ->assertJsonPath('data.due_on_override', '2026-11-25')
            ->assertJsonPath('data.next_suggested_on', '2026-11-25')
            ->assertJsonPath('data.days_until_due', 7)
            ->assertJsonPath('data.status', 'proximo');

        // Hoy mismo vale.
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'due_on' => '2026-11-18', 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.days_until_due', 0);

        // Quitarla vuelve al cálculo.
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'due_on' => null, 'expected_version' => 2])->assertOk()
            ->assertJsonPath('data.due_on_override', null)
            ->assertJsonPath('data.next_suggested_on', '2026-12-01');
    }

    public function test_due_on_se_consume_con_la_siguiente_valoracion(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-11-01 10:00');
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'due_on' => '2026-11-25'])->assertOk()
            ->assertJsonPath('data.next_suggested_on', '2026-11-25');

        // El socio se valora ANTES de la fecha puntual: la fecha ya cumplió su función.
        Carbon::setTestNow(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $this->valoracion($this->laura, $this->andres, '2026-11-20 10:00');
        $h = $this->reception(); // la sesión de hace tres días ya caducó

        $this->seguimiento($h, $this->laura)->assertOk()
            ->assertJsonPath('data.due_on_override', null)
            ->assertJsonPath('data.last_valuation.date', '2026-11-20')
            ->assertJsonPath('data.next_suggested_on', '2026-12-20')
            ->assertJsonPath('data.status', 'al_dia');
    }

    public function test_due_on_en_el_pasado_es_422_salvo_la_fecha_ya_guardada(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-11-01 10:00');

        $this->cambiar($this->h, $this->laura, ['due_on' => '2026-11-17'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['due_on']]);
        $this->assertDatabaseMissing('member_valuation_follow_ups', ['member_id' => $this->laura->id]);

        $this->cambiar($this->h, $this->laura, ['due_on' => '2026-11-20'])->assertOk();

        // Pasan los días: la fecha guardada ya venció y el PUT es entero.
        // Cambiar el intervalo reenviándola NO puede fallar.
        $this->clock('2026-11-23 09:00');
        $h = $this->reception();
        $this->cambiar($h, $this->laura, ['interval_days' => 45, 'due_on' => '2026-11-20', 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.status', 'vencido')
            ->assertJsonPath('data.interval.days', 45);
        // Pero otra fecha pasada, no.
        $this->cambiar($h, $this->laura, ['interval_days' => 45, 'due_on' => '2026-11-21', 'expected_version' => 2])->assertStatus(422);
    }

    public function test_due_on_sin_valoracion(): void
    {
        $this->cambiar($this->h, $this->laura, ['due_on' => '2026-12-03'])->assertOk()
            ->assertJsonPath('data.next_suggested_on', '2026-12-03')
            ->assertJsonPath('data.days_until_due', 15)
            ->assertJsonPath('data.status', 'sin_valoracion');
    }

    // ── Cita futura ────────────────────────────────────────────────────────

    public function test_cambiar_el_intervalo_con_una_cita_futura_no_la_mueve(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-09-01 10:00');
        $uuid = $this->book($this->h, $this->laura, $this->andres, '2026-11-26T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $antes = $this->appointment($uuid);

        $r = $this->cambiar($this->h, $this->laura, ['interval_days' => 15])->assertOk()
            ->assertJsonPath('data.status', 'agendado')
            ->assertJsonPath('data.next_appointment.uuid', $uuid)
            ->assertJsonPath('data.next_appointment.starts_at', '2026-11-26T09:00:00-05:00')
            ->assertJsonPath('data.next_appointment.status', 'scheduled')
            ->assertJsonPath('data.next_appointment.trainer.id', $this->andres->id)
            // El próximo control se sigue calculando (01-09 + 15): ya pasó, pero
            // la cita manda en el estado.
            ->assertJsonPath('data.next_suggested_on', '2026-09-16');

        $despues = $this->appointment($uuid);
        $this->assertTrue($antes->starts_at->eq($despues->starts_at), 'la cita no se movió');
        $this->assertSame($antes->version, $despues->version);
        $this->assertSame($antes->status, $despues->status);
        $this->assertSame('agendado', $r->json('data.status'));
    }

    public function test_una_cita_pasada_sin_cerrar_no_cuenta_como_agendada(): void
    {
        $this->valoracion($this->laura, $this->andres, '2026-08-01 10:00');
        $this->cita($this->laura, $this->andres, '2026-11-17 10:00', ValuationAppointment::STATUS_SCHEDULED);

        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.next_appointment', null)
            ->assertJsonPath('data.status', 'vencido');
    }

    // ── Versión ────────────────────────────────────────────────────────────

    public function test_409_si_la_version_no_coincide_con_el_estado_actual(): void
    {
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk()->assertJsonPath('data.version', 1);

        // Otra pestaña que leyó la versión 0.
        $r = $this->cambiar($this->h, $this->laura, ['interval_days' => 90, 'expected_version' => 0])->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'follow_up_changed')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.interval.days', 30);
        $this->assertNotEmpty($r->json('message'));
        $this->assertSame(30, $this->fila()->interval_days);

        // Una versión del futuro tampoco.
        $this->cambiar($this->h, $this->laura, ['interval_days' => 90, 'expected_version' => 7])->assertStatus(409);
        // Sin fila, cualquier versión distinta de 0 es 409 y no deja fila.
        $pedro = $this->member('Pedro Ruiz');
        $this->cambiar($this->h, $pedro, ['interval_days' => 90, 'expected_version' => 1])->assertStatus(409)->assertJsonPath('data.version', 0);
        $this->assertDatabaseMissing('member_valuation_follow_ups', ['member_id' => $pedro->id]);
    }

    public function test_put_concurrente_dos_pestanas_con_la_misma_version(): void
    {
        // Las dos leyeron la versión 0 y envían a la vez: la que llega
        // segunda (serializadas por el cerrojo de la fila) recibe 409 y su
        // cambio no se aplica. En PostgreSQL, el cerrojo real lo prueba
        // FollowUpPostgresTest.
        $a = $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'expected_version' => 0]);
        $b = $this->cambiar($this->reception(), $this->laura, ['interval_days' => 90, 'expected_version' => 0]);

        $this->assertSame([200, 409], [$a->status(), $b->status()]);
        $this->assertSame(30, $this->fila()->interval_days);
        $this->assertSame(1, $this->fila()->version);
        $this->assertSame(1, AuditLog::query()->where('entity', 'seguimiento_valoraciones')->count());
    }

    public function test_el_mismo_put_dos_veces_no_sube_la_version_ni_audita(): void
    {
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk()->assertJsonPath('data.version', 1);
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, AuditLog::query()->where('entity', 'seguimiento_valoraciones')->count());
    }

    // ── Validación ─────────────────────────────────────────────────────────

    public function test_validacion_del_cuerpo(): void
    {
        foreach ([0, 366, -5, 30.5, 'treinta'] as $malo) {
            $this->cambiar($this->h, $this->laura, ['interval_days' => $malo])->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['interval_days']]);
        }
        foreach (['2026-02-30', '18-11-2026', '2026-11-18T00:00:00', '2101-01-01'] as $mala) {
            $this->cambiar($this->h, $this->laura, ['due_on' => $mala])->assertStatus(422)->assertJsonStructure(['errors' => ['due_on']]);
        }
        $this->putJson("/api/admin/valuations/members/{$this->laura->id}/follow-up", ['interval_days' => 30], $this->h)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['enabled', 'due_on', 'expected_version']]);
        $this->cambiar($this->h, $this->laura, ['enabled' => 'quizá'])->assertStatus(422);
        $this->assertDatabaseMissing('member_valuation_follow_ups', ['member_id' => $this->laura->id]);

        $this->cambiar($this->h, $this->laura, ['interval_days' => 1])->assertOk()->assertJsonPath('data.interval.days', 1);
        $this->cambiar($this->h, $this->laura, ['interval_days' => 365, 'expected_version' => 1])->assertOk()->assertJsonPath('data.interval.days', 365);
    }

    // ── Última valoración e historial ──────────────────────────────────────

    public function test_la_enmienda_no_mueve_la_fecha_y_el_borrador_no_cuenta(): void
    {
        $raiz = $this->valoracion($this->laura, $this->andres, '2026-10-01 09:00', ['status' => ProfessionalAssessment::STATUS_AMENDED]);
        $this->valoracion($this->laura, $this->andres, '2026-11-10 09:00', ['parent_id' => $raiz->id, 'version' => 2, 'amendment_reason' => 'Corrige la cintura']);
        $this->valoracion($this->laura, $this->andres, '2026-11-17 09:00', ['status' => ProfessionalAssessment::STATUS_DRAFT, 'submitted_at' => null]);

        $r = $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.last_valuation.date', '2026-10-01')
            ->assertJsonPath('data.last_valuation.source', 'assessment')
            ->assertJsonPath('data.last_valuation.assessment_uuid', $raiz->uuid)
            ->assertJsonPath('data.last_valuation.appointment_uuid', null);
        $historial = $r->json('data.history');
        $this->assertCount(1, $historial, 'una cadena de enmiendas es una fila; el borrador no sale');
        $this->assertSame(['assessment', 'amended', $raiz->uuid, false, 'app_entrenador'], [
            $historial[0]['kind'], $historial[0]['status'], $historial[0]['assessment_uuid'], $historial[0]['linked'], $historial[0]['origin'],
        ]);
    }

    public function test_la_mas_reciente_entre_cita_completada_y_valoracion(): void
    {
        $this->valoracion($this->laura, $this->sofia, '2026-10-05 08:00');
        $this->cita($this->laura, $this->andres, '2026-10-20 07:00');
        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.last_valuation.source', 'appointment')
            ->assertJsonPath('data.last_valuation.date', '2026-10-20')
            ->assertJsonPath('data.last_valuation.assessment_uuid', null)
            ->assertJsonPath('data.last_valuation.trainer.name', 'Andrés Pérez');

        $nueva = $this->valoracion($this->laura, $this->sofia, '2026-11-02 08:00');
        $this->seguimiento($this->h, $this->laura)->assertOk()
            ->assertJsonPath('data.last_valuation.source', 'assessment')
            ->assertJsonPath('data.last_valuation.assessment_uuid', $nueva->uuid)
            ->assertJsonPath('data.last_valuation.trainer.name', 'Sofía Ríos');

        // Cancelada o «no asistió» no son valoraciones.
        $this->cita($this->laura, $this->andres, '2026-11-15 07:00', ValuationAppointment::STATUS_CANCELLED);
        $this->cita($this->laura, $this->andres, '2026-11-16 07:00', ValuationAppointment::STATUS_NO_SHOW);
        $this->seguimiento($this->h, $this->laura)->assertJsonPath('data.last_valuation.date', '2026-11-02');
    }

    public function test_historial_unificado_y_ordenado(): void
    {
        $enlazada = $this->valoracion($this->laura, $this->andres, '2026-10-01 09:10');
        $agenda = $this->cita($this->laura, $this->andres, '2026-10-01 09:00', ValuationAppointment::STATUS_COMPLETED, $enlazada, 'trainer_assessment', '2026-10-01 09:10');
        $suelta = $this->valoracion($this->laura, $this->sofia, '2026-10-15 16:00');
        $reconciliada = $this->valoracion($this->laura, $this->andres, '2026-09-02 10:30');
        $antigua = $this->cita($this->laura, $this->andres, '2026-09-02 10:00', ValuationAppointment::STATUS_COMPLETED, $reconciliada, 'historical_reconciliation', '2026-09-02 10:30');
        $crm = $this->cita($this->laura, $this->sofia, '2026-08-10 10:00', ValuationAppointment::STATUS_COMPLETED, null, 'otra');
        $cancelada = $this->cita($this->laura, $this->sofia, '2026-11-05 10:00', ValuationAppointment::STATUS_CANCELLED);
        $futura = $this->book($this->h, $this->laura, $this->andres, '2026-11-27T10:00:00-05:00')->assertCreated()->json('data.uuid');

        $h = $this->seguimiento($this->h, $this->laura)->assertOk()->json('data.history');

        $this->assertSame(
            [$futura, $cancelada->uuid, null, $agenda->uuid, $antigua->uuid, $crm->uuid],
            array_column($h, 'appointment_uuid'),
            'de la más reciente a la más antigua; la valoración enlazada no sale suelta',
        );
        $this->assertSame([false, false, false, true, true, false], array_column($h, 'linked'));
        $this->assertSame(['agenda', 'agenda', 'app_entrenador', 'app_entrenador', 'reconciliacion', 'agenda'], array_column($h, 'origin'));
        $this->assertSame([null, null, $suelta->uuid, $enlazada->uuid, $reconciliada->uuid, null], array_column($h, 'assessment_uuid'));
        $this->assertSame(['appointment', 'appointment', 'assessment', 'appointment', 'appointment', 'appointment'], array_column($h, 'kind'));
        $this->assertSame('2026-10-01T09:10:00-05:00', $h[3]['completed_at']);
        $this->assertSame('2026-10-01T09:00:00-05:00', $h[3]['at']);
        $this->assertSame(['name' => 'Sofía Ríos'], $h[2]['trainer']);
        $this->assertSame('2026-10-15', $h[2]['date']);
        $this->assertCount(1, array_filter($h, fn ($f) => $f['assessment_uuid'] === $enlazada->uuid), 'cita + valoración = una fila');
    }

    public function test_historial_como_mucho_50(): void
    {
        foreach (range(1, 30) as $i) {
            $this->cita($this->laura, $this->andres, Carbon::parse('2026-01-01 08:00')->addDays($i)->format('Y-m-d H:i'), ValuationAppointment::STATUS_CANCELLED);
            $this->valoracion($this->laura, $this->andres, Carbon::parse('2026-03-01 08:00')->addDays($i)->format('Y-m-d H:i'));
        }
        $h = $this->seguimiento($this->h, $this->laura)->assertOk()->json('data.history');
        $this->assertCount(50, $h);
        $this->assertSame('assessment', $h[0]['kind']);
        $this->assertSame('2026-03-31', $h[0]['date']);
        $fechas = array_column($h, 'at');
        $ordenadas = $fechas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
    }

    // ── Ajustes del gimnasio ───────────────────────────────────────────────

    public function test_ajustes_follow_up_interval_days(): void
    {
        $this->assertSame(60, ValuationSetting::query()->value('follow_up_interval_days'), 'la migración deja 60 en la fila existente');
        $config = $this->h($this->staffWith(['valuations.manage_settings']));
        $this->getJson('/api/admin/valuations/settings', $config)->assertOk()->assertJsonPath('data.follow_up_interval_days', 60);

        $base = $this->getJson('/api/admin/valuations/settings', $config)->json('data');
        unset($base['updated_at'], $base['follow_up_interval_days']);

        // Un CRM anterior no lo envía: no falla ni lo borra.
        $this->putJson('/api/admin/valuations/settings', $base, $config)->assertOk()->assertJsonPath('data.follow_up_interval_days', 60);

        $this->putJson('/api/admin/valuations/settings', $base + ['follow_up_interval_days' => 30], $config)->assertOk()
            ->assertJsonPath('data.follow_up_interval_days', 30);
        $this->putJson('/api/admin/valuations/settings', $base + ['follow_up_interval_days' => null], $config)->assertOk()
            ->assertJsonPath('data.follow_up_interval_days', null);
        foreach ([0, 366, 'x', 12.5] as $malo) {
            $this->putJson('/api/admin/valuations/settings', $base + ['follow_up_interval_days' => $malo], $config)
                ->assertStatus(422)->assertJsonStructure(['errors' => ['follow_up_interval_days']]);
        }
        $this->putJson('/api/admin/valuations/settings', $base + ['follow_up_interval_days' => 365], $config)->assertOk();

        $traza = AuditLog::query()->where('entity', 'ajustes_valoraciones')->orderBy('id')->get()
            ->flatMap(fn (AuditLog $a) => $a->changes ?? [])
            ->where('field', 'follow_up_interval_days')->values()->all();
        $this->assertSame([
            ['field' => 'follow_up_interval_days', 'from' => '60', 'to' => '30'],
            ['field' => 'follow_up_interval_days', 'from' => '30', 'to' => null],
            ['field' => 'follow_up_interval_days', 'from' => null, 'to' => '365'],
        ], $traza);

        // Sin manage_settings no se escribe.
        $this->putJson('/api/admin/valuations/settings', $base + ['follow_up_interval_days' => 30], $this->h)->assertForbidden();
    }

    // ── Auditoría ──────────────────────────────────────────────────────────

    public function test_cada_cambio_queda_auditado_con_antes_y_despues(): void
    {
        $quien = $this->staff(Admin::ROLE_RECEPCION, null, 'Marta');
        $h = $this->h($quien);
        $this->cambiar($h, $this->laura, ['interval_days' => 30])->assertOk();
        $this->cambiar($h, $this->laura, ['interval_days' => 30, 'enabled' => false, 'due_on' => '2026-12-01', 'expected_version' => 1])->assertOk();

        $trazas = AuditLog::query()->where('entity', 'seguimiento_valoraciones')->orderBy('id')->get();
        $this->assertCount(2, $trazas);
        $this->assertSame((string) $this->laura->id, $trazas[0]->entity_id);
        $this->assertSame((string) $quien->id, $trazas[0]->actor_id);
        $this->assertSame('Valoraciones', $trazas[0]->module);
        $this->assertSame([['field' => 'interval_days', 'from' => null, 'to' => '30']], $trazas[0]->changes);
        $this->assertEqualsCanonicalizing([
            ['field' => 'enabled', 'from' => 'sí', 'to' => 'no'],
            ['field' => 'due_on', 'from' => null, 'to' => '2026-12-01'],
        ], $trazas[1]->changes);
        $this->assertSame(['member_id' => $this->laura->id, 'version' => 2], $trazas[1]->metadata);
        $this->assertStringNotContainsString('Laura', json_encode($trazas->toArray()), 'la traza no lleva el nombre del socio');

        $fila = $this->fila();
        $this->assertSame($quien->id, (int) $fila->updated_by_admin_id);
        $this->assertSame(2, $fila->version);
    }

    private function fila(): MemberValuationFollowUp
    {
        return MemberValuationFollowUp::query()->where('member_id', $this->laura->id)->firstOrFail();
    }
}
