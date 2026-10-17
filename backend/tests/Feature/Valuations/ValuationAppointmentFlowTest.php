<?php

namespace Tests\Feature\Valuations;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * El ciclo de vida de una cita de valoración por la API (contrato §3–§4):
 * crear, editar, reprogramar, cancelar, confirmar, iniciar, completar y «no
 * asistió»; idempotencia, `expected_version` y cada código de error.
 */
class ValuationAppointmentFlowTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private array $recepcion;

    private array $entrenadorH;

    private Trainer $andres;

    private Member $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00'); // lunes
        $this->andres = $this->trainer('Andrés Pérez');
        $this->laura = $this->member('Laura Gómez');
        $this->everyDay($this->andres, '06:00', '21:00');
        $this->recepcion = $this->reception();
        $this->entrenadorH = $this->h($this->staff('Entrenador', $this->andres, 'Andrés'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crear(string $cuando = '2026-10-24T09:00:00-05:00', array $extra = []): array
    {
        return $this->book($this->recepcion, $this->laura, $this->andres, $cuando, $extra)->assertCreated()->json('data');
    }

    // ── Crear ──────────────────────────────────────────────────────────────

    public function test_crear_guarda_utc_y_devuelve_la_forma_del_contrato(): void
    {
        $cita = $this->crear('2026-10-24T09:00:00-05:00', ['notes' => 'Trae ropa cómoda']);

        $this->assertSame('2026-10-24T14:00:00+00:00', $cita['starts_at']);
        $this->assertSame('2026-10-24T15:00:00+00:00', $cita['ends_at']);
        $this->assertSame(['2026-10-24', '09:00', '10:00'], [$cita['local_date'], $cita['local_start'], $cita['local_end']]);
        $this->assertSame('scheduled', $cita['status']);
        $this->assertSame('Programada', $cita['status_label']);
        $this->assertSame('Valoración inicial', $cita['type_label']);
        $this->assertSame(1, $cita['version']);
        $this->assertSame('crm', $cita['source']);
        $this->assertSame('Trae ropa cómoda', $cita['notes']);
        $this->assertSame(['id', 'name', 'document_tail', 'membership_status', 'membership_label'], array_keys($cita['member']));
        $this->assertSame(substr($this->laura->document_number, -4), $cita['member']['document_tail']);
        $this->assertSame('active', $cita['member']['membership_status']);
        $this->assertFalse($cita['trainer']['is_assigned_to_member']);

        $fila = $this->appointment($cita['uuid']);
        $this->assertSame('2026-10-24 14:00:00', $fila->getRawOriginal('starts_at'));
        $this->assertSame(1, ValuationAppointmentEvent::where('appointment_id', $fila->id)->where('action', 'created')->count());
        $this->assertSame(1, AuditLog::where('module', 'Valoraciones')->where('entity_id', $cita['uuid'])->count());
    }

    public function test_sin_desfase_es_hora_de_pared_de_bogota(): void
    {
        $cita = $this->crear('2026-10-24T09:00');
        $this->assertSame('2026-10-24T14:00:00+00:00', $cita['starts_at']);
    }

    public function test_duracion_por_defecto_de_los_ajustes(): void
    {
        $this->settings(['default_duration_minutes' => 45]);
        $cita = $this->book($this->recepcion, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => null])->assertCreated()->json('data');
        $this->assertSame(45, $cita['duration_minutes']);
        $this->assertSame('09:45', $cita['local_end']);
    }

    public function test_validacion_con_forma_ok_code_errors(): void
    {
        $r = $this->postJson('/api/admin/valuations', ['member_id' => $this->laura->id], $this->recepcion)->assertStatus(422);
        $this->assertFalse($r->json('ok'));
        $this->assertSame('validation_failed', $r->json('code'));
        $this->assertArrayHasKey('client_request_id', $r->json('errors'));
        $this->assertArrayHasKey('starts_at', $r->json('errors'));

        // Duración fuera de rango o no múltiplo de 5.
        $this->book($this->recepcion, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => 47])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->book($this->recepcion, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => 300])
            ->assertStatus(422);
        // Fecha basura.
        $this->book($this->recepcion, $this->laura, $this->andres, 'mañana a las 9')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        // Notas > 1000.
        $this->book($this->recepcion, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['notes' => str_repeat('a', 1001)])->assertStatus(422);
        $this->assertSame(0, ValuationAppointment::count());
    }

    public function test_entrenador_inactivo_y_socio_inexistente(): void
    {
        $baja = $this->trainer('Baja Total', ['status' => 'inactive']);
        $this->everyDay($baja);
        $this->book($this->recepcion, $this->laura, $baja, '2026-10-24T09:00:00-05:00')
            ->assertStatus(422)->assertJsonPath('code', 'trainer_inactive');

        $this->postJson('/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['member_id' => 999999]), $this->recepcion)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    // ── Idempotencia ───────────────────────────────────────────────────────

    public function test_mismo_client_request_id_mismo_actor_devuelve_la_misma_cita_con_200(): void
    {
        $admin = $this->staff(Admin::ROLE_RECEPCION);
        $h = $this->h($admin);
        $cuerpo = $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00');

        $a = $this->postJson('/api/admin/valuations', $cuerpo, $h)->assertStatus(201)->json('data.uuid');
        $b = $this->postJson('/api/admin/valuations', $cuerpo, $h)->assertStatus(200)->json('data.uuid');

        $this->assertSame($a, $b);
        $this->assertSame(1, ValuationAppointment::count());
        $this->assertSame(1, ValuationAppointmentEvent::count());
    }

    public function test_mismo_client_request_id_otro_actor_u_otros_datos_es_request_conflict(): void
    {
        $cuerpo = $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->postJson('/api/admin/valuations', $cuerpo, $this->recepcion)->assertCreated();

        $this->postJson('/api/admin/valuations', $cuerpo, $this->reception())->assertStatus(409)->assertJsonPath('code', 'request_conflict');
        $this->postJson('/api/admin/valuations', array_merge($cuerpo, ['starts_at' => '2026-10-24T11:00:00-05:00']), $this->recepcion)
            ->assertStatus(409)->assertJsonPath('code', 'request_conflict');
        $this->assertSame(1, ValuationAppointment::count());
    }

    // ── Editar ─────────────────────────────────────────────────────────────

    public function test_editar_tipo_y_notas_no_sube_la_version_ni_toca_los_avisos(): void
    {
        $cita = $this->crear();
        $avisosAntes = ValuationReminder::where('status', 'pending')->count();

        $r = $this->patchJson("/api/admin/valuations/{$cita['uuid']}", ['expected_version' => 1, 'type' => 'follow_up', 'notes' => 'Llega tarde'], $this->recepcion)
            ->assertOk();
        $this->assertTrue($r->json('changed'));
        $this->assertSame('follow_up', $r->json('data.type'));
        $this->assertSame('Llega tarde', $r->json('data.notes'));
        $this->assertSame(1, $r->json('data.version'));
        $this->assertSame($avisosAntes, ValuationReminder::where('status', 'pending')->where('appointment_version', 1)->count());

        // Repetir lo mismo no cambia nada.
        $this->patchJson("/api/admin/valuations/{$cita['uuid']}", ['expected_version' => 1, 'type' => 'follow_up', 'notes' => 'Llega tarde'], $this->recepcion)
            ->assertOk()->assertJsonPath('changed', false);

        $evento = ValuationAppointmentEvent::where('action', 'updated')->firstOrFail();
        $this->assertStringNotContainsString('Llega tarde', json_encode($evento->toArray()), 'el historial no copia las notas');
    }

    public function test_editar_exige_expected_version(): void
    {
        $cita = $this->crear();
        $this->patchJson("/api/admin/valuations/{$cita['uuid']}", ['notes' => 'x'], $this->recepcion)->assertStatus(422);
        $r = $this->patchJson("/api/admin/valuations/{$cita['uuid']}", ['expected_version' => 7, 'notes' => 'x'], $this->recepcion)->assertStatus(409);
        $this->assertSame('appointment_changed', $r->json('code'));
        $this->assertSame($cita['uuid'], $r->json('data.uuid'), 'el 409 trae la cita como está');
    }

    // ── Reprogramar ────────────────────────────────────────────────────────

    public function test_reprogramar_mueve_vuelve_a_programada_sube_version_e_invalida_avisos_viejos(): void
    {
        $cita = $this->crear();
        $u = $cita['uuid'];
        $this->settings(['member_app_enabled' => true]);
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $this->entrenadorH)->assertOk();

        $r = $this->postJson("/api/admin/valuations/{$u}/reschedule", [
            'expected_version' => 2, 'starts_at' => '2026-10-26T10:00:00-05:00', 'reason' => 'Pidió otro día',
        ], $this->recepcion)->assertOk();

        $this->assertTrue($r->json('changed'));
        $this->assertSame('scheduled', $r->json('data.status'));
        $this->assertSame(3, $r->json('data.version'));
        $this->assertSame('2026-10-26T15:00:00+00:00', $r->json('data.starts_at'));

        $viejos = ValuationReminder::where('appointment_version', '<', 3)->whereIn('status', ['pending', 'error'])->count();
        $this->assertSame(0, $viejos, 'no queda ningún aviso pendiente de una versión anterior');
        $this->assertSame(1, ValuationReminder::where('appointment_version', 3)->where('kind', 'rescheduled')->count());
        $this->assertSame(0, ValuationReminder::where('appointment_version', 3)->where('kind', 'confirmation')->count());

        $ev = ValuationAppointmentEvent::where('action', 'rescheduled')->firstOrFail();
        $this->assertSame('confirmed', $ev->from_status);
        $this->assertSame('2026-10-24 14:00:00', $ev->getRawOriginal('from_starts_at'));
        $this->assertSame('Pidió otro día', $ev->reason);
    }

    public function test_reprogramar_a_donde_ya_esta_es_idempotente_y_con_version_vieja_es_409(): void
    {
        $u = $this->crear()['uuid'];
        $mover = ['expected_version' => 1, 'starts_at' => '2026-10-26T10:00:00-05:00'];
        $this->postJson("/api/admin/valuations/{$u}/reschedule", $mover, $this->recepcion)->assertOk()->assertJsonPath('changed', true);
        // Doble clic: mismo destino con la versión de antes → 200 sin cambios.
        $this->postJson("/api/admin/valuations/{$u}/reschedule", $mover, $this->recepcion)->assertOk()->assertJsonPath('changed', false);
        // Otro destino con versión vieja → 409 con la cita actual.
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-27T10:00:00-05:00'], $this->recepcion)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed')->assertJsonPath('data.version', 2);
    }

    public function test_reprogramar_a_otro_entrenador_y_duracion(): void
    {
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($sofia);
        $u = $this->crear()['uuid'];

        $r = $this->postJson("/api/admin/valuations/{$u}/reschedule", [
            'expected_version' => 1, 'starts_at' => '2026-10-24T09:00:00-05:00', 'trainer_id' => $sofia->id, 'duration_minutes' => 90,
        ], $this->recepcion)->assertOk();
        $this->assertSame($sofia->id, $r->json('data.trainer.id'));
        $this->assertSame(90, $r->json('data.duration_minutes'));
        $this->assertSame('10:30', $r->json('data.local_end'));
    }

    public function test_reprogramar_se_excluye_a_si_misma_del_solape(): void
    {
        $u = $this->crear('2026-10-24T09:00:00-05:00')['uuid'];
        // Media hora más tarde se solaparía consigo misma: no cuenta.
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T09:30:00-05:00'], $this->recepcion)
            ->assertOk()->assertJsonPath('data.local_start', '09:30');
    }

    public function test_no_se_reprograma_una_cerrada(): void
    {
        $u = $this->crear()['uuid'];
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $this->recepcion)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 2, 'starts_at' => '2026-10-26T10:00:00-05:00'], $this->recepcion)
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    // ── Cancelar ───────────────────────────────────────────────────────────

    public function test_cancelar_exige_motivo_y_anula_los_avisos_pendientes(): void
    {
        $u = $this->crear()['uuid'];
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'no'], $this->recepcion)->assertStatus(422);
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1], $this->recepcion)->assertStatus(422);

        $r = $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Se enfermó'], $this->recepcion)->assertOk();
        $this->assertSame('cancelled', $r->json('data.status'));
        $this->assertSame('Se enfermó', $r->json('data.cancellation_reason'));
        $this->assertSame(2, $r->json('data.version'));
        $this->assertSame(['edit'], $r->json('data.allowed_actions'), 'cancelada: solo se editan las notas');

        $this->assertSame(0, ValuationReminder::whereIn('status', ['pending', 'error'])->where('kind', '!=', 'cancellation')->count());
        $this->assertSame(1, ValuationReminder::where('kind', 'cancellation')->where('appointment_version', 2)->count());

        // Repetir: 200 sin cambios.
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Se enfermó'], $this->recepcion)
            ->assertOk()->assertJsonPath('changed', false);
        $fila = $this->appointment($u);
        $this->assertNotNull($fila->cancelled_at);
        $this->assertNotNull($fila->cancelled_by_admin_id);
    }

    // ── Transiciones ───────────────────────────────────────────────────────

    public function test_confirmar_iniciar_completar_con_sus_horas(): void
    {
        $u = $this->crear('2026-10-19T10:00:00-05:00')['uuid'];

        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $this->entrenadorH)
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.version', 2);
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $this->entrenadorH)
            ->assertOk()->assertJsonPath('changed', false);

        // Iniciar: desde 15 min antes.
        $this->clock('2026-10-19 09:44');
        $this->postJson("/api/admin/valuations/{$u}/start", ['expected_version' => 2], $this->entrenadorH)->assertStatus(409)->assertJsonPath('code', 'not_due');
        $this->clock('2026-10-19 09:45');
        $this->postJson("/api/admin/valuations/{$u}/start", ['expected_version' => 2], $this->entrenadorH)->assertOk()->assertJsonPath('data.status', 'in_progress');

        // Completar: desde la hora.
        $this->postJson("/api/admin/valuations/{$u}/complete", ['expected_version' => 3], $this->entrenadorH)->assertStatus(409)->assertJsonPath('code', 'not_due');
        $this->clock('2026-10-19 10:00');
        $r = $this->postJson("/api/admin/valuations/{$u}/complete", ['expected_version' => 3, 'note' => 'Todo en orden'], $this->entrenadorH)->assertOk();
        $this->assertSame('completed', $r->json('data.status'));
        $this->assertSame(4, $r->json('data.version'));

        $fila = $this->appointment($u);
        $this->assertNotNull($fila->confirmed_at);
        $this->assertNotNull($fila->started_at);
        $this->assertNotNull($fila->completed_at);
        $this->assertNotNull($fila->outcome_by_admin_id);
        $this->assertSame(
            ['created', 'confirmed', 'started', 'completed'],
            ValuationAppointmentEvent::where('appointment_id', $fila->id)->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Todo en orden', ValuationAppointmentEvent::where('action', 'completed')->value('reason'));
        $this->assertSame(0, ValuationReminder::where('appointment_id', $fila->id)->whereIn('status', ['pending', 'error'])->count(), 'cerrada: nada pendiente');
    }

    public function test_no_asistio_desde_la_hora_y_terminales(): void
    {
        $u = $this->crear('2026-10-19T10:00:00-05:00')['uuid'];
        $this->postJson("/api/admin/valuations/{$u}/no-show", ['expected_version' => 1], $this->entrenadorH)->assertStatus(409)->assertJsonPath('code', 'not_due');
        $this->clock('2026-10-19 10:20');
        $this->postJson("/api/admin/valuations/{$u}/no-show", ['expected_version' => 1], $this->entrenadorH)->assertOk()->assertJsonPath('data.status', 'no_show');

        // Terminal: nada más.
        foreach (['confirm', 'start', 'complete'] as $accion) {
            $this->postJson("/api/admin/valuations/{$u}/{$accion}", ['expected_version' => 2], $this->entrenadorH)
                ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        }
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 2, 'reason' => 'Ya no aplica'], $this->recepcion)
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_confirmar_despues_de_la_hora_es_too_late(): void
    {
        $u = $this->crear('2026-10-19T10:00:00-05:00')['uuid'];
        $this->clock('2026-10-19 10:00');
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $this->entrenadorH)
            ->assertStatus(409)->assertJsonPath('code', 'too_late');
    }

    public function test_transicion_con_version_vieja_es_409_con_la_cita_actual(): void
    {
        $u = $this->crear('2026-10-19T10:00:00-05:00')['uuid'];
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-19T11:00:00-05:00'], $this->recepcion)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $this->entrenadorH)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed')->assertJsonPath('data.local_start', '11:00');
    }

    public function test_allowed_actions_segun_estado_hora_y_permisos(): void
    {
        $u = $this->crear('2026-10-19T10:00:00-05:00')['uuid'];
        $ver = fn (array $h) => $this->getJson("/api/admin/valuations/{$u}", $h)->assertOk()->json('data.allowed_actions');

        $this->assertSame(['cancel', 'reschedule', 'edit'], $ver($this->recepcion), 'recepción no cierra citas');
        $this->assertSame(['confirm'], $ver($this->entrenadorH), 'el entrenador confirma, no mueve');
        $this->clock('2026-10-19 09:50');
        $this->assertSame(['confirm', 'start'], $ver($this->entrenadorH));
        $this->clock('2026-10-19 10:05');
        $this->assertSame(['start', 'complete', 'no_show'], $ver($this->entrenadorH));
    }

    public function test_detalle_trae_historial_reciente_primero_y_avisos_sin_contenido(): void
    {
        $u = $this->crear()['uuid'];
        $this->patchJson("/api/admin/valuations/{$u}", ['expected_version' => 1, 'notes' => 'nota'], $this->recepcion)->assertOk();
        $d = $this->getJson("/api/admin/valuations/{$u}", $this->recepcion)->assertOk()->json('data');

        $this->assertSame(['updated', 'created'], array_column($d['history'], 'action'));
        $this->assertSame(['action', 'label', 'actor_name', 'from_status', 'to_status', 'from_starts_at', 'to_starts_at', 'reason', 'created_at'], array_keys($d['history'][0]));
        $this->assertSame(['kind', 'channel', 'status', 'due_at', 'processed_at'], array_keys($d['reminders'][0]));
    }

    public function test_uuid_desconocido_404_y_formato_no_uuid_no_enruta(): void
    {
        $this->getJson('/api/admin/valuations/'.Str::uuid(), $this->recepcion)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson('/api/admin/valuations/no-es-un-uuid', $this->recepcion)->assertStatus(404);
    }

    public function test_el_historial_es_append_only(): void
    {
        $u = $this->crear()['uuid'];
        $ev = ValuationAppointmentEvent::firstOrFail();

        try {
            $ev->forceFill(['reason' => 'reescrito'])->save();
            $this->fail('un evento no se modifica');
        } catch (LogicException) {
        }
        try {
            $ev->delete();
            $this->fail('un evento no se borra');
        } catch (LogicException) {
        }
        $this->assertSame(1, ValuationAppointmentEvent::count());
        $this->assertNull(ValuationAppointmentEvent::firstOrFail()->reason);
        $this->assertNotNull($u);
    }
}
