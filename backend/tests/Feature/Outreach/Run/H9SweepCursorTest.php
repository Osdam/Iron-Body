<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachRuns;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Outreach\Run\TriggerPrefilters;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * I-4 de la auditoría de cierre B, sobre las clases REALES (planificador,
 * prefiltros, pasadas): las pasadas sucesivas del barrido de una estrategia y
 * un disparador recorren su audiencia ENTERA, página a página, sin repetir a
 * nadie dentro de una vuelta y sin saltarse a nadie, y al acabar vuelven a
 * empezar. La posición vive en los recuentos de la última pasada `sweep`
 * completada (sin esquema nuevo). Una pasada que falla a mitad no la mueve,
 * y el freno de la estrategia y la cadencia tampoco.
 *
 * Páginas de 2 (`outreach.sweep.batch`). El martes 17-11-2026 a las 10:00 de
 * Bogotá (15:00Z).
 */
class H9SweepCursorTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una renovación a 7 días cuya audiencia deja FUERA a quien vence en menos de `$minDays` días. @return array<string, mixed> */
    private function renewal(int $minDays = 0): array
    {
        return $this->liveStrategy($this->validContent($this->assistedTemplate(), ['audience' => ['all' => [
            ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
            ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
            ['fact' => 'membership.days_to_expiry', 'op' => 'gte', 'value' => $minDays],
        ]]]));
    }

    /** Socios que vencen en 1, 2, … `$n` días, creados en ese orden (ids crecientes). @return list<array{user: int, member: int, key: string}> */
    private function members(int $n): array
    {
        $r = [];
        for ($i = 1; $i <= $n; $i++) {
            $r[] = $this->expiringMember(self::utcAt(self::NOW), $i);
        }

        return $r;
    }

    /** La última pasada `sweep` de la estrategia: su estado, sus recuentos y la posición que guardó. @return array{status: string, counts: array<string, mixed>} */
    private function lastSweep(array $vivo, string $mode = 'shadow'): array
    {
        $f = DB::table('outreach_runs')->where('kind', 'sweep')->where('strategy_id', $vivo['strategy'])->where('mode', $mode)->orderByDesc('id')->first(['status', 'counts']);

        return ['status' => (string) $f->status, 'counts' => json_decode((string) $f->counts, true) ?? []];
    }

    /** Recuentos de una pasada que importan aquí. @param  array<string, mixed>  $c */
    private static function pick(array $c): array
    {
        return array_intersect_key($c, array_flip(['candidates', 'evaluated', 'enrolled', 'not_in_audience', 'already_enrolled', 'more']));
    }

    private static function enrolledKeys(): array
    {
        return DB::table('outreach_enrollments')->orderBy('id')->pluck('subject_key')->all();
    }

    public function test_varias_paginas_se_recorren_enteras_y_los_negados_del_principio_ya_no_tapan_al_resto(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        // Los dos primeros (ids más bajos) vencen en 1 y 2 días: la audiencia (≥ 3) los deja fuera. Antes de I-4, cada
        // pasada volvía a ellos y nadie más se miraba nunca.
        $vivo = $this->renewal(3);
        $s = $this->members(5);
        $u = array_map(static fn (array $m): string => (string) $m['user'], $s);
        $planner = new OutreachPlanner;

        $esperado = [
            // [candidatos, evaluados, inscritos, fuera de audiencia, ya inscritos, más], desde, siguiente
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 0, 'not_in_audience' => 2, 'already_enrolled' => 0, 'more' => true], '', $u[1]],
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 2, 'not_in_audience' => 0, 'already_enrolled' => 0, 'more' => true], $u[1], $u[3]],
            [['candidates' => 1, 'evaluated' => 1, 'enrolled' => 1, 'not_in_audience' => 0, 'already_enrolled' => 0, 'more' => false], $u[3], ''],
            // Acabada la vuelta, vuelve a empezar por el principio.
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 0, 'not_in_audience' => 2, 'already_enrolled' => 0, 'more' => true], '', $u[1]],
            [['candidates' => 2, 'evaluated' => 0, 'enrolled' => 0, 'not_in_audience' => 0, 'already_enrolled' => 2, 'more' => true], $u[1], $u[3]],
        ];
        foreach ($esperado as $i => [$recuentos, $desde, $siguiente]) {
            $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
            $this->assertSame($recuentos, self::pick($r), "pasada {$i}");
            $this->assertArrayNotHasKey(OutreachRuns::SWEEP_CURSOR_FROM, $r, 'la posición (ids internos) no sale en el resumen');
            $this->assertArrayNotHasKey(OutreachRuns::SWEEP_CURSOR_NEXT, $r);
            $guardada = $this->lastSweep($vivo);
            $this->assertSame('completed', $guardada['status']);
            $this->assertSame([$desde, $siguiente], [$guardada['counts'][OutreachRuns::SWEEP_CURSOR_FROM], $guardada['counts'][OutreachRuns::SWEEP_CURSOR_NEXT]], "pasada {$i}");
            if ($i === 2) {
                // Al acabar la primera vuelta están todos los que califican, cada uno UNA vez: nadie saltado, nadie repetido.
                $this->assertSame([$s[2]['key'], $s[3]['key'], $s[4]['key']], self::enrolledKeys());
            }
        }
        $this->assertSame(3, DB::table('outreach_enrollments')->count());
        // Lo que enseñan la API y el panel: los recuentos sin la posición.
        $publicos = OutreachRuns::publicCounts($this->lastSweep($vivo)['counts']);
        $this->assertSame([], array_intersect_key($publicos, array_flip(OutreachRuns::PRIVATE_COUNT_KEYS)));
        $this->assertSame(self::pick($this->lastSweep($vivo)['counts']), self::pick($publicos), 'el resto de los recuentos, igual');
    }

    public function test_si_la_ultima_pagina_estaba_llena_la_pasada_que_la_encuentra_vacia_empieza_ya_la_vuelta_siguiente(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->renewal();
        $u = array_map(static fn (array $m): string => (string) $m['user'], $this->members(4));
        $planner = new OutreachPlanner;

        $planner->sweep(now(), true);
        $planner->sweep(now(), true);
        $this->assertSame([$u[1], $u[3]], [$this->lastSweep($vivo)['counts']['cursorFrom'], $this->lastSweep($vivo)['counts']['cursorNext']], 'la segunda página se llenó: todavía no se sabe que es la última');
        $this->assertSame(4, DB::table('outreach_enrollments')->count());

        // La tercera encuentra vacío lo que queda después del 4: no se pierde la pasada, empieza otra vuelta en ella misma.
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(['candidates' => 2, 'already_enrolled' => 2, 'more' => true], array_intersect_key($r, array_flip(['candidates', 'already_enrolled', 'more'])));
        $this->assertSame(['', $u[1]], [$this->lastSweep($vivo)['counts']['cursorFrom'], $this->lastSweep($vivo)['counts']['cursorNext']]);
    }

    public function test_sin_force_la_cadencia_no_mueve_la_posicion_y_despues_sigue_donde_iba(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->renewal();
        $s = $this->members(5);
        $planner = new OutreachPlanner;

        $planner->sweep(now());
        $this->assertSame((string) $s[1]['user'], $this->lastSweep($vivo)['counts']['cursorNext']);
        // Dentro de la cadencia (60 min) no hay pasada: ni recuentos ni posición nueva.
        $this->assertSame(['skipped' => 'cadence'], $planner->sweep(now())['strategies'][$vivo['strategyUuid']]);
        $this->assertSame(1, DB::table('outreach_runs')->where('kind', 'sweep')->count());

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(61));
        $r = $planner->sweep(now())['strategies'][$vivo['strategyUuid']];
        $this->assertSame(2, $r['enrolled'], 'la segunda página, no la primera otra vez');
        $this->assertSame([$s[0]['key'], $s[1]['key'], $s[2]['key'], $s[3]['key']], self::enrolledKeys());
    }

    public function test_el_freno_de_la_estrategia_no_mueve_la_posicion(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->renewal();
        $s = $this->members(5);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $freno = (new OutreachStop)->open('strategy', $vivo['strategyUuid'], 'manual', 'Freno de la prueba', $this->threePeople()['ana'], (string) Str::uuid());
        $this->assertSame(['skipped' => 'outreach_brake_strategy'], $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']]);
        $this->assertSame(1, DB::table('outreach_runs')->where('kind', 'sweep')->count(), 'frenada no abre pasada');
        $this->assertSame((string) $s[1]['user'], (new OutreachRuns)->sweepPosition($vivo['strategy'], 'membership.expiring_window', 'shadow'));

        (new OutreachStop)->release($this->threePeople()['beto'], (string) $freno['brake']['uuid'], 'Suelta de la prueba', (string) Str::uuid());
        $this->assertSame(2, $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']]['enrolled']);
        $this->assertSame([$s[0]['key'], $s[1]['key'], $s[2]['key'], $s[3]['key']], self::enrolledKeys());
    }

    public function test_una_excepcion_a_mitad_no_avanza_la_posicion_y_la_pagina_se_repite_sin_inscribir_dos_veces(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->renewal();
        $s = $this->members(5);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $despuesDeLaPrimera = (string) $s[1]['user'];

        // La inscripción del 4.º socio (segundo de la página 2) falla en la base: el 3.º ya quedó inscrito.
        $this->failEnrollmentOf($s[3]['key']);
        try {
            $planner->sweep(now(), true);
            $this->fail('La pasada tenía que fallar.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $fallida = $this->lastSweep($vivo);
        $this->assertSame('failed', $fallida['status']);
        $this->assertArrayNotHasKey(OutreachRuns::SWEEP_CURSOR_NEXT, $fallida['counts'], 'una pasada fallida no guarda posición');
        $this->assertSame($despuesDeLaPrimera, (new OutreachRuns)->sweepPosition($vivo['strategy'], 'membership.expiring_window', 'shadow'), 'el cursor no avanza en falso');
        $this->assertSame([$s[0]['key'], $s[1]['key'], $s[2]['key']], self::enrolledKeys());

        // Arreglado, la siguiente repite la página 2: el 3.º ya estaba (no se inscribe dos veces) y el 4.º entra.
        $this->restoreEnrollments();
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame([2, 1, 1], [$r['candidates'], $r['already_enrolled'], $r['enrolled']]);
        $this->assertSame([$despuesDeLaPrimera, (string) $s[3]['user']], [$this->lastSweep($vivo)['counts']['cursorFrom'], $this->lastSweep($vivo)['counts']['cursorNext']]);
        $planner->sweep(now(), true);
        $this->assertSame(array_column($s, 'key'), self::enrolledKeys(), 'todos, una vez cada uno');
        $this->assertSame(5, DB::table('outreach_enrollments')->distinct()->count('subject_key'));
    }

    public function test_la_posicion_es_por_modo(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->renewal();
        $s = $this->members(5);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->sweep(now(), true);
        $this->assertSame((string) $s[3]['user'], (new OutreachRuns)->sweepPosition($vivo['strategy'], 'membership.expiring_window', 'shadow'));

        // En vivo la vuelta es otra: empieza por el principio y no toca la de sombra.
        $this->runFlags('live', ['outreach.sweep.batch' => 2]);
        $planner->sweep(now(), true);
        $this->assertSame(['', (string) $s[1]['user']], [$this->lastSweep($vivo, 'live')['counts']['cursorFrom'], $this->lastSweep($vivo, 'live')['counts']['cursorNext']]);
        $this->assertSame((string) $s[3]['user'], (new OutreachRuns)->sweepPosition($vivo['strategy'], 'membership.expiring_window', 'shadow'));
    }

    public function test_las_clases_se_recorren_por_ocurrencia_y_persona_sin_repetir_ni_saltar(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'increase_adherence',
            'trigger' => ['event' => 'class.upcoming_with_capacity', 'params' => ['withinDays' => 7]],
            'audience' => ['all' => [['fact' => 'membership.active', 'op' => 'eq', 'value' => true], ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false]]],
            'success' => ['fact' => 'class.reserved_next_7d', 'op' => 'eq', 'value' => true],
        ]));
        $miercoles = $this->klass(['type' => 'Spinning', 'day_of_week' => 'Miércoles', 'max_capacity' => 10]);   // 18-11
        $jueves = $this->klass(['type' => 'Yoga', 'day_of_week' => 'Jueves', 'max_capacity' => 10]);           // 19-11
        $u = array_map(static fn (array $m): int => $m['user'], [$this->expiringMember(self::utcAt(self::NOW), 30), $this->expiringMember(self::utcAt(self::NOW), 30), $this->expiringMember(self::utcAt(self::NOW), 30)]);
        $trigger = ['event' => 'class.upcoming_with_capacity', 'params' => ['withinDays' => 7]];

        // El recorrido de los prefiltros, página a página: seis pares (ocurrencia, persona), cada uno una vez por vuelta.
        $vistos = [];
        $posicion = '';
        $paginas = [];
        do {
            $p = (new TriggerPrefilters)->pageFrom($trigger, now(), $posicion, 2);
            foreach ($p['candidates'] as $c) {
                $vistos[] = $c->cycleParts['session_date'].'#'.$c->cursor;
            }
            $paginas[] = $p['next'];
            $posicion = $p['next'];
        } while ($posicion !== '' && count($paginas) < 10);
        $esperados = [];
        foreach (['2026-11-18', '2026-11-19'] as $fecha) {
            foreach ($u as $id) {
                $esperados[] = "{$fecha}#{$id}";
            }
        }
        $this->assertSame($esperados, $vistos, 'en orden de (fecha, clase, persona), sin repetidos ni huecos');
        $this->assertSame(["2026-11-18:{$miercoles}:{$u[1]}", "2026-11-19:{$jueves}:{$u[0]}", "2026-11-19:{$jueves}:{$u[2]}", ''], $paginas);

        // Y el barrido sigue las mismas posiciones; la pasada que encuentra vacío el final empieza ya la vuelta siguiente.
        $planner = new OutreachPlanner;
        $vueltas = [];
        foreach (range(1, 4) as $i) {
            $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
            $c = $this->lastSweep($vivo)['counts'];
            $vueltas[] = [$r['candidates'], $c['cursorFrom'], $c['cursorNext']];
        }
        $this->assertSame([
            [2, '', "2026-11-18:{$miercoles}:{$u[1]}"],
            [2, "2026-11-18:{$miercoles}:{$u[1]}", "2026-11-19:{$jueves}:{$u[0]}"],
            [2, "2026-11-19:{$jueves}:{$u[0]}", "2026-11-19:{$jueves}:{$u[2]}"],
            [2, '', "2026-11-18:{$miercoles}:{$u[1]}"],
        ], $vueltas);
        $this->assertSame(3, DB::table('outreach_enrollments')->count(), 'una inscripción abierta por persona (la de su primera ocurrencia)');
    }

    public function test_una_posicion_ilegible_empieza_por_el_principio(): void
    {
        $trigger = ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 7]];
        $s = $this->members(3);
        foreach (['basura', '-5', '0', '007', '2026-11-18:1:2', str_repeat('9', 30)] as $rota) {
            $p = (new TriggerPrefilters)->pageFrom($trigger, now(), $rota, 2);
            $this->assertSame([$s[0]['key'], $s[1]['key']], array_map(static fn ($c): string => $c->subjectKey, $p['candidates']), "posición {$rota}");
        }
        $clase = (new TriggerPrefilters)->pageFrom(['event' => 'class.upcoming_with_capacity', 'params' => ['withinDays' => 7]], now(), '12345', 2);
        $this->assertSame(['candidates' => [], 'next' => ''], $clase, 'sin clases: vacío, y una posición de otro disparador empieza por el principio');
        $this->assertNull((new TriggerPrefilters)->pageFrom(['event' => 'lead_created'], now(), ''), 'los crm_fact no tienen prefiltro');
    }

    /** Hace fallar en la base la inscripción de una persona (SQLite o PostgreSQL); lo deshace la transacción de la prueba. */
    private function failEnrollmentOf(string $subjectKey): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION zz_falla_inscripcion() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.subject_key = '{$subjectKey}' THEN RAISE EXCEPTION 'falla de la prueba'; END IF; RETURN NEW; END \$\$;");
            DB::unprepared('CREATE TRIGGER zz_falla_inscripcion BEFORE INSERT ON outreach_enrollments FOR EACH ROW EXECUTE FUNCTION zz_falla_inscripcion();');

            return;
        }
        DB::unprepared("CREATE TRIGGER zz_falla_inscripcion BEFORE INSERT ON outreach_enrollments WHEN NEW.subject_key = '{$subjectKey}' BEGIN SELECT RAISE(ABORT, 'falla de la prueba'); END;");
    }

    private function restoreEnrollments(): void
    {
        DB::unprepared('DROP TRIGGER zz_falla_inscripcion'.(DB::getDriverName() === 'pgsql' ? ' ON outreach_enrollments; DROP FUNCTION zz_falla_inscripcion();' : ';'));
    }
}
