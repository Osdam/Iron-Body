<?php

namespace Tests\Feature\Classes;

use App\Models\CatalogEvent;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Services\ClassRenewalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La renovación de clases fijas ({@see ClassRenewalService}, comando
 * `classes:renew`, cada hora).
 *
 * Una sesión VENCE cuando el entrenador la cerró hace al menos `renewal_hours`
 * horas. El CORTE de una clase es la fecha de su última sesión vencida: se
 * borran las reservas hasta esa fecha (y las heredadas sin fecha) y se archivan
 * (`renewed_at`) sus sesiones cerradas hasta esa fecha. Lo posterior al corte
 * no se toca.
 *
 * Antes se archivaban TODAS las sesiones cerradas de la clase, también la que
 * el entrenador acababa de cerrar: esa ya no vencía nunca, sus reservas se
 * quedaban y la app dejaba de verla cerrada. `enrolled_count` contaba todas
 * las reservas que sobrevivían, de cualquier semana. Y el aviso salía como una
 * fila por socio activo, sin hecho para el CRM ni aviso al entrenador.
 */
class ClassRenewalTest extends TestCase
{
    use RefreshDatabase;

    private int $doc = 720300100;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    /** Hora de pared de Bogotá como instante UTC, igual que en producción. */
    private function ahora(string $bogota): void
    {
        Carbon::setTestNow($this->instante($bogota));
    }

    /** Un datetime se guarda como instante UTC: así lo escribe producción. */
    private function instante(string $bogota): Carbon
    {
        return Carbon::parse($bogota, 'America/Bogota')->utc();
    }

    private function renovar(): int
    {
        return app(ClassRenewalService::class)->renewDue();
    }

    private function entrenador(): Trainer
    {
        $doc = (string) $this->doc++;

        return Trainer::create(['full_name' => 'Coach '.$doc, 'document' => $doc, 'status' => 'active']);
    }

    /** Por defecto, una clase de los lunes de 18:00 a 19:00 que renueva cada semana. */
    private function clase(array $extra = [], ?Trainer $coach = null): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional', 'trainer_id' => $coach?->id,
            'day_of_week' => 'Lunes', 'start_time' => '18:00', 'end_time' => '19:00',
            'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true,
            'is_recurring' => true, 'renewal_hours' => 168,
        ], $extra));
    }

    private function socio(): Member
    {
        $doc = (string) $this->doc++;

        return Member::create([
            'full_name' => 'Socio '.$doc, 'document_number' => $doc, 'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    /**
     * La sesión de esa fecha. Inicio, cierre y renovación son horas de pared de
     * Bogotá con su fecha; `null` = no ocurrió.
     */
    private function sesion(MyClass $clase, string $fecha, ?string $inicio, ?string $cierre, ?string $renovada = null): ClassSession
    {
        return ClassSession::create([
            'class_id' => $clase->id,
            'session_date' => $fecha,
            'started_at' => $inicio === null ? null : $this->instante($inicio),
            'ended_at' => $cierre === null ? null : $this->instante($cierre),
            'renewed_at' => $renovada === null ? null : $this->instante($renovada),
        ]);
    }

    /** Dictada ese día a su hora y cerrada al terminar. */
    private function dictada(MyClass $clase, string $fecha): ClassSession
    {
        return $this->sesion($clase, $fecha, "{$fecha} {$clase->start_time}", "{$fecha} {$clase->end_time}");
    }

    private function reservar(MyClass $clase, ?string $fecha, Member $socio): void
    {
        ClassReservation::create([
            'class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => $fecha, 'reserved_at' => now(),
        ]);
    }

    /** @return list<string|null> las fechas de las reservas que quedan (heredadas primero) */
    private function fechasReservadas(MyClass $clase): array
    {
        return ClassReservation::where('class_id', $clase->id)
            ->orderBy('session_date')
            ->orderBy('id')
            ->get()
            ->map(fn (ClassReservation $r) => $r->session_date?->toDateString())
            ->all();
    }

    /** La fila tal cual está en la base: para afirmar que algo «no se tocó». */
    private function fila(ClassSession $sesion): array
    {
        return (array) DB::table('class_sessions')->where('id', $sesion->id)->first();
    }

    private function inscritos(MyClass $clase): int
    {
        return (int) DB::table('classes')->where('id', $clase->id)->value('enrolled_count');
    }

    private function fijarInscritos(MyClass $clase, int $n): void
    {
        DB::table('classes')->where('id', $clase->id)->update(['enrolled_count' => $n]);
    }

    /** @return array<string, int> filas de cada destino de hechos y avisos */
    private function rastro(): array
    {
        return [
            'class_events' => ClassEvent::count(),
            'catalog_events' => CatalogEvent::count(),
            'trainer_realtime_events' => TrainerRealtimeEvent::count(),
            'member_realtime_events' => MemberRealtimeEvent::count(),
        ];
    }

    // ── Cuándo vence una sesión ─────────────────────────────────────────────

    /** @return array<string, array{0: int}> las ventanas que admite el CRM */
    public static function ventanas(): array
    {
        return [
            '8 horas' => [8],
            '12 horas' => [12],
            '24 horas' => [24],
            '48 horas' => [48],
            'semanal (168 horas)' => [168],
        ];
    }

    #[DataProvider('ventanas')]
    public function test_una_sesion_vence_al_cumplir_su_ventana_y_ni_un_minuto_antes(int $horas): void
    {
        $this->ahora('2026-10-05 19:30');
        $clase = $this->clase(['renewal_hours' => $horas]);
        $sesion = $this->dictada($clase, '2026-10-05');
        $this->reservar($clase, '2026-10-05', $this->socio());
        $vence = $this->instante('2026-10-05 19:00')->addHours($horas);

        Carbon::setTestNow($vence->copy()->subMinute());
        $this->assertSame(0, $this->renovar(), 'Un minuto antes de cumplir la ventana, todavía no vence.');
        $this->assertNull($sesion->fresh()->renewed_at);
        $this->assertSame(['2026-10-05'], $this->fechasReservadas($clase));

        Carbon::setTestNow($vence);
        $this->assertSame(1, $this->renovar(), 'Al cumplirla, vence.');
        $this->assertSame($vence->toDateTimeString(), $sesion->fresh()->renewed_at?->toDateTimeString());
        $this->assertSame([], $this->fechasReservadas($clase));
    }

    // ── El corte ────────────────────────────────────────────────────────────

    /**
     * Cómo puede estar la sesión de hoy cuando vence la del lunes pasado.
     *
     * @return array<string, array{0: string|null}> cierre de la sesión de hoy
     */
    public static function sesionesPosterioresAlCorte(): array
    {
        return [
            'recién cerrada, dentro de su ventana' => ['2026-10-05 19:00'],
            'todavía en curso' => [null],
        ];
    }

    #[DataProvider('sesionesPosterioresAlCorte')]
    public function test_solo_archiva_hasta_el_corte_y_la_sesion_posterior_no_se_toca(?string $cierreDeHoy): void
    {
        // Clase semanal que renueva cada semana: la sesión del lunes pasado
        // vence a la hora en que el entrenador cierra la de hoy. Pasa cada lunes.
        $this->ahora('2026-10-05 20:00');
        $clase = $this->clase();
        $pasada = $this->dictada($clase, '2026-09-28');
        $deHoy = $this->sesion($clase, '2026-10-05', '2026-10-05 18:00', $cierreDeHoy);
        $ana = $this->socio();
        $beto = $this->socio();
        $this->reservar($clase, '2026-09-28', $ana);
        $this->reservar($clase, '2026-09-28', $beto);
        $this->reservar($clase, '2026-10-05', $ana);
        $this->reservar($clase, '2026-10-05', $beto);
        $antes = $this->fila($deHoy);

        $this->assertSame(1, $this->renovar());

        $this->assertSame(now()->toDateTimeString(), $pasada->fresh()->renewed_at?->toDateTimeString(), 'La sesión del corte queda archivada.');
        $this->assertEquals($antes, $this->fila($deHoy), 'La de hoy es posterior al corte (28/09): ni se archiva ni se toca.');
        $this->assertSame(['2026-10-05', '2026-10-05'], $this->fechasReservadas($clase), 'Se borra el ciclo vencido; las de hoy siguen.');
    }

    public function test_el_corte_es_de_cada_clase(): void
    {
        // El programador estuvo parado unos días y se pone al día de una vez.
        $this->ahora('2026-10-05 10:00');
        $yoga = $this->clase(['name' => 'Yoga', 'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00']);
        $boxeo = $this->clase([
            'name' => 'Boxeo', 'day_of_week' => 'Viernes', 'start_time' => '19:00', 'end_time' => '20:00', 'renewal_hours' => 24,
        ]);
        $yogaVencida = $this->dictada($yoga, '2026-09-23');   // semanal: venció el 30/09 a las 20:00
        $yogaReciente = $this->dictada($yoga, '2026-09-30');  // vence el 07/10: aún no
        $boxeoVencida = $this->dictada($boxeo, '2026-10-02'); // 24 h: venció el 03/10
        $socio = $this->socio();
        $this->reservar($yoga, '2026-09-23', $socio);
        $this->reservar($yoga, '2026-09-30', $socio);
        $this->reservar($boxeo, '2026-10-02', $socio);
        $this->reservar($boxeo, '2026-10-09', $socio);
        $antes = $this->fila($yogaReciente);

        $this->assertSame(2, $this->renovar());

        $this->assertNotNull($yogaVencida->fresh()->renewed_at);
        $this->assertNotNull($boxeoVencida->fresh()->renewed_at);
        $this->assertEquals(
            $antes,
            $this->fila($yogaReciente),
            'El 30/09 es anterior al corte de Boxeo (02/10) pero posterior al de Yoga (23/09): no se toca.',
        );
        $this->assertSame(['2026-09-30'], $this->fechasReservadas($yoga));
        $this->assertSame(['2026-10-09'], $this->fechasReservadas($boxeo));
    }

    public function test_la_sesion_posterior_al_corte_se_renueva_cuando_cumple_su_ventana(): void
    {
        $this->ahora('2026-10-05 20:00');
        $clase = $this->clase();
        $this->dictada($clase, '2026-09-28');
        $delCinco = $this->dictada($clase, '2026-10-05');
        $ana = $this->socio();
        $this->reservar($clase, '2026-10-05', $ana);
        $this->reservar($clase, '2026-10-05', $this->socio());
        $this->reservar($clase, '2026-10-12', $ana);

        $this->renovar(); // vence la del 28/09; la del 05/10 lleva una hora cerrada

        $this->ahora('2026-10-12 20:00');
        $this->dictada($clase, '2026-10-12'); // la de este lunes, recién cerrada
        $segunda = $this->renovar();

        $this->assertSame(['2026-10-12'], $this->fechasReservadas($clase), 'Las reservas del 05/10 se limpian cuando su sesión vence.');
        $this->assertSame(1, $segunda);
        $this->assertSame(
            now()->toDateTimeString(),
            $delCinco->fresh()->renewed_at?->toDateTimeString(),
            'Se archiva en la corrida en que vence, no una semana antes.',
        );
    }

    public function test_tras_renovar_la_clase_cerrada_hoy_sigue_finalizada_en_la_app(): void
    {
        // Si la sesión de hoy se archivara junto con la del lunes pasado, la
        // app dejaría de verla: la clase saldría «programada» y con «Cancelar»,
        // y cancelar contestaría «La clase ya finalizó.».
        $this->ahora('2026-10-05 20:00');
        $clase = $this->clase();
        $this->dictada($clase, '2026-09-28');
        $this->dictada($clase, '2026-10-05');
        $socio = $this->givePlanWithClasses($this->socio());
        $this->reservar($clase, '2026-10-05', $socio);

        $this->assertSame(1, $this->renovar());

        $vista = collect($this->getJson('/api/app/classes', ['Authorization' => 'Bearer '.$socio->access_hash])->assertOk()->json('data'))
            ->firstWhere('id', (string) $clase->id);
        $this->assertSame('finished', $vista['session_status'], 'La de hoy sigue cerrada hasta que venza.');
        $this->assertFalse($vista['can_cancel']);
    }

    // ── enrolled_count ──────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: int}> [hora de Bogotá, cupo esperado] */
    public static function momentosDeLaCorrida(): array
    {
        return [
            // La ocurrencia operativa es la del lunes siguiente (19/10).
            'martes por la mañana' => ['2026-10-13 10:00', 2],
            // En Bogotá sigue siendo el lunes de la clase (12/10); en UTC ya es martes.
            'lunes de noche, ya martes en UTC' => ['2026-10-12 20:00', 3],
        ];
    }

    #[DataProvider('momentosDeLaCorrida')]
    public function test_enrolled_count_queda_con_el_cupo_de_la_ocurrencia_operativa(string $ahora, int $esperado): void
    {
        $this->ahora($ahora);
        $clase = $this->clase();
        $this->fijarInscritos($clase, 17);
        $this->dictada($clase, '2026-10-05'); // semanal: vence el 12/10 a las 19:00
        $a = $this->socio();
        $b = $this->socio();
        $c = $this->socio();
        // Ciclo vencido y heredada sin fecha: se borran.
        $this->reservar($clase, '2026-10-05', $a);
        $this->reservar($clase, '2026-10-05', $b);
        $this->reservar($clase, null, $c);
        // Sobreviven seis, de tres semanas distintas.
        foreach ([$a, $b, $c] as $socio) {
            $this->reservar($clase, '2026-10-12', $socio);
        }
        $this->reservar($clase, '2026-10-19', $a);
        $this->reservar($clase, '2026-10-19', $b);
        $this->reservar($clase, '2026-10-26', $a);

        $this->assertSame(1, $this->renovar());

        $this->assertSame(
            ['2026-10-12', '2026-10-12', '2026-10-12', '2026-10-19', '2026-10-19', '2026-10-26'],
            $this->fechasReservadas($clase),
        );
        $this->assertSame(
            $esperado,
            $this->inscritos($clase),
            'El cupo de UNA ocurrencia, la operativa en día de Bogotá; no las seis reservas que quedan.',
        );
    }

    // ── Hechos y avisos ─────────────────────────────────────────────────────

    /**
     * Tres clases de los lunes que renuevan cada 24 h, todas vencidas el martes
     * 06/10 a las 20:00; Ana dicta dos y Beto una. Tres socios activos reservaron
     * cada una.
     *
     * @return array{0: Trainer, 1: Trainer, 2: list<MyClass>}
     */
    private function tresClasesVencidas(): array
    {
        $this->ahora('2026-10-06 20:00');
        $ana = $this->entrenador();
        $beto = $this->entrenador();
        $clases = [
            $this->clase(['name' => 'Funcional AM', 'start_time' => '06:00', 'end_time' => '07:00', 'renewal_hours' => 24], $ana),
            $this->clase(['name' => 'Yoga', 'start_time' => '12:00', 'end_time' => '13:00', 'renewal_hours' => 24], $ana),
            $this->clase(['name' => 'Boxeo', 'start_time' => '18:00', 'end_time' => '19:00', 'renewal_hours' => 24], $beto),
        ];
        $socios = [$this->socio(), $this->socio(), $this->socio()];
        foreach ($clases as $clase) {
            $this->dictada($clase, '2026-10-05');
            foreach ($socios as $socio) {
                $this->reservar($clase, '2026-10-05', $socio);
            }
        }

        return [$ana, $beto, $clases];
    }

    public function test_cada_clase_renovada_deja_un_class_updated_del_sistema_con_motivo_renewal(): void
    {
        [, , $clases] = $this->tresClasesVencidas();

        $this->artisan('classes:renew')->expectsOutput('Clases renovadas: 3')->assertSuccessful()->run();

        $hechos = ClassEvent::where('type', 'class.updated')->orderBy('class_id')->get()
            ->map(fn (ClassEvent $e) => [
                'class_id' => $e->class_id, 'source' => $e->source, 'reason' => $e->reason, 'reservation_id' => $e->reservation_id,
            ])->all();
        $this->assertSame(
            array_map(fn (MyClass $c) => [
                'class_id' => $c->id, 'source' => 'system', 'reason' => 'renewal', 'reservation_id' => null,
            ], $clases),
            $hechos,
            'Un class.updated por clase renovada, del sistema y con motivo renewal.',
        );
    }

    public function test_los_socios_reciben_un_solo_aviso_global_aunque_renueven_varias_clases(): void
    {
        [$ana, $beto] = $this->tresClasesVencidas();

        $this->artisan('classes:renew')->expectsOutput('Clases renovadas: 3')->assertSuccessful()->run();

        $this->assertSame(
            ['catalog_events' => 1, 'member_realtime_events' => 0],
            ['catalog_events' => CatalogEvent::count(), 'member_realtime_events' => MemberRealtimeEvent::count()],
            'Tres clases renovadas: UN aviso en el canal global y ni una fila por socio.',
        );
        $global = CatalogEvent::sole();
        $this->assertSame('class.updated', $global->type, 'La app publicada enruta `class.*` a Clases.');
        $this->assertNull($global->class_id, 'Un aviso que cubre tres clases no puede decir que es de una sola.');
        $this->assertSame(['classes'], $global->changed);

        foreach ([$ana, $beto] as $coach) {
            $aviso = TrainerRealtimeEvent::where('trainer_id', $coach->id)->sole();
            $this->assertSame('class.updated', $aviso->type);
            $this->assertSame(['classes', 'class.updated'], $aviso->changed, 'Un aviso por entrenador, aunque renueven dos clases suyas.');
        }
    }

    public function test_en_una_corrida_mixta_solo_la_clase_renovada_deja_hecho_y_avisa_a_su_entrenador(): void
    {
        $this->ahora('2026-10-06 20:00');
        $ana = $this->entrenador();
        $beto = $this->entrenador();
        $renueva = $this->clase(['renewal_hours' => 24], $ana);
        $espera = $this->clase(['name' => 'Pilates', 'renewal_hours' => 48], $beto);
        $this->dictada($renueva, '2026-10-05');
        $cerradaAyer = $this->dictada($espera, '2026-10-05'); // 48 h: vence el 07/10 a las 19:00
        $socio = $this->socio();
        $this->reservar($renueva, '2026-10-05', $socio);
        $this->reservar($espera, '2026-10-05', $socio);
        $this->fijarInscritos($espera, 1);
        $antes = $this->fila($cerradaAyer);

        $this->assertSame(1, $this->renovar());

        $this->assertSame(0, ClassEvent::where('class_id', $espera->id)->count(), 'Pilates no renovó: no hay hecho suyo.');
        $this->assertSame(0, TrainerRealtimeEvent::where('trainer_id', $beto->id)->count(), 'Ni aviso a su entrenador.');
        $this->assertEquals($antes, $this->fila($cerradaAyer));
        $this->assertSame(['2026-10-05'], $this->fechasReservadas($espera));
        $this->assertSame(1, $this->inscritos($espera));

        $this->assertSame(1, ClassEvent::where('class_id', $renueva->id)->where('reason', 'renewal')->count(), 'La que renovó sí deja su hecho.');
        $this->assertSame(1, TrainerRealtimeEvent::where('trainer_id', $ana->id)->count(), 'Y avisa a su entrenadora.');
        $this->assertSame([], $this->fechasReservadas($renueva));
    }

    /**
     * Clases sin nada que renovar el martes 06/10 a las 20:00. La sesión es la
     * del lunes 05/10: [inicio, cierre, renovada].
     *
     * @return array<string, array{0: int|null, 1: list<string|null>|null}>
     */
    public static function nadaQueRenovar(): array
    {
        return [
            'sin ninguna sesión' => [24, null],
            'la sesión sigue en curso' => [24, ['2026-10-05 18:00', null]],
            'cerrada y dentro de su ventana' => [48, ['2026-10-05 18:00', '2026-10-05 19:00']],
            'ya renovada' => [24, ['2026-10-05 18:00', '2026-10-05 19:00', '2026-10-06 19:00']],
            'renovación manual (0 horas)' => [0, ['2026-10-05 18:00', '2026-10-05 19:00']],
            'renovación manual (sin horas)' => [null, ['2026-10-05 18:00', '2026-10-05 19:00']],
        ];
    }

    #[DataProvider('nadaQueRenovar')]
    public function test_una_clase_sin_nada_que_renovar_no_deja_hechos_ni_avisos(?int $horas, ?array $sesion): void
    {
        $this->ahora('2026-10-06 20:00');
        $clase = $this->clase(['renewal_hours' => $horas], $this->entrenador());
        $this->fijarInscritos($clase, 2);
        $creada = $sesion === null ? null : $this->sesion($clase, '2026-10-05', ...$sesion);
        $this->reservar($clase, '2026-10-05', $this->socio());
        $this->reservar($clase, null, $this->socio());
        $antes = $creada === null ? null : $this->fila($creada);

        $this->artisan('classes:renew')->expectsOutput('Clases renovadas: 0')->assertSuccessful()->run();

        $this->assertSame(
            ['class_events' => 0, 'catalog_events' => 0, 'trainer_realtime_events' => 0, 'member_realtime_events' => 0],
            $this->rastro(),
            'Sin renovación no hay hecho para el CRM ni aviso para nadie.',
        );
        $this->assertSame([null, '2026-10-05'], $this->fechasReservadas($clase), 'No se borra ninguna reserva.');
        $this->assertSame(2, $this->inscritos($clase));
        if ($creada !== null) {
            $this->assertEquals($antes, $this->fila($creada));
        }
    }

    // ── Repetición y fallos ─────────────────────────────────────────────────

    public function test_dos_sesiones_vencidas_de_la_misma_clase_se_renuevan_hasta_la_ultima(): void
    {
        // Dos lunes dictados y vencidos (ventana de 24 h) que ninguna corrida
        // renovó; el corte es la fecha de la ÚLTIMA sesión vencida.
        $this->ahora('2026-10-07 20:00');
        $clase = $this->clase(['renewal_hours' => 24], $this->entrenador());
        $vieja = $this->dictada($clase, '2026-09-28');
        $reciente = $this->dictada($clase, '2026-10-05');
        foreach (['2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12'] as $fecha) {
            $this->reservar($clase, $fecha, $this->socio());
        }

        $this->assertSame(1, $this->renovar(), 'Una clase renovada, aunque tuviera dos sesiones vencidas.');

        $this->assertNotNull($vieja->fresh()->renewed_at, 'También la anterior al corte.');
        $this->assertNotNull($reciente->fresh()->renewed_at);
        $this->assertSame(['2026-10-12'], $this->fechasReservadas($clase), 'Todo lo anterior al corte, incluida la reserva de hace dos semanas, se libera; la futura no.');
    }

    public function test_una_sesion_ya_renovada_no_se_vuelve_a_sellar_al_renovar_la_siguiente(): void
    {
        $this->ahora('2026-09-29 20:00');
        $clase = $this->clase(['renewal_hours' => 24], $this->entrenador());
        $primera = $this->dictada($clase, '2026-09-28');
        $this->assertSame(1, $this->renovar());
        $sello = $primera->fresh()->renewed_at?->toDateTimeString();
        $this->assertNotNull($sello);

        // Una semana después vence la siguiente sesión.
        $this->ahora('2026-10-06 20:00');
        $segunda = $this->dictada($clase, '2026-10-05');
        $this->assertSame(1, $this->renovar());

        $this->assertNotNull($segunda->fresh()->renewed_at);
        $this->assertSame($sello, $primera->fresh()->renewed_at?->toDateTimeString(), 'La ya renovada conserva su sello: la renovación es idempotente.');
    }

    public function test_una_segunda_corrida_no_vuelve_a_archivar_ni_a_avisar(): void
    {
        $this->ahora('2026-10-06 20:00');
        $clase = $this->clase(['renewal_hours' => 24], $this->entrenador());
        $sesion = $this->dictada($clase, '2026-10-05');
        $this->reservar($clase, '2026-10-05', $this->socio());
        $this->reservar($clase, '2026-10-12', $this->socio());

        $this->assertSame(1, $this->renovar());
        $archivada = $sesion->fresh()->renewed_at?->toDateTimeString();
        $rastro = $this->rastro();

        $this->ahora('2026-10-06 21:00'); // la corrida horaria siguiente
        $this->assertSame(0, $this->renovar());

        $this->assertSame($archivada, $sesion->fresh()->renewed_at?->toDateTimeString(), 'No se vuelve a sellar.');
        $this->assertSame($rastro, $this->rastro(), 'Ni hechos ni avisos nuevos.');
        $this->assertSame(['2026-10-12'], $this->fechasReservadas($clase));
    }

    public function test_si_la_renovacion_falla_a_mitad_no_se_borra_nada_ni_se_avisa(): void
    {
        $this->ahora('2026-10-06 20:00');
        $clase = $this->clase(['renewal_hours' => 24], $this->entrenador());
        $sesion = $this->dictada($clase, '2026-10-05');
        $this->reservar($clase, '2026-10-05', $this->socio());
        $this->reservar($clase, null, $this->socio());
        $this->fijarInscritos($clase, 2);
        $antes = $this->fila($sesion);
        // Archivar la sesión es lo último de la transacción: cuando falla ahí,
        // las reservas ya se habían borrado y el cupo recalculado.
        DB::unprepared(
            "CREATE TRIGGER renovacion_interrumpida BEFORE UPDATE ON class_sessions BEGIN SELECT RAISE(ABORT, 'renovacion interrumpida'); END",
        );

        try {
            $this->renovar();
            $this->fail('La renovación tenía que fallar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('renovacion interrumpida', $e->getMessage());
        }

        $this->assertSame([null, '2026-10-05'], $this->fechasReservadas($clase), 'Las reservas vuelven con el rollback.');
        $this->assertSame(2, $this->inscritos($clase));
        $this->assertEquals($antes, $this->fila($sesion));
        $this->assertSame(
            ['class_events' => 0, 'catalog_events' => 0, 'trainer_realtime_events' => 0, 'member_realtime_events' => 0],
            $this->rastro(),
            'No se anuncia una renovación que no ocurrió.',
        );
    }
}
