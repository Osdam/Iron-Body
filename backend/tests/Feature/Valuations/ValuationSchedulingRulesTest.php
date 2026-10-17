<?php

namespace Tests\Feature\Valuations;

use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationAvailabilityBlock;
use App\Models\ValuationAvailabilityRule;
use App\Models\ValuationReminder;
use App\Services\Valuations\ValuationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Las reglas de la franja (contrato §3, 1–10) y el cálculo de huecos, con la
 * zona horaria como protagonista: reglas en hora de pared de Bogotá, columnas
 * en UTC, reloj de pruebas en UTC.
 */
class ValuationSchedulingRulesTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private array $rec;

    private Trainer $andres;

    private Member $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00'); // lunes, 13:00 UTC
        $this->andres = $this->trainer('Andrés Pérez');
        $this->laura = $this->member('Laura Gómez');
        $this->rec = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function code(string $cuando, array $extra = [], ?Member $m = null, ?array $h = null): ?string
    {
        $r = $this->book($h ?? $this->rec, $m ?? $this->laura, $this->andres, $cuando, $extra);

        return $r->status() === 201 ? null : $r->json('code');
    }

    // ── 6. Disponibilidad ──────────────────────────────────────────────────

    public function test_sin_disponibilidad_no_hay_huecos_ni_citas(): void
    {
        $this->assertSame('outside_availability', $this->code('2026-10-24T09:00:00-05:00'));
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $this->rec)->assertOk()->json('data');
        $this->assertSame([], $s['windows']);
        $this->assertSame([], $s['slots']);
    }

    public function test_varias_franjas_por_dia_el_descanso_es_el_hueco(): void
    {
        // Sábado 24: 07:00–12:00 y 14:00–18:00.
        $this->rule($this->andres, 6, '07:00', '12:00');
        $this->rule($this->andres, 6, '14:00', '18:00');

        $this->assertNull($this->code('2026-10-24T11:00:00-05:00'), 'termina justo en el fin de la franja');
        $this->assertSame('outside_availability', $this->code('2026-10-24T11:30:00-05:00', [], $this->member('Otro')), 'se sale de la franja');
        $this->assertSame('outside_availability', $this->code('2026-10-24T12:30:00-05:00', [], $this->member('Otra')), 'en el descanso');
        $this->assertNull($this->code('2026-10-24T14:00:00-05:00', [], $this->member('Tercera')));

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $this->rec)->assertOk()->json('data');
        $this->assertSame([['start' => '07:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], $s['windows']);
        $inicios = array_column($s['slots'], 'local_start');
        $this->assertContains('07:00', $inicios);
        $this->assertNotContains('11:30', $inicios, 'no cabe una hora antes del descanso');
        $this->assertNotContains('11:00', $inicios, 'ocupada');
        $this->assertNotContains('12:00', $inicios);
        $this->assertNotContains('14:00', $inicios, 'ocupada');
        $this->assertContains('15:00', $inicios);
        $this->assertSame('17:00', end($inicios), 'el último que cabe entero');
    }

    public function test_vigencia_de_las_reglas(): void
    {
        $this->rule($this->andres, 6, '07:00', '12:00', ['valid_from' => '2026-11-01']);
        $this->rule($this->andres, 6, '07:00', '12:00', ['valid_until' => '2026-10-23']);
        $this->rule($this->andres, 6, '07:00', '12:00', ['active' => false]);
        $this->assertSame('outside_availability', $this->code('2026-10-24T08:00:00-05:00'), 'ninguna vigente el 24');

        $this->rule($this->andres, 6, '07:00', '12:00', ['valid_from' => '2026-10-24', 'valid_until' => '2026-10-24']);
        $this->assertNull($this->code('2026-10-24T08:00:00-05:00'));
    }

    public function test_cita_en_fin_de_semana_y_el_mes_siguiente(): void
    {
        $this->rule($this->andres, 7, '08:00', '12:00'); // domingo
        $this->rule($this->andres, 5, '08:00', '12:00'); // viernes
        $this->assertNull($this->code('2026-10-25T09:00:00-05:00'), 'domingo 25');
        $r = $this->book($this->rec, $this->member('Mes Siguiente'), $this->andres, '2026-11-20T08:00:00-05:00')->assertCreated();
        $this->assertSame('2026-11-20', $r->json('data.local_date'));
        $this->assertSame('2026-11-20T13:00:00+00:00', $r->json('data.starts_at'));
    }

    public function test_cruce_de_medianoche_bogota_utc(): void
    {
        // Viernes 23 de 18:00 a 23:30 en Bogotá: 23:00–04:30 UTC del sábado.
        $this->rule($this->andres, 5, '18:00', '23:30');
        $r = $this->book($this->rec, $this->laura, $this->andres, '2026-10-23T20:30:00-05:00')->assertCreated();
        $this->assertSame('2026-10-24T01:30:00+00:00', $r->json('data.starts_at'), 'en UTC ya es sábado');
        $this->assertSame('2026-10-23', $r->json('data.local_date'), 'en Bogotá sigue siendo viernes');

        // La regla del SÁBADO no abre esa hora: 01:30 UTC del sábado no es sábado en Bogotá.
        $sabado = $this->trainer('Sábado Solo');
        $this->rule($sabado, 6, '00:00', '23:59');
        $this->book($this->rec, $this->member('X'), $sabado, '2026-10-24T01:30:00+00:00')
            ->assertStatus(409)->assertJsonPath('code', 'outside_availability');

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-23", $this->rec)->json('data');
        $this->assertContains('22:30', array_column($s['slots'], 'local_start'));
        $this->assertContains(['local_start' => '20:30', 'local_end' => '21:30', 'reason' => 'appointment', 'label' => 'Ocupado'], $s['busy']);
    }

    public function test_lunes_antes_de_las_cinco_utc_es_domingo_en_bogota(): void
    {
        // Domingo 25 de 20:00 a 23:30 Bogotá = lunes 26 de 01:00 a 04:30 UTC.
        $this->rule($this->andres, 7, '20:00', '23:30');
        $r = $this->book($this->rec, $this->laura, $this->andres, '2026-10-26T03:00:00Z')->assertCreated();
        $this->assertSame('2026-10-25', $r->json('data.local_date'));
        $this->assertSame('22:00', $r->json('data.local_start'));

        // Una clase de los LUNES a las 06:00 no choca con ella; una de los DOMINGOS a las 22:00, sí.
        $this->klass($this->andres, ['day_of_week' => 'Lunes', 'start_time' => '06:00', 'end_time' => '07:00']);
        $this->klass($this->andres, ['name' => 'Yoga nocturno', 'day_of_week' => 'Domingo', 'start_time' => '21:00', 'end_time' => '21:45']);
        $this->book($this->rec, $this->member('Y'), $this->andres, '2026-10-26T02:00:00Z')
            ->assertStatus(409)->assertJsonPath('code', 'class_conflict')->assertJsonPath('class_name', 'Yoga nocturno');

        // Y el reloj: lunes 03:00 UTC es domingo 22:00 en Bogotá; «hoy» es domingo.
        $this->clock('2026-10-25 22:00');
        $rec = $this->reception(); // la sesión de hace seis días ya caducó
        $this->settings(['max_horizon_days' => 1]);
        $this->rule($this->andres, 1, '07:00', '12:00');
        $this->book($rec, $this->member('Z'), $this->andres, '2026-10-26T08:00:00-05:00')->assertCreated();
        $this->book($rec, $this->member('W'), $this->andres, '2026-10-27T08:00:00-05:00')
            ->assertStatus(422)->assertJsonPath('code', 'beyond_horizon');
    }

    // ── 7. Bloqueos ────────────────────────────────────────────────────────

    public function test_bloqueo_rechaza_con_su_tipo_y_los_bordes_no_chocan(): void
    {
        $this->everyDay($this->andres);
        ValuationAvailabilityBlock::create([
            'trainer_id' => $this->andres->id, 'kind' => 'absence', 'reason' => 'Médico',
            'starts_at' => Carbon::parse('2026-10-24 10:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-24 12:00', 'America/Bogota')->utc(),
        ]);
        $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T10:30:00-05:00')
            ->assertStatus(409)->assertJsonPath('code', 'blocked')->assertJsonPath('block_kind', 'absence');
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00'), 'termina cuando empieza el bloqueo');
        $this->assertNull($this->code('2026-10-24T12:00:00-05:00', [], $this->member('B')), 'empieza cuando acaba el bloqueo');

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $this->rec)->json('data');
        $this->assertContains(['local_start' => '10:00', 'local_end' => '12:00', 'reason' => 'block', 'label' => 'Ocupado'], $s['busy']);
        $this->assertStringNotContainsString('Médico', json_encode($s), 'el motivo del bloqueo no sale en los huecos');
    }

    // ── 8. Clases ──────────────────────────────────────────────────────────

    public function test_clases_activas_bloquean_y_las_inactivas_o_finalizadas_no(): void
    {
        $this->everyDay($this->andres);
        $this->klass($this->andres, ['name' => 'Spinning', 'day_of_week' => 'Sábado', 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->klass($this->andres, ['name' => 'Borrador', 'status' => 'inactive', 'day_of_week' => 'Sábado', 'start_time' => '11:00', 'end_time' => '12:00']);
        $this->klass($this->andres, ['name' => 'Vieja', 'status' => 'finished', 'day_of_week' => 'Sábado', 'start_time' => '13:00', 'end_time' => '14:00']);
        // De otro entrenador: no le ocupa.
        $this->klass($this->trainer('Otro'), ['day_of_week' => 'Sábado', 'start_time' => '15:00', 'end_time' => '16:00']);

        $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:30:00-05:00')
            ->assertStatus(409)->assertJsonPath('code', 'class_conflict')->assertJsonPath('class_name', 'Spinning');
        $this->assertNull($this->code('2026-10-24T10:00:00-05:00'), 'justo al terminar la clase');
        $this->assertNull($this->code('2026-10-24T11:00:00-05:00', [], $this->member('I')), 'inactiva no ocupa');
        $this->assertNull($this->code('2026-10-24T13:00:00-05:00', [], $this->member('F')), 'finalizada no ocupa');
        $this->assertNull($this->code('2026-10-24T15:00:00-05:00', [], $this->member('O')), 'clase de otro entrenador');

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $this->rec)->json('data');
        $this->assertContains(['local_start' => '09:00', 'local_end' => '10:00', 'reason' => 'class', 'label' => 'Ocupado'], $s['busy']);
    }

    public function test_si_el_negocio_lo_desactiva_las_clases_no_bloquean(): void
    {
        $this->everyDay($this->andres);
        $this->klass($this->andres, ['day_of_week' => 'Sábado', 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->settings(['block_on_class_conflict' => false]);
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00'));
    }

    // ── 9 y 10. Otras citas: entrenador (con margen) y socio ───────────────

    public function test_bordes_que_se_tocan_no_chocan_y_el_solape_si(): void
    {
        $this->everyDay($this->andres);
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00'));
        $this->assertNull($this->code('2026-10-24T10:00:00-05:00', [], $this->member('A')), 'empieza cuando acaba la otra');
        $this->assertNull($this->code('2026-10-24T08:00:00-05:00', [], $this->member('B')), 'acaba cuando empieza la otra');
        $this->assertSame('slot_taken', $this->code('2026-10-24T09:30:00-05:00', [], $this->member('C')));
    }

    public function test_margen_entre_citas(): void
    {
        $this->everyDay($this->andres);
        $this->settings(['buffer_minutes' => 15]);
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00'));
        $this->assertSame('slot_taken', $this->code('2026-10-24T10:00:00-05:00', [], $this->member('A')), 'dentro del margen');
        $this->assertNull($this->code('2026-10-24T10:15:00-05:00', [], $this->member('B')));
        $this->assertSame('slot_taken', $this->code('2026-10-24T07:50:00-05:00', ['duration_minutes' => 60], $this->member('C')));
        $this->assertNull($this->code('2026-10-24T07:45:00-05:00', ['duration_minutes' => 60], $this->member('D')));

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $this->rec)->json('data');
        $this->assertNotContains('11:00', array_column($s['slots'], 'local_start'), '11:00 queda a 0 min de la de 10:15–11:15');
    }

    public function test_el_socio_no_puede_estar_en_dos_valoraciones_a_la_vez(): void
    {
        $this->everyDay($this->andres);
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($sofia);
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00'));
        $this->book($this->rec, $this->laura, $sofia, '2026-10-24T09:30:00-05:00')
            ->assertStatus(409)->assertJsonPath('code', 'member_overlap');
        $this->book($this->rec, $this->laura, $sofia, '2026-10-24T10:00:00-05:00')->assertCreated();
    }

    public function test_una_cancelada_libera_la_hora(): void
    {
        $this->everyDay($this->andres);
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00')->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Cambio de planes'], $this->rec)->assertOk();
        $this->assertNull($this->code('2026-10-24T09:00:00-05:00', [], $this->member('Nueva')));
    }

    // ── 3, 4, 5. Pasado, antelación, horizonte ─────────────────────────────

    public function test_antelacion_minima(): void
    {
        $this->everyDay($this->andres);
        $this->settings(['min_notice_minutes' => 120]);
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-19", $this->rec)->json('data');
        $this->assertSame('10:00', $s['slots'][0]['local_start'], 'ahora 08:00 + 120 min');
        $this->assertSame('too_soon', $this->code('2026-10-19T09:30:00-05:00'));
        $this->assertNull($this->code('2026-10-19T10:00:00-05:00'));
    }

    public function test_horizonte(): void
    {
        $this->everyDay($this->andres);
        $this->settings(['max_horizon_days' => 5]);
        $this->assertNull($this->code('2026-10-24T20:00:00-05:00'), 'hoy + 5 días, incluido');
        $this->assertSame('beyond_horizon', $this->code('2026-10-25T07:00:00-05:00', [], $this->member('Lejos')));
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-25", $this->rec)->json('data');
        $this->assertSame([], $s['slots']);
        $this->assertNotSame([], $s['windows'], 'la franja existe; lo que falta es horizonte');
    }

    public function test_pasado_sin_permiso_es_in_past_y_con_manage_settings_y_motivo_se_registra(): void
    {
        $this->everyDay($this->andres);
        $this->assertSame('in_past', $this->code('2026-10-19T07:00:00-05:00'));
        $this->assertSame('in_past', $this->code('2026-10-19T07:00:00-05:00', ['allow_past' => true, 'past_reason' => 'Se hizo y no se registró']), 'recepción no tiene manage_settings');

        $root = $this->h($this->superAdmin());
        $this->book($root, $this->laura, $this->andres, '2026-10-19T07:00:00-05:00', ['allow_past' => true])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $r = $this->book($root, $this->laura, $this->andres, '2026-10-19T07:00:00-05:00', ['allow_past' => true, 'past_reason' => 'Se hizo y no se registró'])
            ->assertCreated();

        $cita = $this->appointment($r->json('data.uuid'));
        $ev = ValuationAppointmentEvent::where('appointment_id', $cita->id)->firstOrFail();
        $this->assertSame('Se hizo y no se registró', $ev->reason);
        $this->assertTrue($ev->metadata['allow_past']);
        $this->assertSame(0, ValuationReminder::where('appointment_id', $cita->id)->count(), 'una cita pasada no avisa a nadie');
        $this->assertContains('complete', $r->json('data.allowed_actions'));
    }

    // ── Huecos ─────────────────────────────────────────────────────────────

    public function test_huecos_sin_nombres_de_socios_y_exclude_para_reprogramar(): void
    {
        $this->everyDay($this->andres, '08:00', '12:00');
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['notes' => 'Nota privada'])->json('data.uuid');

        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24&duration_minutes=60", $this->rec)->assertOk()->json('data');
        $this->assertSame('America/Bogota', $s['timezone']);
        $this->assertSame(['08:00', '10:00', '10:30', '11:00'], array_column($s['slots'], 'local_start'));
        $this->assertSame(['starts_at', 'local_start', 'local_end'], array_keys($s['slots'][0]));
        $this->assertSame('2026-10-24T13:00:00+00:00', $s['slots'][0]['starts_at']);
        $json = json_encode($s, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Laura', $json);
        $this->assertStringNotContainsString('Nota privada', $json);
        $this->assertStringNotContainsString($u, $json);

        $con = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24&exclude={$u}", $this->rec)->json('data');
        $this->assertContains('09:00', array_column($con['slots'], 'local_start'));
        $this->assertSame([], $con['busy']);
    }

    public function test_huecos_de_hoy_empiezan_despues_de_ahora(): void
    {
        $this->everyDay($this->andres, '06:00', '12:00');
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-19", $this->rec)->json('data');
        $this->assertSame('08:00', $s['slots'][0]['local_start'], 'ahora son las 08:00 en Bogotá');
    }

    // ── Gestión de la disponibilidad ───────────────────────────────────────

    public function test_sustituir_reglas_entero_validado_y_sin_solapes(): void
    {
        $gestor = $this->h($this->staffWith([ValuationAccess::MANAGE_AVAILABILITY]));
        $this->rule($this->andres, 2, '06:00', '08:00');

        $r = $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '15:00', 'valid_from' => '2026-11-01'],
            ['weekday' => 6, 'start_time' => '08:00', 'end_time' => '11:00', 'active' => false],
        ]], $gestor)->assertOk();
        $this->assertCount(3, $r->json('data.rules'));
        $this->assertSame(['id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_until', 'active'], array_keys($r->json('data.rules.0')));
        $this->assertSame(0, ValuationAvailabilityRule::where('weekday', 2)->count(), 'el conjunto se sustituye entero');

        // Solape el mismo día y vigencia → 422 rules_overlap, sin tocar lo que había.
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '13:00'],
        ]], $gestor)->assertStatus(422)->assertJsonPath('code', 'rules_overlap');
        $this->assertSame(3, ValuationAvailabilityRule::count());

        // Vigencias que no se cruzan sí pueden repetir horario.
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '12:00', 'valid_until' => '2026-10-31'],
            ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '13:00', 'valid_from' => '2026-11-01'],
        ]], $gestor)->assertOk();

        // Forma: fin antes del inicio, día 8.
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => [
            ['weekday' => 8, 'start_time' => '07:00', 'end_time' => '06:00'],
        ]], $gestor)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_bloqueo_sobre_citas_activas_exige_force_y_no_las_cancela(): void
    {
        $this->everyDay($this->andres);
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00')->json('data.uuid');
        $gestor = $this->h($this->staffWith([ValuationAccess::MANAGE_AVAILABILITY]));
        $cuerpo = ['starts_at' => '2026-10-24T08:00:00-05:00', 'ends_at' => '2026-10-24T13:00:00-05:00', 'kind' => 'day_off', 'reason' => 'Calamidad'];

        $r = $this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", $cuerpo, $gestor)->assertStatus(409);
        $this->assertSame('block_overlaps_appointments', $r->json('code'));
        $this->assertSame($u, $r->json('appointments.0.uuid'));
        $this->assertStringNotContainsString('Laura', json_encode($r->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, ValuationAvailabilityBlock::count());

        $r = $this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", $cuerpo + ['force' => true], $gestor)->assertCreated();
        $this->assertSame($u, $r->json('overlapping_appointments.0.uuid'));
        $this->assertSame('scheduled', $this->appointment($u)->status, 'el bloqueo no cancela la cita');

        $id = $r->json('data.id');
        $lista = $this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks?from=2026-10-20T00:00:00-05:00&to=2026-10-30T00:00:00-05:00", $gestor)->assertOk()->json('data');
        $this->assertSame([$id], array_column($lista, 'id'));
        $this->assertSame(1, count($this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", $gestor)->json('data.blocks_next_60_days')));

        $this->deleteJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks/{$id}", [], $gestor)->assertOk();
        $this->assertSame(0, ValuationAvailabilityBlock::count());
        $this->deleteJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks/{$id}", [], $gestor)->assertStatus(404);
    }

    public function test_bloqueo_invalido(): void
    {
        $gestor = $this->h($this->staffWith([ValuationAccess::MANAGE_AVAILABILITY]));
        $this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", [
            'starts_at' => '2026-10-24T13:00:00-05:00', 'ends_at' => '2026-10-24T08:00:00-05:00', 'kind' => 'break',
        ], $gestor)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", [
            'starts_at' => '2026-10-24T08:00:00-05:00', 'ends_at' => '2026-10-24T13:00:00-05:00', 'kind' => 'vacaciones',
        ], $gestor)->assertStatus(422);
        $this->postJson('/api/admin/valuations/trainers/999999/blocks', [
            'starts_at' => '2026-10-24T08:00:00-05:00', 'ends_at' => '2026-10-24T13:00:00-05:00', 'kind' => 'break',
        ], $gestor)->assertStatus(404);
    }

    // ── Lista ──────────────────────────────────────────────────────────────

    public function test_lista_por_rango_con_filtros_y_rango_maximo(): void
    {
        $this->everyDay($this->andres);
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($sofia);
        $a = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00')->json('data.uuid');
        $b = $this->book($this->rec, $this->member('Pedro'), $sofia, '2026-10-24T09:00:00-05:00', ['type' => 'follow_up'])->json('data.uuid');
        $c = $this->book($this->rec, $this->laura, $sofia, '2026-11-02T09:00:00-05:00')->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$b}/cancel", ['expected_version' => 1, 'reason' => 'Ya no viene'], $this->rec)->assertOk();

        $l = fn (string $q) => array_column($this->getJson('/api/admin/valuations?'.$q, $this->rec)->assertOk()->json('data'), 'uuid');
        $this->assertSame([$a, $b], $l('from=2026-10-24T00:00:00-05:00&to=2026-10-25T00:00:00-05:00'));
        $this->assertSame([$a], $l('from=2026-10-24T00:00:00-05:00&to=2026-10-25T00:00:00-05:00&status=scheduled,confirmed'));
        $this->assertSame([$b], $l('from=2026-10-24T00:00:00-05:00&to=2026-10-25T00:00:00-05:00&type=follow_up'));
        $this->assertSame([$a, $c], $l('member_id='.$this->laura->id), 'historial del socio sin rango');
        $this->assertSame([$b, $c], $l('trainer_id='.$sofia->id));

        // Las fechas en UTC: la de las 09:00 de Bogotá (14:00 UTC) cae en un rango UTC.
        $this->assertSame([$a, $b], $l('from=2026-10-24T13:59:00Z&to=2026-10-24T14:01:00Z'));

        $this->getJson('/api/admin/valuations?from=2026-10-01T00:00:00-05:00&to=2026-12-15T00:00:00-05:00', $this->rec)
            ->assertStatus(422)->assertJsonPath('code', 'range_too_wide');
        $this->getJson('/api/admin/valuations', $this->rec)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson('/api/admin/valuations?member_id=1&status=borrada', $this->rec)->assertStatus(422);

        $p = $this->getJson('/api/admin/valuations?member_id='.$this->laura->id.'&per_page=1', $this->rec)->json('meta');
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2], $p);
    }

    public function test_entrenadores_visibles(): void
    {
        $this->everyDay($this->andres);
        $this->trainer('Baja', ['status' => 'inactive']);
        $sin = $this->trainer('Sin Reglas');

        $lista = $this->getJson('/api/admin/valuations/trainers', $this->rec)->assertOk()->json('data');
        $this->assertSame(['Andrés Pérez', 'Sin Reglas'], array_column($lista, 'name'));
        $this->assertSame(['id', 'name', 'has_availability', 'location'], array_keys($lista[0]));
        $this->assertTrue($lista[0]['has_availability']);
        $this->assertFalse($lista[1]['has_availability']);
        $this->assertSame($sin->id, $lista[1]['id']);
    }

    public function test_reglas_y_settings_no_tocan_citas_existentes(): void
    {
        $this->everyDay($this->andres);
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00')->json('data.uuid');
        $root = $this->h($this->superAdmin());
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => []], $root)->assertOk();
        $this->assertSame('scheduled', $this->appointment($u)->status);
        $this->assertSame(1, ValuationAppointment::count());
        $this->assertInstanceOf(Admin::class, Admin::first());
    }
}
