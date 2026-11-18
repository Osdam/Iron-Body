<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Models\Member;
use App\Models\Notification;
use App\Models\PhysicalEvaluation;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONTRATO_CICLO_VALORACIONES.md §2: `valuations:link-assessments` concilia el
 * histórico con la MISMA regla del envío. Ensayo por defecto (no escribe),
 * `--apply` sin avisos e idempotente, informe por categoría sin nombres.
 *
 * El histórico se fabrica como era antes de este ciclo: citas agendadas por la
 * agenda real y valoraciones enviadas que no tocaron ninguna cita (filas de
 * `professional_assessments` con su `submitted_at`, como las de producción).
 */
class ReconciliacionHistoricaTest extends TestCase
{
    use CicloFixtures;
    use RefreshDatabase;

    private Trainer $t1;

    private Trainer $t2;

    /** @var array<string, string> nombre del caso → uuid de la valoración */
    private array $v = [];

    /** @var array<string, string> nombre del caso → uuid de la cita */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->t1 = $this->coach('Carlos Pérez');
        $this->t2 = $this->coach('Ana Rojas');
        $rec = $this->reception();
        $m = fn (string $n): Member => $this->member($n);

        // A: una cita del mismo entrenador y DOS valoraciones ese día.
        $a = $m('Socio A');
        $this->c['A'] = $this->schedule($rec, $a, $this->t1, '2026-10-20T09:00:00-05:00');
        $this->v['A1'] = $this->historica($a, $this->t1, '2026-10-20 09:20');
        $this->v['A2'] = $this->historica($a, $this->t1, '2026-10-20 09:40');

        // B: dos citas ese día.
        $b = $m('Socio B');
        $this->schedule($rec, $b, $this->t1, '2026-10-21T09:00:00-05:00');
        $this->schedule($rec, $b, $this->t1, '2026-10-21T15:00:00-05:00');
        $this->v['B'] = $this->historica($b, $this->t1, '2026-10-21 09:30');

        // C: la cita era con otro entrenador.
        $c = $m('Socio C');
        $this->c['C'] = $this->schedule($rec, $c, $this->t2, '2026-10-21T10:00:00-05:00');
        $this->v['C'] = $this->historica($c, $this->t1, '2026-10-21 10:20');

        // D: la cita ya la completó un administrador.
        $d = $m('Socio D');
        $this->c['D'] = $this->schedule($rec, $d, $this->t1, '2026-10-22T09:00:00-05:00');
        $this->v['D'] = $this->historica($d, $this->t1, '2026-10-22 09:30');

        // E: la cita de ese día se canceló.
        $e = $m('Socio E');
        $this->c['E'] = $this->schedule($rec, $e, $this->t1, '2026-10-22T10:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$this->c['E']}/cancel", ['expected_version' => 1, 'reason' => 'Viaje imprevisto'], $rec)->assertOk();
        $this->v['E'] = $this->historica($e, $this->t1, '2026-10-22 10:30');

        // F: valoración un día SIN cita (la tiene otro día): no sale en el informe.
        $f = $m('Socio F');
        $this->schedule($rec, $f, $this->t1, '2026-10-23T11:00:00-05:00');
        $this->v['F'] = $this->historica($f, $this->t1, '2026-10-20 12:00');

        // G: raíz corregida después (otro día): cuenta la raíz, no la enmienda.
        $g = $m('Socio G');
        $this->c['G'] = $this->schedule($rec, $g, $this->t1, '2026-10-23T09:00:00-05:00');
        $this->v['G'] = $this->historica($g, $this->t1, '2026-10-23 09:10', ProfessionalAssessment::STATUS_AMENDED);
        $raiz = ProfessionalAssessment::where('uuid', $this->v['G'])->sole();
        $this->v['G2'] = $this->historica($g, $this->t1, '2026-10-25 10:00', ProfessionalAssessment::STATUS_SUBMITTED, ['parent_id' => $raiz->id, 'version' => 2, 'amendment_reason' => 'Corrige']);

        // H: un borrador el día de una cita: no cuenta.
        $h = $m('Socio H');
        $this->c['H'] = $this->schedule($rec, $h, $this->t1, '2026-10-23T10:00:00-05:00');
        ProfessionalAssessment::create(['member_id' => $h->id, 'trainer_id' => $this->t1->id, 'status' => 'draft', 'version' => 1]);

        $this->clock('2026-10-22 09:05');
        $this->postJson("/api/admin/valuations/{$this->c['D']}/complete", ['expected_version' => 1], $this->h($this->superAdmin()))->assertOk();

        $this->clock('2026-10-30 08:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una valoración enviada ANTES de este ciclo: ninguna cita la conoce. */
    private function historica(Member $m, Trainer $t, string $bogota, string $estado = ProfessionalAssessment::STATUS_SUBMITTED, array $extra = []): string
    {
        return ProfessionalAssessment::create(array_merge([
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'status' => $estado,
            'version' => 1,
            'weight_kg' => 70,
            'submitted_at' => Carbon::parse($bogota, 'America/Bogota')->utc(),
        ], $extra))->uuid;
    }

    private function correr(array $opciones = []): array
    {
        $codigo = Artisan::call('valuations:link-assessments', $opciones + ['--json' => true]);
        $this->assertSame(0, $codigo);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Todo lo que el comando podría escribir, para comparar antes y después. */
    private function foto(): array
    {
        return [
            'citas' => DB::table('valuation_appointments')->orderBy('id')
                ->get(['id', 'status', 'version', 'professional_assessment_id', 'completed_at', 'outcome_by_admin_id'])->map(fn ($r) => (array) $r)->all(),
            'eventos' => ValuationAppointmentEvent::count(),
            'avisos' => ValuationReminder::orderBy('id')->pluck('status', 'id')->all(),
            'notificaciones' => Notification::count(),
            'valoraciones' => ProfessionalAssessment::count(),
            'evaluaciones' => PhysicalEvaluation::count(),
        ];
    }

    private function porValoracion(array $informe): array
    {
        return collect($informe['rows'])->mapWithKeys(fn ($f) => [array_search($f['assessment_uuid'], $this->v, true) => $f['category']])->all();
    }

    public function test_el_ensayo_informa_por_categoria_y_no_escribe_nada(): void
    {
        $antes = $this->foto();
        $informe = $this->correr();

        $this->assertSame($antes, $this->foto(), 'el ensayo no escribe');
        $this->assertSame('dry_run', $informe['mode']);
        $this->assertSame(['linked' => 2, 'ambiguous' => 1, 'different_trainer' => 1, 'already_closed' => 2, 'cancelled' => 1], $informe['counts']);
        // En orden de envío. A2 sale `already_closed`: el ensayo simula que A1
        // ya cerró la cita. F (sin cita ese día), la enmienda y el borrador no salen.
        $this->assertSame([
            'A1' => 'linked', 'A2' => 'already_closed', 'B' => 'ambiguous', 'C' => 'different_trainer',
            'D' => 'already_closed', 'E' => 'cancelled', 'G' => 'linked',
        ], $this->porValoracion($informe));

        // Sin nombres ni documentos: uuids, fechas y categorías.
        $crudo = json_encode($informe, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Socio', $crudo);
        $this->assertStringNotContainsString('Pérez', $crudo);
        $fila = collect($informe['rows'])->firstWhere('assessment_uuid', $this->v['A1']);
        $this->assertSame(['date' => '2026-10-20', 'assessment_uuid' => $this->v['A1'], 'appointment_uuid' => $this->c['A'], 'candidates' => 1, 'category' => 'linked', 'applied' => false], $fila);
    }

    public function test_aplicar_enlaza_con_la_fecha_real_sin_avisos_y_es_idempotente(): void
    {
        $notificaciones = Notification::count();
        $informe = $this->correr(['--apply' => true]);
        $this->assertSame('apply', $informe['mode']);
        $this->assertSame(['linked' => 2, 'ambiguous' => 1, 'different_trainer' => 1, 'already_closed' => 2, 'cancelled' => 1], $informe['counts']);

        $a1 = ProfessionalAssessment::where('uuid', $this->v['A1'])->sole();
        $cita = $this->appointment($this->c['A']);
        $this->assertSame(['completed', 2, (int) $a1->id], [$cita->status, $cita->version, (int) $cita->professional_assessment_id]);
        $this->assertSame('2026-10-20 14:20:00', $cita->getRawOriginal('completed_at'), 'submitted_at de la valoración, no ahora');
        $evento = ValuationAppointmentEvent::where('appointment_id', $cita->id)->where('action', 'completed')->sole();
        $this->assertNull($evento->actor_admin_id);
        $this->assertSame('Carlos Pérez', $evento->actor_name);
        $this->assertSame(['source', 'assessment_uuid', 'reconciled_at'], array_keys($evento->metadata));
        $this->assertSame('historical_reconciliation', $evento->metadata['source']);
        $this->assertSame($this->v['A1'], $evento->metadata['assessment_uuid']);
        $this->assertSame('2026-10-30T13:00:00+00:00', $evento->metadata['reconciled_at']);
        $this->assertSame(0, ValuationReminder::where('appointment_id', $cita->id)->whereIn('status', ['pending', 'error'])->count(), 'avisos pendientes anulados');

        // G: la RAÍZ cierra la cita, no la enmienda.
        $raiz = ProfessionalAssessment::where('uuid', $this->v['G'])->sole();
        $this->assertSame((int) $raiz->id, (int) $this->appointment($this->c['G'])->professional_assessment_id);
        $this->assertSame('2026-10-23 14:10:00', $this->appointment($this->c['G'])->getRawOriginal('completed_at'));

        // Lo demás, intacto; y ningún aviso a nadie.
        $this->assertSame('scheduled', $this->appointment($this->c['C'])->status);
        $this->assertSame('scheduled', $this->appointment($this->c['H'])->status);
        $this->assertNull($this->appointment($this->c['D'])->professional_assessment_id);
        $this->assertSame($notificaciones, Notification::count(), 'sin avisos al CRM ni al socio');
        $this->assertSame(0, PhysicalEvaluation::count(), 'no crea medidas');

        // Segunda pasada: nada que hacer.
        $antes = $this->foto();
        $otra = $this->correr(['--apply' => true]);
        $this->assertSame($antes, $this->foto(), 'idempotente');
        $this->assertSame(['linked' => 0, 'ambiguous' => 1, 'different_trainer' => 1, 'already_closed' => 4, 'cancelled' => 1], $otra['counts']);
        $this->assertSame($this->c['A'], collect($otra['rows'])->firstWhere('assessment_uuid', $this->v['A1'])['appointment_uuid']);
    }

    public function test_la_salida_de_tabla_dice_el_modo(): void
    {
        $this->artisan('valuations:link-assessments')
            ->expectsOutputToContain('MODO: ENSAYO')
            ->expectsOutputToContain('Se enlazarían: 2 citas.')
            ->assertSuccessful();
        $this->assertSame('scheduled', $this->appointment($this->c['A'])->status);
    }
}
