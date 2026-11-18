<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Jobs\SendAutomationEventToN8n;
use App\Models\AutomationEvent;
use App\Models\Member;
use App\Models\MemberValuationFollowUp;
use App\Models\Trainer;
use App\Models\TrainerTask;
use App\Models\ValuationAppointment;
use App\Services\TrainerTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * EL DETECTOR `evaluation.outdated` (contrato §7) con el comando real
 * `ironbody:detect-evaluation-outdated` (→ `emit-automation-events`).
 *
 * Solo emite a quien le toca según el seguimiento: membresía vigente,
 * seguimiento activo, próximo control ≤ hoy y sin cita futura. Una vez por
 * socio y FECHA DE VENCIMIENTO, con el tipo y la forma de siempre (los lee el
 * enrutador de n8n). Las tareas del entrenador: una abierta por socio.
 *
 * Reloj: lunes 23-11-2026, 09:00 de Bogotá (la hora del cron).
 */
class FollowUpDetectorTest extends TestCase
{
    use RefreshDatabase;
    use SeguimientoFixtures;

    private Trainer $andres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-23 09:00');
        config(['automation.enabled' => false]);
        $this->andres = $this->trainer('Andrés Pérez');
        $this->everyDay($this->andres);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function detectar(): void
    {
        $this->artisan('ironbody:detect-evaluation-outdated')->assertSuccessful();
    }

    /** @return array<int, AutomationEvent> member_id → evento */
    private function eventos(): array
    {
        return AutomationEvent::query()->where('event_type', 'evaluation.outdated')->orderBy('id')->get()->keyBy('member_id')->all();
    }

    private function valorado(string $nombre, string $cuando, ?string $finMembresia = '2027-01-31'): Member
    {
        $m = $this->member($nombre, $finMembresia);
        $this->valoracion($m, $this->andres, $cuando);

        return $m;
    }

    public function test_solo_emite_a_quien_le_toca(): void
    {
        $vencido = $this->valorado('Vencido', '2026-09-01 10:00');                   // 31-10 ≤ hoy
        $justo = $this->valorado('Justo hoy', '2026-09-24 10:00');                   // 23-11 = hoy
        $alDia = $this->valorado('Al día', '2026-11-01 10:00');                      // 31-12
        $manana = $this->valorado('Mañana', '2026-09-25 10:00');                     // 24-11
        $pausado = $this->valorado('Pausado', '2026-08-01 10:00');
        MemberValuationFollowUp::create(['member_id' => $pausado->id, 'enabled' => false, 'version' => 1]);
        $conCita = $this->valorado('Con cita', '2026-08-01 10:00');
        $this->book($this->reception(), $conCita, $this->andres, '2026-11-25T10:00:00-05:00')->assertCreated();
        $citaVieja = $this->valorado('Cita pasada sin cerrar', '2026-08-01 10:00');
        $this->cita($citaVieja, $this->andres, '2026-11-20 10:00', ValuationAppointment::STATUS_SCHEDULED);
        $vencidaMembresia = $this->valorado('Membresía vencida', '2026-08-01 10:00', '2026-11-01');
        $sinMembresia = $this->valorado('Sin membresía', '2026-08-01 10:00', null);
        $inactiva = $this->valorado('Cuenta inactiva', '2026-08-01 10:00');
        $inactiva->user->forceFill(['status' => 'inactive'])->save();
        $nunca = $this->member('Nunca valorado');
        $fechaPuntual = $this->member('Fecha puntual sin valoración');
        MemberValuationFollowUp::create(['member_id' => $fechaPuntual->id, 'enabled' => true, 'due_on' => '2026-11-23', 'version' => 1]);
        $completada = $this->member('Cita completada vieja');
        $this->cita($completada, $this->andres, '2026-09-10 08:00');                   // 09-11

        $this->detectar();
        $ev = $this->eventos();

        $this->assertEqualsCanonicalizing(
            [$vencido->id, $justo->id, $citaVieja->id, $fechaPuntual->id, $completada->id],
            array_keys($ev),
        );
        foreach ([$alDia, $manana, $pausado, $conCita, $vencidaMembresia, $sinMembresia, $inactiva, $nunca] as $no) {
            $this->assertArrayNotHasKey($no->id, $ev, "{$no->full_name} no debía recibir evaluation.outdated");
        }

        // Clave por socio y fecha de vencimiento; forma de siempre.
        $this->assertSame("evaluation.outdated:{$vencido->id}:2026-10-31", $ev[$vencido->id]->idempotency_key);
        $this->assertSame("evaluation.outdated:{$justo->id}:2026-11-23", $ev[$justo->id]->idempotency_key);
        $this->assertSame("evaluation.outdated:{$completada->id}:2026-11-09", $ev[$completada->id]->idempotency_key);
        $this->assertSame(
            ['member' => ['id' => $vencido->id, 'name' => 'Vencido'], 'evaluation' => ['has_any' => true]],
            $ev[$vencido->id]->payload_json,
        );
        $this->assertSame(
            ['member' => ['id' => $fechaPuntual->id, 'name' => 'Fecha puntual sin valoración'], 'evaluation' => ['has_any' => false]],
            $ev[$fechaPuntual->id]->payload_json,
        );
        $this->assertSame(AutomationEvent::STATUS_SKIPPED, $ev[$vencido->id]->status, 'n8n apagado en la prueba');
    }

    public function test_dos_ejecuciones_no_duplican_y_un_nuevo_vencimiento_si_emite(): void
    {
        $socio = $this->valorado('Vencido', '2026-09-01 10:00', '2027-12-31');

        $this->detectar();
        $this->detectar();
        $this->assertSame(1, AutomationEvent::query()->where('event_type', 'evaluation.outdated')->count());

        // La semana siguiente, sigue vencido con la MISMA fecha: nada nuevo
        // (antes era uno al mes).
        $this->clock('2026-11-30 09:00');
        $this->detectar();
        $this->clock('2026-12-07 09:00');
        $this->detectar();
        $this->assertSame(1, AutomationEvent::query()->where('event_type', 'evaluation.outdated')->count());

        // Se valora y vuelve a vencer: otra fecha, otro evento.
        $this->valoracion($socio, $this->andres, '2026-12-07 10:00');
        $this->clock('2027-02-08 09:00');
        $this->detectar();
        $this->assertSame(
            ["evaluation.outdated:{$socio->id}:2026-10-31", "evaluation.outdated:{$socio->id}:2027-02-05"],
            AutomationEvent::query()->where('event_type', 'evaluation.outdated')->orderBy('id')->pluck('idempotency_key')->all(),
        );
    }

    public function test_tareas_del_entrenador_una_abierta_por_socio(): void
    {
        $socio = $this->valorado('Vencido', '2026-09-01 10:00');
        $this->assign($socio, $this->andres);
        $sinEntrenador = $this->valorado('Sin entrenador', '2026-09-01 10:00');

        $this->detectar();
        $this->detectar();
        $tareas = TrainerTask::query()->where('type', 'evaluation.outdated')->get();
        $this->assertCount(1, $tareas, 'una tarea, para el socio con entrenador');
        $this->assertSame($socio->id, (int) $tareas[0]->member_id);
        $this->assertSame(TrainerTask::STATUS_PENDING, $tareas[0]->status);
        $this->assertSame(0, TrainerTask::query()->where('member_id', $sinEntrenador->id)->count());

        // Al día siguiente (fuera de la ventana anti-spam de 12 h, que si no
        // taparía la regla), una nueva fecha de vencimiento → nuevo evento,
        // pero la tarea sigue abierta (vista, no hecha): no se crea otra.
        app(TrainerTaskService::class)->markSeen((int) $tareas[0]->id);
        MemberValuationFollowUp::create(['member_id' => $socio->id, 'enabled' => true, 'due_on' => '2026-11-22', 'version' => 1]);
        $this->clock('2026-11-24 09:00');
        $this->detectar();
        $this->assertSame(2, AutomationEvent::query()->where('member_id', $socio->id)->where('event_type', 'evaluation.outdated')->count());
        $this->assertSame(1, TrainerTask::query()->where('member_id', $socio->id)->where('type', 'evaluation.outdated')->count());

        // Cerrada la tarea, el siguiente vencimiento sí crea una.
        app(TrainerTaskService::class)->markDone((int) $tareas[0]->id);
        MemberValuationFollowUp::query()->where('member_id', $socio->id)->update(['due_on' => '2026-11-21']);
        $this->clock('2026-11-25 09:00');
        $this->detectar();
        $this->assertSame(2, TrainerTask::query()->where('member_id', $socio->id)->where('type', 'evaluation.outdated')->count());
        $this->assertSame(1, TrainerTask::query()->where('member_id', $socio->id)->where('type', 'evaluation.outdated')
            ->whereIn('status', [TrainerTask::STATUS_PENDING, TrainerTask::STATUS_SEEN])->count());
    }

    public function test_los_demas_tipos_de_tarea_no_cambian(): void
    {
        $socio = $this->member('Laura Gómez');
        $this->assign($socio, $this->andres);
        $servicio = app(TrainerTaskService::class);

        $primera = $servicio->createForTrainer($this->andres->id, $socio->id, 'workout.missed', 'Alumno sin entrenar', 'x');
        $this->assertSame('created', $primera['status']);
        // Pasada la ventana anti-spam, otra del mismo tipo aunque la anterior
        // siga pendiente: como antes.
        $this->clock('2026-11-24 09:00');
        $this->assertSame('created', $servicio->createForTrainer($this->andres->id, $socio->id, 'workout.missed', 'Alumno sin entrenar', 'x')['status']);

        // evaluation.outdated, en cambio, responde «ya existe» con la abierta.
        $a = $servicio->createForTrainer($this->andres->id, $socio->id, 'evaluation.outdated', 'Evaluación física pendiente', 'x');
        $this->clock('2026-11-25 09:00');
        $b = $servicio->createForTrainer($this->andres->id, $socio->id, 'evaluation.outdated', 'Evaluación física pendiente', 'x');
        $this->assertSame(['created', 'skipped_duplicate'], [$a['status'], $b['status']]);
        $this->assertSame($a['task']->id, $b['task']->id);
    }

    public function test_gimnasio_sin_seguimiento_solo_emite_fechas_puntuales(): void
    {
        $this->settings(['follow_up_interval_days' => null]);
        $sinIntervalo = $this->valorado('Viejo sin intervalo', '2026-01-01 10:00');
        $propio = $this->valorado('Con intervalo propio', '2026-10-01 10:00');
        MemberValuationFollowUp::create(['member_id' => $propio->id, 'enabled' => true, 'interval_days' => 30, 'version' => 1]);

        $this->detectar();
        $this->assertSame([$propio->id], array_keys($this->eventos()));
    }

    public function test_con_n8n_encendido_se_despacha_como_siempre(): void
    {
        Queue::fake();
        config(['automation.enabled' => true]);
        $socio = $this->valorado('Vencido', '2026-09-01 10:00');

        $this->detectar();

        $ev = $this->eventos()[$socio->id];
        $this->assertSame(AutomationEvent::STATUS_PENDING, $ev->status);
        Queue::assertPushed(SendAutomationEventToN8n::class, 1);
    }
}
