<?php

namespace Tests\Feature\Valuations;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\User;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Revisión de BD B1. Con la FK del socio en RESTRICT, dar de baja a un socio
 * que tuvo alguna valoración (aunque estuviera cancelada) respondía 500 DESPUÉS
 * de borrar sus ficheros, avisar y auditar «Dio de baja»: el socio quedaba vivo
 * sin documento, firma ni rostro. Decisión: la FK del socio es CASCADE, como la
 * de `physical_evaluations` (los datos del socio se van con él), y con la cita
 * se van su historial y sus avisos. La del ENTRENADOR sigue en RESTRICT.
 *
 * Por los endpoints reales, sin tocar los controladores.
 */
class ValuationMemberDeletionTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Laura con una cita activa y otra cancelada (con sus eventos y avisos), y
     * Pedro con una suya que no puede verse afectada.
     *
     * @return array{0: Trainer, 1: Member, 2: list<int>, 3: int}
     */
    private function agendaConSocios(): array
    {
        $this->settings(['member_app_enabled' => true]);
        $andres = $this->trainer('Andrés Pérez');
        $this->everyDay($andres);
        $laura = $this->member('Laura Gómez');
        $pedro = $this->member('Pedro Ruiz');
        $rec = $this->reception();

        $activa = $this->book($rec, $laura, $andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $cancelada = $this->book($rec, $laura, $andres, '2026-10-25T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$cancelada}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $rec)->assertOk();
        $dePedro = $this->book($rec, $pedro, $andres, '2026-10-24T11:00:00-05:00')->assertCreated()->json('data.uuid');

        $deLaura = [$this->appointment($activa)->id, $this->appointment($cancelada)->id];
        $this->assertSame('cancelled', $this->appointment($cancelada)->status);
        $this->assertGreaterThan(0, ValuationAppointmentEvent::whereIn('appointment_id', $deLaura)->count());
        $this->assertGreaterThan(0, ValuationReminder::whereIn('appointment_id', $deLaura)->count());

        return [$andres, $laura, $deLaura, $this->appointment($dePedro)->id];
    }

    /** Huella de la cita de Pedro con su historial y sus avisos. */
    private function huellaDe(int $citaId): string
    {
        return md5(json_encode([
            DB::table('valuation_appointments')->where('id', $citaId)->get()->all(),
            DB::table('valuation_appointment_events')->where('appointment_id', $citaId)->orderBy('id')->get()->all(),
            DB::table('valuation_reminders')->where('appointment_id', $citaId)->orderBy('id')->get()->all(),
        ]));
    }

    private function sinRastro(Member $socio, array $citas): void
    {
        $this->assertNull(Member::query()->withoutGlobalScopes()->find($socio->id), 'el socio se borró');
        $this->assertNull(User::query()->find($socio->user_id), 'y su cuenta');
        $this->assertSame(0, ValuationAppointment::where('member_id', $socio->id)->count(), 'sus citas, activas y canceladas');
        $this->assertSame(0, ValuationAppointmentEvent::whereIn('appointment_id', $citas)->count(), 'su historial');
        $this->assertSame(0, ValuationReminder::whereIn('appointment_id', $citas)->count(), 'sus avisos');
    }

    public function test_dar_de_baja_a_un_socio_con_citas_responde_como_antes_y_se_lleva_su_agenda(): void
    {
        [$andres, $laura, $deLaura, $dePedro] = $this->agendaConSocios();
        $huellaPedro = $this->huellaDe($dePedro);
        $root = $this->h($this->superAdmin());

        // «Como antes»: lo mismo que con un socio que nunca tuvo una cita.
        $sinCitas = $this->member('Juan Sin Citas');
        $antes = $this->deleteJson("/api/users/{$sinCitas->user_id}", [], $root);
        $antes->assertNoContent();

        $r = $this->deleteJson("/api/users/{$laura->user_id}", [], $root);
        $r->assertNoContent();
        $this->assertSame($antes->getContent(), $r->getContent());

        $this->sinRastro($laura, $deLaura);
        $this->assertSame($huellaPedro, $this->huellaDe($dePedro), 'la agenda de otro socio no se toca');
        $this->assertNotNull($andres->fresh(), 'el entrenador sigue');
    }

    public function test_descartar_un_alta_desde_el_crm_tambien_se_lleva_sus_citas(): void
    {
        [, $laura, $deLaura, $dePedro] = $this->agendaConSocios();
        $huellaPedro = $this->huellaDe($dePedro);

        $this->deleteJson("/api/admin/members/{$laura->id}", [], $this->h($this->superAdmin()))->assertNoContent();

        $this->sinRastro($laura, $deLaura);
        $this->assertSame($huellaPedro, $this->huellaDe($dePedro));
    }

    /**
     * El entrenador NO va en cascada: borrar su fila con citas lo impide la
     * base, aunque alguien se salte la guarda 409 de TrainerController.
     */
    public function test_el_entrenador_sigue_en_restrict(): void
    {
        [$andres, , $deLaura] = $this->agendaConSocios();

        try {
            DB::transaction(fn () => DB::table('trainers')->where('id', $andres->id)->delete());
            $this->fail('la FK del entrenador tenía que impedir el borrado');
        } catch (QueryException $e) {
            $this->assertContains((string) ($e->errorInfo[0] ?? $e->getCode()), ['23503', '23000'], 'violación de FK');
        }

        $this->assertNotNull($andres->fresh());
        $this->assertSame(2, ValuationAppointment::whereIn('id', $deLaura)->count());
    }
}
