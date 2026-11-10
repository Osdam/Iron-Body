<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\TriggerPrefilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #7 de PUNTA A PUNTA (contrato H9, D9-3 `class.upcoming_with_capacity`
 * y `valuation.outdated`, D9-5 «un prefiltro no decide», L9-29; REV9 M-3,
 * TEST9-c): el barrido parte de las OCURRENCIAS con cupo (nunca de la base
 * entera), el prefiltro acota a los socios activos sin esa reserva, y la
 * puerta inscribe solo al segmento que la audiencia y las exclusiones dibujan:
 * quien ya tiene una reserva próxima o una valoración próxima no entra; un
 * congelado, un vencido, un socio sin plan o un prospecto nunca son
 * candidatos; quien reserva entre planificar y despachar sale `converted` sin
 * contacto. Los hechos de la agenda están en `AgendaEngineHistoryFactsTest`;
 * aquí van con el barrido y el despacho en sombra.
 */
class H9EventSegmentTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    /** Martes 17-11-2026, 10:00 de Bogotá: la clase del miércoles 18-11 cae en la ventana. */
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

    /** Un socio con membresía vigente (vence en 30 días), su prospecto de WhatsApp autorizado, conversación y número canario. @return array{user: int, member: int, key: string, lead: int} */
    private function activeMember(string $phone, array $user = []): array
    {
        $r = $this->expiringMember(self::utcAt(self::NOW), 30, $user);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $this->canary('+57'.$phone, 'whatsapp');

        return $r + ['lead' => $lead];
    }

    /** La invitación: el segmento (activos, sin congelar, sin reserva próxima), la exclusión (valoración próxima) y el éxito (reservó). @return array<string, mixed> */
    private function classContent(int $withinDays = 7): array
    {
        return $this->validContent($this->assistedTemplate(), [
            'goal' => 'increase_adherence',
            'trigger' => ['event' => 'class.upcoming_with_capacity', 'params' => ['withinDays' => $withinDays]],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
                ['fact' => 'class.reserved_next_7d', 'op' => 'eq', 'value' => false],
            ]],
            'exclusions' => ['fact' => 'valuation.has_upcoming', 'op' => 'eq', 'value' => true],
            'success' => ['fact' => 'class.reserved_next_7d', 'op' => 'eq', 'value' => true],
        ]);
    }

    public function test_7_la_invitacion_a_una_clase_con_cupo_inscribe_solo_al_segmento_elegible_y_nunca_a_toda_la_base(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->classContent());
        $spinning = $this->klass(['type' => 'Spinning', 'day_of_week' => 'Miércoles', 'max_capacity' => 3]);  // 18-11, con cupo
        $yoga = $this->klass(['type' => 'Yoga', 'day_of_week' => 'Jueves', 'max_capacity' => 1]);              // 19-11, llena
        $this->klass(['type' => 'Pilates', 'day_of_week' => 'Lunes', 'status' => 'inactive']);                 // inactiva
        $entrenador = $this->trainer();

        $elegible = $this->activeMember('3001234561');
        $conReserva = $this->activeMember('3001234562');
        $this->reservation($spinning, $conReserva['member'], '2026-11-18');     // ya tiene ESA reserva: el prefiltro lo deja fuera
        $otraReserva = $this->activeMember('3001234563');
        $this->reservation($yoga, $otraReserva['member'], '2026-11-19');        // otra clase en 7 días (y la llena): fuera de la audiencia
        $conValoracion = $this->activeMember('3001234564');
        $this->valuation($conValoracion['member'], $entrenador, 'scheduled', '2026-11-19 15:00:00');  // valoración próxima: excluido
        $this->activeMember('3001234565', ['membership_frozen_at' => '2026-11-10']);                  // congelado
        $this->qualifyingMember(self::utcAt(self::NOW), -1, '3001234566');                             // vencido ayer
        $sinPlan = $this->activeMember('3001234567');
        DB::table('users')->where('id', $sinPlan['user'])->update(['plan' => null]);                   // sin plan
        $prospecto = $this->lead(['phone' => '3001234568']);                                           // sin ficha de socio

        // La fuente del disparador son las ocurrencias con cupo: solo el Spinning del 18-11 (el Yoga está lleno, el Pilates inactivo).
        $pagina = (new TriggerPrefilters)->page(['event' => 'class.upcoming_with_capacity', 'params' => ['withinDays' => 7]], now());
        $this->assertNotNull($pagina);
        $this->assertEquals([['class_id' => $spinning, 'class_session_id' => null, 'session_date' => '2026-11-18']], array_values(array_unique(array_map(static fn ($c): array => $c->cycleParts, $pagina['candidates']), SORT_REGULAR)));
        $this->assertEqualsCanonicalizing([$elegible['key'], $otraReserva['key'], $conValoracion['key']], array_map(static fn ($c): string => $c->subjectKey, $pagina['candidates']), 'activos sin esa reserva; nunca la base entera');

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(3, $r['candidates']);
        $this->assertSame(3, $r['evaluated']);
        $this->assertSame(1, $r['enrolled']);
        $this->assertSame(1, $r['not_in_audience'], 'la reserva próxima en otra clase');
        $this->assertSame(1, $r['excluded'], 'la valoración próxima');
        $inscripciones = DB::table('outreach_enrollments')->get();
        $this->assertCount(1, $inscripciones, 'ocho personas sembradas, una inscrita');
        $e = $inscripciones[0];
        $this->assertSame($elegible['key'], $e->subject_key);
        $this->assertSame($elegible['member'], (int) $e->member_id);
        $this->assertSame("cls:{$spinning}:2026-11-18", $e->cycle_key, 'L9-29: la clase y la fecha (keyVersion 2)');
        $this->assertSame(0, DB::table('outreach_enrollments')->where('subject_key', 'like', SubjectKey::lead($prospecto))->count());
        $this->assertSame(2, OutreachCatalog::event('class.upcoming_with_capacity')['keyVersion']);

        $r = $planner->planDue(now());
        $this->assertSame(1, $r['planned'], json_encode($r));
        $this->assertSame('simulated', DB::table('outreach_jobs')->value('channel'));
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame('not_attempted', DB::table('outreach_outcomes')->value('outcome'));
        $this->assertSame(1, DB::table('outreach_jobs')->count());
        // La misma ocurrencia otra vez: ya inscrita; nadie más entra.
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(1, $r['already_enrolled']);
        $this->assertSame(0, $r['enrolled']);
    }

    public function test_7_quien_reserva_entre_planificar_y_despachar_sale_converted_sin_contacto_y_deja_de_ser_candidato(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->classContent());
        $spinning = $this->klass(['type' => 'Spinning', 'day_of_week' => 'Miércoles', 'max_capacity' => 3]);
        $socio = $this->activeMember('3001234561');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);

        $this->reservation($spinning, $socio['member'], '2026-11-18');
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $this->assertSame(['converted' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $trabajo->status);
        $this->assertSame('success_reached', $trabajo->cancel_reason);
        $this->assertSame('completed', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame('converted', DB::table('outreach_outcomes')->value('outcome'));
        $this->assertSame(0, DB::table('outreach_outcomes')->where('source', 'simulated')->count(), 'el canal no se llamó');
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(0, $r['candidates'], 'con la reserva ya no es candidato de esa ocurrencia');
    }

    public function test_7_valuation_outdated_inscribe_a_quien_tiene_la_valoracion_caducada_y_no_a_quien_tiene_una_proxima_o_ninguna(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'book_assessment',
            'trigger' => ['event' => 'valuation.outdated', 'params' => ['days' => 30]],
            'audience' => ['all' => [['fact' => 'membership.active', 'op' => 'eq', 'value' => true], ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false]]],
            'exclusions' => ['fact' => 'valuation.has_upcoming', 'op' => 'eq', 'value' => true],
            'success' => ['fact' => 'valuation.days_since_last', 'op' => 'lt', 'value' => 30],
        ]));
        $entrenador = $this->trainer();
        $caducada = $this->activeMember('3001234561');
        $this->valuation($caducada['member'], $entrenador, 'completed', '2026-10-08 15:00:00', '2026-10-08 16:00:00');   // hace 40 días
        $reciente = $this->activeMember('3001234562');
        $this->valuation($reciente['member'], $entrenador, 'completed', '2026-11-07 15:00:00', '2026-11-07 16:00:00');   // hace 10
        $conCita = $this->activeMember('3001234563');
        $this->valuation($conCita['member'], $entrenador, 'completed', '2026-10-01 15:00:00', '2026-10-01 16:00:00');
        $this->valuation($conCita['member'], $entrenador, 'confirmed', '2026-11-20 15:00:00');                           // próxima
        $this->activeMember('3001234564');                                                                                 // nunca: desconocido, no entra (DF-K3)

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(1, $r['candidates']);
        $this->assertSame(1, $r['enrolled']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame($caducada['key'], $e->subject_key);
        $cita = (int) DB::table('valuation_appointments')->where('member_id', $caducada['member'])->value('id');
        $this->assertSame('val:'.$cita, $e->cycle_key);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
    }
}
