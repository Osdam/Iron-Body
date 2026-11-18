<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Exceptions\AssessmentException;
use App\Models\Member;
use App\Models\Notification;
use App\Models\PhysicalEvaluation;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerRealtimeEvent;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use App\Services\Trainer\ProfessionalAssessmentService;
use App\Services\Valuations\ValuationReminderProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * CONTRATO_CICLO_VALORACIONES.md §1: el envío de una valoración profesional
 * completa la cita del día (si es la única y del mismo entrenador), la enlaza
 * y avisa a la bandeja del CRM DESPUÉS del commit. Todo por los endpoints
 * reales (agenda del CRM y portal del entrenador).
 *
 * Calendario: lunes 19-10-2026 en Bogotá; reloj en UTC.
 */
class FinalizacionAutomaticaTest extends TestCase
{
    use CicloFixtures;
    use RefreshDatabase;

    private array $rec;

    private Trainer $carlos;

    private Trainer $ana;

    private Member $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portalOn();
        $this->clock('2026-10-19 08:00');
        $this->carlos = $this->coach('Carlos Pérez');
        $this->ana = $this->coach('Ana Rojas');
        $this->maria = $this->member('María Gómez');
        $this->assign($this->maria, $this->carlos);
        $this->rec = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cita(string $uuid): ValuationAppointment
    {
        return $this->appointment($uuid)->fresh();
    }

    private function valoracion(string $uuid): ProfessionalAssessment
    {
        return ProfessionalAssessment::query()->where('uuid', $uuid)->firstOrFail();
    }

    // ── El caso normal ──────────────────────────────────────────────────────

    public function test_con_la_cita_del_mismo_entrenador_la_completa_y_enlaza_con_un_solo_aviso(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:40');
        $v = $this->assess($this->carlos, $this->maria);

        $valoracion = $this->valoracion($v);
        $cita = $this->cita($u);
        $this->assertSame('completed', $cita->status);
        $this->assertSame((int) $valoracion->id, (int) $cita->professional_assessment_id);
        $this->assertSame(2, $cita->version);
        $this->assertSame('2026-10-19 14:40:00', $cita->getRawOriginal('completed_at'), 'la fecha REAL del envío, en UTC');
        $this->assertTrue($cita->completed_at->equalTo($valoracion->submitted_at));
        $this->assertNull($cita->outcome_by_admin_id);

        // Ningún aviso pendiente de la cita (ni «sin atender»).
        $this->assertSame(0, ValuationReminder::where('appointment_id', $cita->id)->whereIn('status', ['pending', 'error'])->count());

        // Historial: `completed` sin cuenta del CRM, con el nombre del entrenador.
        $evento = ValuationAppointmentEvent::where('appointment_id', $cita->id)->where('action', 'completed')->sole();
        $this->assertNull($evento->actor_admin_id);
        $this->assertSame('Carlos Pérez', $evento->actor_name);
        $this->assertSame(['scheduled', 'completed'], [$evento->from_status, $evento->to_status]);
        $this->assertSame(['source' => 'trainer_assessment', 'assessment_uuid' => $v, 'trainer_id' => $this->carlos->id], $evento->metadata);

        // Traza del entrenador con la cita.
        $traza = TrainerAuditLog::where('event', 'assessment.submitted')->sole();
        $this->assertSame($u, $traza->metadata['appointment_uuid']);
        $this->assertSame('linked', $traza->metadata['appointment_match']);

        // UN aviso en la bandeja, con su forma exacta.
        $n = $this->assessmentNotices()->sole();
        $this->assertSame('admin', $n->audience);
        $this->assertSame('valuation', $n->type);
        $this->assertSame('valuations.view_all', $n->required_permission);
        $this->assertSame("valuation:assessment:{$v}:completed", $n->event_key);
        $this->assertSame('Valoración completada', $n->title);
        $this->assertSame('Carlos P. finalizó la valoración de María G. · 19 oct, 9:40 a. m.', $n->message);
        $this->assertSame('valuation_detail', $n->action_type);
        $this->assertSame(['appointment_uuid' => $u], $n->action_payload);
        $this->assertSame([
            'source_event' => 'valuation_completed', 'member_id' => $this->maria->id, 'trainer_id' => $this->carlos->id,
            'assessment_uuid' => $v, 'appointment_uuid' => $u,
        ], $n->metadata);
        $this->assertNull($n->member_id);
        $this->assertNull($n->document);
        // Sin medidas ni datos físicos (la valoración lleva 80.5 kg y observaciones).
        $todo = json_encode([$n->title, $n->message, $n->metadata, $n->action_payload], JSON_UNESCAPED_UNICODE);
        foreach (['80.5', '80,5', 'kg', 'weight', 'progreso'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo);
        }

        // Lo de siempre sigue: aviso al socio y copia a evaluación física.
        $this->assertSame(1, Notification::where('audience', 'member')->where('type', 'professional_assessment')->count());
        $this->assertSame(1, PhysicalEvaluation::where('member_id', $this->maria->id)->count());
    }

    public function test_sin_atender_no_sale_tras_el_cierre(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:05');
        $this->assess($this->carlos, $this->maria);

        $this->clock('2026-10-19 09:20');
        app(ValuationReminderProcessor::class)->run();
        $this->assertNull(Notification::where('event_key', 'like', "valuation:{$u}:internal_unattended%")->first());
    }

    public function test_el_envio_antes_de_la_hora_de_la_cita_tambien_la_cierra(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T10:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->assess($this->carlos, $this->maria);

        $cita = $this->cita($u);
        $this->assertSame('completed', $cita->status);
        $this->assertSame('2026-10-19 14:30:00', $cita->getRawOriginal('completed_at'), 'antes de la hora: la fecha real, no la de la cita');
        $this->assertSame("valuation:assessment:{$v}:completed", $this->assessmentNotices()->sole()->event_key);
    }

    // ── Cuando NO se toca la cita ───────────────────────────────────────────

    public function test_sin_cita_ese_dia_no_toca_nada_y_avisa_sin_cita(): void
    {
        $otroDia = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-20T09:00:00-05:00');
        $this->clock('2026-10-19 11:00');
        $v = $this->assess($this->carlos, $this->maria);

        $this->assertSame(['scheduled', 1, null], [$this->cita($otroDia)->status, $this->cita($otroDia)->version, $this->cita($otroDia)->professional_assessment_id]);
        $n = $this->assessmentNotices()->sole();
        $this->assertSame("valuation:assessment:{$v}:unlinked", $n->event_key);
        $this->assertSame('Valoración realizada sin cita', $n->title);
        $this->assertSame('Carlos P. registró la valoración de María G. · 19 oct. No había cita pendiente ese día.', $n->message);
        $this->assertSame('valuation_member', $n->action_type);
        $this->assertSame(['member_id' => $this->maria->id, 'assessment_uuid' => $v], $n->action_payload);
        $this->assertSame(['source_event' => 'valuation_assessment_unlinked', 'member_id' => $this->maria->id, 'trainer_id' => $this->carlos->id, 'assessment_uuid' => $v], $n->metadata);
        $this->assertSame('valuations.view_all', $n->required_permission);
        $this->assertSame('none', TrainerAuditLog::where('event', 'assessment.submitted')->sole()->metadata['appointment_match']);
        $this->assertArrayNotHasKey('appointment_uuid', TrainerAuditLog::where('event', 'assessment.submitted')->sole()->metadata);
    }

    public function test_con_dos_candidatas_no_toca_ninguna_y_pide_revision(): void
    {
        $a = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $b = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T15:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->assess($this->carlos, $this->maria);

        foreach ([$a, $b] as $u) {
            $this->assertSame(['scheduled', 1, null], [$this->cita($u)->status, $this->cita($u)->version, $this->cita($u)->professional_assessment_id]);
        }
        $n = $this->assessmentNotices()->sole();
        $this->assertSame("valuation:assessment:{$v}:review", $n->event_key);
        $this->assertSame('Valoración por revisar', $n->title);
        $this->assertSame('Carlos P. registró la valoración de María G. · 19 oct. Había 2 citas ese día.', $n->message);
        $this->assertSame('valuation_member', $n->action_type);
        $this->assertSame(['member_id' => $this->maria->id, 'assessment_uuid' => $v, 'reason' => 'ambiguous'], $n->action_payload);
        $this->assertSame('ambiguous', $n->metadata['reason']);
        $this->assertArrayNotHasKey('appointment_uuid', $n->metadata);
    }

    public function test_la_cita_de_otro_entrenador_no_se_toca_y_pide_revision(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->ana, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->assess($this->carlos, $this->maria);

        $this->assertSame(['scheduled', 1, null], [$this->cita($u)->status, $this->cita($u)->version, $this->cita($u)->professional_assessment_id]);
        $n = $this->assessmentNotices()->sole();
        $this->assertSame("valuation:assessment:{$v}:review", $n->event_key);
        $this->assertSame('Carlos P. registró la valoración de María G. · 19 oct. La cita del día era con Ana R.', $n->message);
        $this->assertSame(['member_id' => $this->maria->id, 'assessment_uuid' => $v, 'appointment_uuid' => $u, 'reason' => 'different_trainer'], $n->action_payload);
        $this->assertSame([
            'source_event' => 'valuation_assessment_review', 'member_id' => $this->maria->id, 'trainer_id' => $this->carlos->id,
            'assessment_uuid' => $v, 'appointment_uuid' => $u, 'reason' => 'different_trainer',
        ], $n->metadata);
    }

    public function test_el_borrador_y_el_guardado_parcial_no_tocan_nada(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:10');
        $v = $this->draft($this->carlos, $this->maria, ['weight_kg' => 70]);
        $this->putJson("/api/trainer/assessments/{$v}", ['weight_kg' => 71, 'observations' => 'A medias'], $this->asCoach($this->carlos))->assertOk();

        $this->assertSame(['scheduled', 1, null], [$this->cita($u)->status, $this->cita($u)->version, $this->cita($u)->professional_assessment_id]);
        $this->assertCount(0, $this->assessmentNotices());
        $this->assertSame(0, ValuationAppointmentEvent::where('action', 'completed')->count());
    }

    public function test_la_enmienda_no_vuelve_a_completar(): void
    {
        $a = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->assess($this->carlos, $this->maria);
        $this->assertSame('completed', $this->cita($a)->status);

        // Otra cita ese mismo día, agendada después del envío.
        $b = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T16:00:00-05:00');
        $this->clock('2026-10-19 10:00');
        $this->postJson("/api/trainer/assessments/{$v}/amend", ['weight_kg' => 79, 'amendment_reason' => 'Corrijo el peso'], $this->asCoach($this->carlos))->assertCreated();

        $this->assertSame(['scheduled', 1, null], [$this->cita($b)->status, $this->cita($b)->version, $this->cita($b)->professional_assessment_id]);
        $this->assertSame((int) $this->valoracion($v)->id, (int) $this->cita($a)->professional_assessment_id, 'el enlace sigue en la raíz');
        $this->assertSame(1, ValuationAppointmentEvent::where('action', 'completed')->count());
        $this->assertSame(["valuation:assessment:{$v}:completed"], $this->assessmentNotices()->pluck('event_key')->all(), 'la enmienda no avisa');
    }

    public function test_borde_del_dia_de_bogota(): void
    {
        // Martes 20 a las 20:00 de Bogotá = miércoles 21 a la 01:00 UTC.
        // Miércoles 21 a las 07:00 de Bogotá = miércoles 21 a las 12:00 UTC.
        $martes = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-20T20:00:00-05:00');
        $miercoles = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-21T07:00:00-05:00');

        // Envío el martes a las 23:30 de Bogotá = miércoles 21 a las 04:30 UTC.
        $this->clock('2026-10-20 23:30');
        $this->assertSame('2026-10-21 04:30:00', Carbon::now()->format('Y-m-d H:i:s'));
        $v = $this->assess($this->carlos, $this->maria);

        $this->assertSame('completed', $this->cita($martes)->status, 'la del martes de Bogotá');
        $this->assertSame('scheduled', $this->cita($miercoles)->status, 'la del miércoles NO, aunque comparta el día UTC');
        $this->assertSame("valuation:assessment:{$v}:completed", $this->assessmentNotices()->sole()->event_key);
        $this->assertSame('Carlos P. finalizó la valoración de María G. · 20 oct, 11:30 p. m.', $this->assessmentNotices()->sole()->message);
    }

    public function test_segunda_valoracion_del_mismo_dia_no_cierra_otra_vez(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $primera = $this->assess($this->carlos, $this->maria);
        $this->clock('2026-10-19 09:40');
        $segunda = $this->assess($this->carlos, $this->maria);

        $cita = $this->cita($u);
        $this->assertSame((int) $this->valoracion($primera)->id, (int) $cita->professional_assessment_id);
        $this->assertSame(2, $cita->version, 'no se vuelve a completar');
        $this->assertSame('2026-10-19 14:30:00', $cita->getRawOriginal('completed_at'));
        $this->assertSame(1, ValuationAppointmentEvent::where('action', 'completed')->count());
        $this->assertSame([
            "valuation:assessment:{$primera}:completed",
            "valuation:assessment:{$segunda}:unlinked",
        ], $this->assessmentNotices()->pluck('event_key')->all());
    }

    public function test_segunda_valoracion_otro_dia_cierra_su_propia_cita(): void
    {
        $lunes = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $jueves = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-22T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $primera = $this->assess($this->carlos, $this->maria);
        $this->clock('2026-10-22 09:15');
        $segunda = $this->assess($this->carlos, $this->maria);

        $this->assertSame((int) $this->valoracion($primera)->id, (int) $this->cita($lunes)->professional_assessment_id);
        $this->assertSame((int) $this->valoracion($segunda)->id, (int) $this->cita($jueves)->professional_assessment_id);
        $this->assertSame('completed', $this->cita($jueves)->status);
    }

    public function test_la_cita_ya_completada_por_un_admin_no_se_toca(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:10');
        $root = $this->superAdmin();
        $this->postJson("/api/admin/valuations/{$u}/complete", ['expected_version' => 1], $this->h($root))->assertOk();
        $antes = $this->cita($u);

        $this->clock('2026-10-19 09:30');
        $v = $this->assess($this->carlos, $this->maria);

        $despues = $this->cita($u);
        $this->assertSame(['completed', 2, null], [$despues->status, $despues->version, $despues->professional_assessment_id]);
        $this->assertSame($antes->getRawOriginal('completed_at'), $despues->getRawOriginal('completed_at'));
        $this->assertSame((int) $root->id, (int) $despues->outcome_by_admin_id);
        $this->assertSame(["valuation:assessment:{$v}:unlinked"], $this->assessmentNotices()->pluck('event_key')->all(), 'ni un segundo «completada»');
    }

    public function test_cancelada_y_no_asistio_no_son_candidatas(): void
    {
        $cancelada = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$cancelada}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $this->rec)->assertOk();
        $ausente = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T10:00:00-05:00');
        $this->clock('2026-10-19 10:05');
        $this->postJson("/api/admin/valuations/{$ausente}/no-show", ['expected_version' => 1], $this->h($this->superAdmin()))->assertOk();

        $this->clock('2026-10-19 11:00');
        $v = $this->assess($this->carlos, $this->maria);
        $this->assertSame('cancelled', $this->cita($cancelada)->status);
        $this->assertSame('no_show', $this->cita($ausente)->status);
        $this->assertSame(["valuation:assessment:{$v}:unlinked"], $this->assessmentNotices()->pluck('event_key')->all());
    }

    // ── Doble envío, fallos y orden respecto del commit ─────────────────────

    public function test_un_segundo_envio_responde_409_y_no_duplica_nada(): void
    {
        $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->draft($this->carlos, $this->maria);
        $this->submit($this->carlos, $v)->assertOk();
        $this->submit($this->carlos, $v)->assertStatus(409)->assertJsonPath('message', 'Solo un borrador puede enviarse.');

        $this->assertCount(1, $this->assessmentNotices());
        $this->assertSame(1, PhysicalEvaluation::where('member_id', $this->maria->id)->count());
        $this->assertSame(1, ValuationAppointmentEvent::where('action', 'completed')->count());
    }

    /**
     * La instancia que llega al servicio se leyó ANTES del envío ajeno (lo que
     * pasa con dos peticiones a la vez): el servicio no se fía de ella, relee
     * la valoración bloqueada y responde el 409 sin escribir nada. La carrera
     * real con dos procesos está en tests/Concurrency/ValoracionCita.
     */
    public function test_el_envio_con_una_copia_vieja_del_borrador_se_rechaza_bajo_el_cerrojo(): void
    {
        $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->draft($this->carlos, $this->maria);
        $copiaVieja = $this->valoracion($v);
        $this->submit($this->carlos, $v)->assertOk();

        $this->assertTrue($copiaVieja->isDraft(), 'la copia sigue diciendo borrador');
        try {
            app(ProfessionalAssessmentService::class)->submit($copiaVieja, $this->carlos);
            $this->fail('el segundo envío tenía que rechazarse');
        } catch (AssessmentException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(1, PhysicalEvaluation::where('member_id', $this->maria->id)->count());
        $this->assertCount(1, $this->assessmentNotices());
        $this->assertSame(1, Notification::where('audience', 'member')->where('type', 'professional_assessment')->count());
    }

    /**
     * El aviso falla DE VERDAD en la base (un disparador rechaza la fila): el
     * envío se confirma igual. En PostgreSQL esto solo pasa si el aviso va
     * después del commit y en su propio punto de guardado; dentro de la
     * transacción del envío la dejaría abortada (25P02) y el envío daría 500.
     */
    public function test_si_el_aviso_falla_el_envio_se_confirma_igual(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->romperAvisosDeValoracion();
        Log::spy();

        $this->clock('2026-10-19 09:30');
        $v = $this->draft($this->carlos, $this->maria);
        $this->submit($this->carlos, $v)->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->assertSame('submitted', $this->valoracion($v)->status);
        $this->assertSame('completed', $this->cita($u)->status);
        $this->assertSame((int) $this->valoracion($v)->id, (int) $this->cita($u)->professional_assessment_id);
        $this->assertCount(0, $this->assessmentNotices());
        $this->assertSame(1, PhysicalEvaluation::where('member_id', $this->maria->id)->count());
        $this->assertSame(1, Notification::where('audience', 'member')->where('type', 'professional_assessment')->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === 'valuations.assessment_notice_failed'
            && ($ctx['assessment_uuid'] ?? null) === $v && ($ctx['outcome'] ?? null) === 'linked')->once();
    }

    /**
     * El aviso al CRM y la señal de los entrenadores salen DESPUÉS del commit:
     * dentro de una transacción de la aplicación todavía no existen; al
     * confirmarla, sí.
     */
    public function test_aviso_y_senal_de_entrenadores_solo_despues_del_commit(): void
    {
        $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->draft($this->carlos, $this->maria);
        TrainerRealtimeEvent::query()->delete();

        DB::transaction(function () use ($v): void {
            app(ProfessionalAssessmentService::class)->submit($this->valoracion($v), $this->carlos);
            $this->assertCount(0, $this->assessmentNotices(), 'el aviso no sale antes del commit');
            $this->assertSame(0, TrainerRealtimeEvent::count(), 'la señal no sale antes del commit');
        });

        $this->assertSame("valuation:assessment:{$v}:completed", $this->assessmentNotices()->sole()->event_key);
        $this->assertSame(1, TrainerRealtimeEvent::where('trainer_id', $this->carlos->id)->where('type', 'assessment.updated')->count());
    }

    public function test_si_la_transaccion_se_deshace_no_hay_aviso_ni_cita_cerrada(): void
    {
        $u = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $this->clock('2026-10-19 09:30');
        $v = $this->draft($this->carlos, $this->maria);

        try {
            DB::transaction(function () use ($v): void {
                app(ProfessionalAssessmentService::class)->submit($this->valoracion($v), $this->carlos);
                throw new \RuntimeException('se deshace');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('draft', $this->valoracion($v)->status);
        $this->assertSame(['scheduled', null], [$this->cita($u)->status, $this->cita($u)->professional_assessment_id]);
        $this->assertCount(0, $this->assessmentNotices());
    }

    public function test_el_indice_unico_impide_que_una_valoracion_cierre_dos_citas(): void
    {
        $a = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-19T09:00:00-05:00');
        $b = $this->schedule($this->rec, $this->maria, $this->carlos, '2026-10-20T09:00:00-05:00');
        DB::table('valuation_appointments')->where('uuid', $a)->update(['professional_assessment_id' => 999]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => DB::table('valuation_appointments')->where('uuid', $b)->update(['professional_assessment_id' => 999]));
    }

    /** Un disparador que rechaza las filas `valuation` de la bandeja del CRM (SQLite y PostgreSQL). */
    private function romperAvisosDeValoracion(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ciclo_rompe_aviso() RETURNS trigger LANGUAGE plpgsql AS $f$
                BEGIN
                    IF NEW.audience = 'admin' AND NEW.type = 'valuation' THEN
                        RAISE EXCEPTION 'aviso roto a propósito';
                    END IF;
                    RETURN NEW;
                END $f$;
                CREATE TRIGGER ciclo_rompe_aviso BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION ciclo_rompe_aviso();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ciclo_rompe_aviso BEFORE INSERT ON notifications
            WHEN NEW.audience = 'admin' AND NEW.type = 'valuation'
            BEGIN SELECT RAISE(ABORT, 'aviso roto a propósito'); END;
            SQL);
    }
}
