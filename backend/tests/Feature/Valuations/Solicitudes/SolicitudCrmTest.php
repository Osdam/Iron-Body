<?php

namespace Tests\Feature\Valuations\Solicitudes;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationRequest;
use App\Services\Valuations\ValuationAccess;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las solicitudes en el CRM (CONTRATO_SOLICITUDES_VALORACION.md §3): lista y
 * detalle con el alcance de la agenda, `schedule` (misma ficha, abierta y
 * futura; idempotente con la misma cita), `attend`, `reject` con motivo y aviso
 * al socio, versión optimista y permisos.
 */
class SolicitudCrmTest extends TestCase
{
    use RefreshDatabase;
    use SolicitudFixtures;

    private Trainer $carlos;

    private Trainer $sofia;

    private Member $maria;

    private array $recepcion;

    private string $pendiente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-18 08:00');
        $this->carlos = $this->trainer('Carlos Pérez');
        $this->sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($this->carlos);
        $this->everyDay($this->sofia);
        $this->maria = $this->socio('María González', ['document_number' => '1012345678']);
        $this->recepcion = $this->reception();
        $this->pendiente = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cita(Member $socio, Trainer $t, string $cuando): string
    {
        return $this->book($this->recepcion, $socio, $t, $cuando)->assertCreated()->json('data.uuid');
    }

    private function agendar(string $solicitud, string $cita, int $version = 1, ?array $h = null)
    {
        return $this->postJson("/api/admin/valuations/requests/{$solicitud}/schedule", ['appointment_uuid' => $cita, 'expected_version' => $version], $h ?? $this->recepcion);
    }

    private function rechazar(string $solicitud, ?string $motivo, int $version = 1, ?array $h = null)
    {
        return $this->postJson("/api/admin/valuations/requests/{$solicitud}/reject", array_filter(['reason' => $motivo, 'expected_version' => $version], fn ($v) => $v !== null), $h ?? $this->recepcion);
    }

    private function atender(string $solicitud, int $version = 1, ?array $h = null)
    {
        return $this->postJson("/api/admin/valuations/requests/{$solicitud}/attend", ['expected_version' => $version], $h ?? $this->recepcion);
    }

    // ── Lista y detalle ─────────────────────────────────────────────────────

    public function test_la_lista_de_pendientes_tiene_la_forma_del_contrato(): void
    {
        $r = $this->getJson('/api/admin/valuations/requests', $this->recepcion)->assertOk();

        $r->assertExactJson(['ok' => true, 'data' => [[
            'uuid' => $this->pendiente,
            'status' => 'pending',
            'status_label' => 'Pendiente',
            'source' => 'app',
            'version' => 1,
            'created_at' => '2026-11-18T08:00:00-05:00',
            'member' => ['id' => (int) $this->maria->id, 'name' => 'María González', 'document_tail' => '5678'],
            'appointment' => null,
            'future_appointment' => null,
            'handled_by_name' => null,
            'handled_at' => null,
            'rejection_reason' => null,
            'allowed_actions' => ['schedule', 'attend', 'reject'],
        ]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 1]]);
        $this->assertStringNotContainsString('1012345678', $r->getContent(), 'nunca el documento entero');
        $this->assertStringNotContainsString($this->maria->phone, $r->getContent());
    }

    public function test_future_appointment_permite_enlazar_en_un_clic(): void
    {
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-19T10:00:00-05:00');

        $this->getJson('/api/admin/valuations/requests', $this->recepcion)->assertOk()
            ->assertJsonPath('data.0.future_appointment', [
                'uuid' => $cita, 'starts_at' => '2026-11-19T10:00:00-05:00', 'trainer_name' => 'Carlos Pérez', 'status' => 'scheduled',
            ])
            ->assertJsonPath('data.0.appointment', null);
    }

    public function test_pendientes_de_la_mas_antigua_y_todas_de_la_mas_nueva(): void
    {
        $this->clock('2026-11-18 09:00');
        $pedro = $this->socio('Pedro Ruiz');
        $dePedro = $this->solicitar($pedro)->assertCreated()->json('data.request.uuid');
        $this->clock('2026-11-18 10:00');
        $ana = $this->socio('Ana Torres');
        $deAna = $this->solicitar($ana)->assertCreated()->json('data.request.uuid');
        $this->rechazar($dePedro, 'Ya no hay cupos este mes')->assertOk();

        $uuids = fn (string $q) => array_column($this->getJson('/api/admin/valuations/requests'.$q, $this->recepcion)->assertOk()->json('data'), 'uuid');
        $this->assertSame([$this->pendiente, $deAna], $uuids(''));
        $this->assertSame([$this->pendiente, $deAna], $uuids('?status=pending'));
        $this->assertSame([$deAna, $dePedro, $this->pendiente], $uuids('?status=all'));

        $pagina = $this->getJson('/api/admin/valuations/requests?status=all&per_page=1&page=2', $this->recepcion)->assertOk();
        $this->assertSame([$dePedro], array_column($pagina->json('data'), 'uuid'));
        $pagina->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/admin/valuations/requests?status=rejected', $this->recepcion)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_detalle(): void
    {
        $lista = $this->getJson('/api/admin/valuations/requests', $this->recepcion)->json('data.0');
        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $this->recepcion)->assertOk()
            ->assertExactJson(['ok' => true, 'data' => $lista]);

        $this->getJson('/api/admin/valuations/requests/'.Str::uuid(), $this->recepcion)->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // ── schedule ────────────────────────────────────────────────────────────

    public function test_schedule_enlaza_la_cita_y_repetirlo_es_200(): void
    {
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-19T10:00:00-05:00');

        $this->agendar($this->pendiente, $cita)->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.appointment.uuid', $cita)
            ->assertJsonPath('data.appointment.trainer_name', 'Carlos Pérez')
            ->assertJsonPath('data.handled_by_name', 'Persona Recepción')
            ->assertJsonPath('data.handled_at', '2026-11-18T08:00:00-05:00')
            ->assertJsonPath('data.allowed_actions', []);

        $fila = $this->solicitud($this->pendiente);
        $this->assertSame('scheduled', $fila->status);
        $this->assertSame(ValuationAppointment::where('uuid', $cita)->value('id'), $fila->appointment_id);
        $this->assertNotNull($fila->handled_by_admin_id);

        $traza = AuditLog::query()->where('entity', 'solicitud_valoracion')->sole();
        $this->assertSame($this->pendiente, $traza->entity_id);
        $this->assertSame('Agendó la solicitud de valoración', $traza->summary);
        $this->assertSame([['field' => 'status', 'from' => 'pending', 'to' => 'scheduled']], $traza->changes);
        $this->assertSame($cita, $traza->metadata['appointment_uuid']);

        // Doble clic / reintento con la versión vieja y la misma cita: 200 sin cambios.
        $this->agendar($this->pendiente, $cita, 1)->assertOk()->assertJsonPath('changed', false)->assertJsonPath('data.version', 2);
        $this->assertSame(1, AuditLog::query()->where('entity', 'solicitud_valoracion')->count());

        // Otra cita: ya no está pendiente.
        $otra = $this->cita($this->maria, $this->carlos, '2026-11-20T10:00:00-05:00');
        $this->agendar($this->pendiente, $otra, 2)->assertStatus(409)->assertJsonPath('code', 'request_not_pending')->assertJsonPath('data.appointment.uuid', $cita);
        $this->agendar($this->pendiente, $otra, 1)->assertStatus(409)->assertJsonPath('code', 'request_changed');

        // La app ya no la muestra como activa y enseña la cita.
        $this->estado($this->maria)->assertJsonPath('data.request', null)->assertJsonPath('data.next_appointment.uuid', $cita);
        // Ni aviso al socio ni otro al CRM.
        $this->assertSame(["valuation:request:{$this->pendiente}:created", "valuation:request:{$this->pendiente}:received"], $this->clavesDeAvisos());
    }

    public function test_schedule_con_la_cita_de_otra_ficha_es_422_y_no_toca_nada(): void
    {
        $dePedro = $this->cita($this->socio('Pedro Ruiz'), $this->carlos, '2026-11-19T10:00:00-05:00');

        $this->agendar($this->pendiente, $dePedro)->assertStatus(422)
            ->assertJsonPath('code', 'appointment_member_mismatch')
            ->assertJsonStructure(['errors' => ['appointment_uuid']]);
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
        $this->assertSame(1, $this->solicitud($this->pendiente)->version);
        $this->assertSame(0, AuditLog::query()->where('entity', 'solicitud_valoracion')->count());
    }

    public function test_schedule_con_una_cita_pasada_se_rechaza(): void
    {
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-18T10:00:00-05:00');
        $this->clock('2026-11-18 10:00'); // la hora exacta ya no es futura

        $this->agendar($this->pendiente, $cita)->assertStatus(409)->assertJsonPath('code', 'appointment_not_future');
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
    }

    public function test_schedule_con_una_cita_cerrada_o_inexistente_se_rechaza(): void
    {
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-19T10:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$cita}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $this->recepcion)->assertOk();

        $this->agendar($this->pendiente, $cita)->assertStatus(409)->assertJsonPath('code', 'appointment_not_open');
        $this->agendar($this->pendiente, (string) Str::uuid())->assertStatus(422)->assertJsonPath('code', 'appointment_not_found');
        $this->agendar($this->pendiente, 'no-es-uuid')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson("/api/admin/valuations/requests/{$this->pendiente}/schedule", ['appointment_uuid' => $cita], $this->recepcion)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['expected_version']]);
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
    }

    // ── attend ──────────────────────────────────────────────────────────────

    public function test_attend_la_cierra_sin_cita(): void
    {
        $this->atender($this->pendiente)->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'attended')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.appointment', null);
        $this->atender($this->pendiente, 1)->assertOk()->assertJsonPath('changed', false);

        $this->assertSame('Marcó atendida la solicitud de valoración', AuditLog::query()->where('entity', 'solicitud_valoracion')->sole()->summary);
        $this->assertCount(2, $this->clavesDeAvisos(), 'atender no avisa a nadie');
        $this->estado($this->maria)->assertJsonPath('data.can_request', true);
    }

    // ── reject ──────────────────────────────────────────────────────────────

    public function test_reject_exige_motivo_de_10_a_500(): void
    {
        $this->rechazar($this->pendiente, null)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['reason']]);
        $this->rechazar($this->pendiente, '          ')->assertStatus(422);
        $this->rechazar($this->pendiente, 'Muy corto')->assertStatus(422);
        $this->rechazar($this->pendiente, str_repeat('x', 501))->assertStatus(422);
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
        $this->assertCount(2, $this->clavesDeAvisos());
    }

    public function test_reject_cierra_y_avisa_al_socio_sin_push(): void
    {
        $this->rechazar($this->pendiente, 'No hay entrenadores disponibles esta semana.')->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'No hay entrenadores disponibles esta semana.');

        $aviso = Notification::query()->where('event_key', "valuation:request:{$this->pendiente}:rejected")->sole();
        $this->assertSame('member', $aviso->audience);
        $this->assertSame((int) $this->maria->id, (int) $aviso->member_id);
        $this->assertFalse($aviso->should_popup);
        $this->assertSame('No pudimos agendar tu valoración: No hay entrenadores disponibles esta semana.', $aviso->message);
        $this->assertSame('/evaluation', $aviso->action_url);
        $this->assertSame('valuation_request_rejected', $aviso->metadata['source_event']);

        // El motivo no va a audit_logs.
        $traza = AuditLog::query()->where('entity', 'solicitud_valoracion')->sole();
        $this->assertSame('Rechazó la solicitud de valoración', $traza->summary);
        $this->assertStringNotContainsString('entrenadores disponibles', json_encode($traza->toArray()));

        // Repetirlo: 200 sin otro aviso.
        $this->rechazar($this->pendiente, 'Otro motivo distinto al primero', 1)->assertOk()->assertJsonPath('changed', false);
        $this->assertSame('No hay entrenadores disponibles esta semana.', $this->solicitud($this->pendiente)->rejection_reason);
        $this->assertSame(1, Notification::query()->where('event_key', 'like', '%:rejected')->count());
    }

    public function test_el_motivo_hostil_se_guarda_y_viaja_como_texto_plano(): void
    {
        $hostil = "<script>alert('x')</script> \"; DROP TABLE valuation_requests; -- {{7*7}} \${jndi:ldap://x} <img src=x onerror=1>";
        $r = $this->rechazar($this->pendiente, $hostil)->assertOk();

        $this->assertSame($hostil, $r->json('data.rejection_reason'));
        $this->assertSame($hostil, $this->solicitud($this->pendiente)->rejection_reason);
        $this->assertSame('No pudimos agendar tu valoración: '.$hostil, Notification::query()->where('event_key', 'like', '%:rejected')->value('message'));
        $this->assertSame(1, ValuationRequest::query()->count(), 'la tabla sigue ahí');
        $this->assertStringStartsWith('application/json', $r->headers->get('Content-Type'));

        // Controles e invisibles (bidi): 422, nada escrito.
        $otra = $this->solicitar($this->socio('Pedro Ruiz'))->assertCreated()->json('data.request.uuid');
        $this->rechazar($otra, "Motivo con NUL \0 dentro")->assertStatus(422);
        $this->rechazar($otra, "Motivo con \u{202E}bidi invertido")->assertStatus(422);
        $this->assertSame('pending', $this->solicitud($otra)->status);
    }

    // ── Versión ─────────────────────────────────────────────────────────────

    public function test_version_que_no_coincide_es_409_request_changed_y_cerrada_request_not_pending(): void
    {
        $this->atender($this->pendiente, 7)->assertStatus(409)
            ->assertJsonPath('code', 'request_changed')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'pending');
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 2)->assertStatus(409)->assertJsonPath('code', 'request_changed');

        $this->atender($this->pendiente, 1)->assertOk();
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 2)->assertStatus(409)
            ->assertJsonPath('code', 'request_not_pending')
            ->assertJsonPath('data.status', 'attended');
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 1)->assertStatus(409)->assertJsonPath('code', 'request_changed');
        $this->assertSame('attended', $this->solicitud($this->pendiente)->status);
        $this->assertSame(0, Notification::query()->where('event_key', 'like', '%:rejected')->count());
    }

    // ── Permisos y alcance ──────────────────────────────────────────────────

    public function test_sin_permiso_403(): void
    {
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-19T10:00:00-05:00');

        // Sin nada de la agenda.
        $nada = $this->h($this->staffWith(['members.view']));
        $this->getJson('/api/admin/valuations/requests', $nada)->assertForbidden();
        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $nada)->assertForbidden();

        // Ver todo no es escribir.
        $lectora = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::VIEW_ALL]));
        $this->getJson('/api/admin/valuations/requests', $lectora)->assertOk()->assertJsonPath('data.0.allowed_actions', []);
        $this->agendar($this->pendiente, $cita, 1, $lectora)->assertForbidden();
        $this->atender($this->pendiente, 1, $lectora)->assertForbidden();
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 1, $lectora)->assertForbidden();

        // `update` no es `create`: atiende y rechaza, pero no agenda.
        $editora = $this->h($this->staffWith([ValuationAccess::VIEW_ALL, ValuationAccess::UPDATE]));
        $this->agendar($this->pendiente, $cita, 1, $editora)->assertForbidden();

        // `view` sin entrenador vinculado no tiene alcance.
        $this->getJson('/api/admin/valuations/requests', $this->h($this->staffWith([ValuationAccess::VIEW])))->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
    }

    public function test_el_token_compartido_no_entra(): void
    {
        $t = $this->sharedTokenHeaders();
        $cita = $this->cita($this->maria, $this->carlos, '2026-11-19T10:00:00-05:00');
        $this->getJson('/api/admin/valuations/requests', $t)->assertForbidden();
        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $t)->assertForbidden();
        $this->agendar($this->pendiente, $cita, 1, $t)->assertForbidden();
        $this->atender($this->pendiente, 1, $t)->assertForbidden();
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 1, $t)->assertForbidden();
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);
    }

    public function test_quien_solo_ve_lo_suyo_no_ve_ni_opera_fuera_de_su_alcance_404(): void
    {
        // María es de Carlos; Pedro, de Sofía.
        $this->assign($this->maria, $this->carlos);
        $pedro = $this->socio('Pedro Ruiz');
        $this->assign($pedro, $this->sofia);
        $dePedro = $this->solicitar($pedro)->assertCreated()->json('data.request.uuid');

        $deSofia = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::CREATE, ValuationAccess::UPDATE], $this->sofia));
        $this->assertSame([$dePedro], array_column($this->getJson('/api/admin/valuations/requests?status=all', $deSofia)->assertOk()->json('data'), 'uuid'));
        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $deSofia)->assertNotFound();
        $this->atender($this->pendiente, 1, $deSofia)->assertNotFound();
        $this->rechazar($this->pendiente, 'Motivo suficientemente largo', 1, $deSofia)->assertNotFound();
        $this->agendar($this->pendiente, $this->cita($this->maria, $this->sofia, '2026-11-19T10:00:00-05:00'), 1, $deSofia)->assertNotFound();
        $this->assertSame('pending', $this->solicitud($this->pendiente)->status);

        // Tampoco enlaza una cita de otro entrenador a una solicitud suya.
        $deCarlosParaPedro = $this->cita($pedro, $this->carlos, '2026-11-20T10:00:00-05:00');
        $this->agendar($dePedro, $deCarlosParaPedro, 1, $deSofia)->assertStatus(422)->assertJsonPath('code', 'appointment_not_found');
        // Y la cita futura de otro entrenador no se le enseña.
        $this->getJson("/api/admin/valuations/requests/{$dePedro}", $deSofia)->assertOk()->assertJsonPath('data.future_appointment', null);
    }

    public function test_la_cuenta_de_entrenador_ve_la_solicitud_enlazada_a_su_cita(): void
    {
        // María NO es de Sofía, pero se agenda con ella.
        $cita = $this->cita($this->maria, $this->sofia, '2026-11-19T10:00:00-05:00');
        $entrenadora = $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $this->sofia));

        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $entrenadora)->assertNotFound();
        $this->agendar($this->pendiente, $cita)->assertOk();
        $this->getJson("/api/admin/valuations/requests/{$this->pendiente}", $entrenadora)->assertOk()
            ->assertJsonPath('data.appointment.uuid', $cita)
            ->assertJsonPath('data.member.name', 'María González')
            ->assertJsonPath('data.allowed_actions', []);
    }
}
