<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\MarketingAiAction;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * M-5 de security (`informe-security-quejas.md`): la BANDEJA ASISTIDA vuelve a
 * mirar la queja (N6 `complaint_recent`) DESPUÉS de que el trabajo salió.
 * Sobre las clases REALES de la ejecución y con el emisor real de la queja
 * (`MarketingAiActionCommercialObserver`):
 *
 *  - un trabajo asistido de una persona que se quejó después de salir deja de
 *    ofrecerse y NO se puede reclamar (409 `not_in_queue`); sin datos de
 *    eventos, tampoco (falla cerrado, como N6);
 *  - quien YA lo reclamó lo sigue viendo y registra su resultado, pero sin
 *    el enlace a la ficha ni el guion (`contactWithheld`, I-1 de
 *    `informe-security-bandeja.md`; lo prueba a fondo H9AssistedConditionsTest);
 *  - `human_requested` NO retira nada (L9-66: una derivada a persona sigue
 *    viva en la bandeja);
 *  - la bandeja no escribe: el trabajo y la inscripción quedan como estaban;
 *  - una página lee las quejas con UNA consulta, tenga las filas que tenga
 *    (también la vista `mine`, que desde I-1 vuelve a mirar lo reclamado).
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), en vivo, con la fuente
 * de eventos encendida (L9-26), como el resto de B9-run.
 */
class H9AssistedComplaintTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Una estrategia viva y un trabajo asistido que SALIÓ en vivo por cada teléfono (uno por persona).
     *
     * @param  list<string>  $phones
     * @return list<array{member: array<string, mixed>, job: object}>
     */
    private function startedFor(array $phones): array
    {
        $ahora = self::utcAt(self::NOW);
        $this->runFlags('live');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socios = [];
        foreach ($phones as $telefono) {
            $socios[] = $this->qualifyingMember($ahora, 5, $telefono);
        }
        $planner = new OutreachPlanner;
        $planner->sweep($ahora, true);
        $this->assertSame(count($phones), $planner->planDue($ahora)['planned'] ?? 0);
        Carbon::setTestNow(Carbon::instance($ahora)->addMinutes(15));
        $this->assertSame(['started' => count($phones)], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);

        return array_map(static fn (array $s): array => [
            'member' => $s,
            'job' => DB::table('outreach_jobs')->where('subject_key', $s['key'])->first(),
        ], $socios);
    }

    /** La queja que marca la IA en la conversación del prospecto, por el emisor REAL. */
    private function complaintFromAi(int $leadId): void
    {
        MarketingAiAction::create([
            'lead_id' => $leadId,
            'conversation_id' => (int) DB::table('marketing_conversations')->where('lead_id', $leadId)->value('id'),
            'action_type' => 'staff_review', 'reason' => 'complaint', 'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ]);
    }

    private function assertClaimWithheld(AssistedQueue $cola, string $jobUuid, string $why): void
    {
        try {
            $cola->claim($this->threePeople()['beto'], $jobUuid, (string) Str::uuid());
            $this->fail("Se reclamó un trabajo que la bandeja tenía que retener ({$why}).");
        } catch (OutreachException $e) {
            $this->assertSame([409, 'not_in_queue'], [$e->status, $e->errorCode], $why);
        }
    }

    /** Lo que la bandeja NO puede escribir al retener: nada de lo que hay en estas tablas cambia. @return array<string, mixed> */
    private static function rowsSnapshot(): array
    {
        return [
            'jobs' => DB::table('outreach_jobs')->orderBy('id')->get(['id', 'status', 'cancel_reason', 'assisted_claimed_by_admin_id', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
            'enrollments' => DB::table('outreach_enrollments')->orderBy('id')->get(['id', 'status', 'exit_reason', 'next_due_at', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
            'decisions' => DB::table('outreach_decisions')->count(),
            'outcomes' => DB::table('outreach_outcomes')->count(),
            'budgets' => DB::table('outreach_budget_counters')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_la_queja_que_llega_despues_de_salir_retira_el_trabajo_de_la_bandeja_y_no_se_puede_reclamar(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;

        // L9-66: la persona pidió hablar con alguien y la inscripción está derivada; el trabajo asistido ES ese contacto: sigue.
        $this->commercialEvent('human_requested', '2026-11-17 15:05:00', $p['member']['member']);
        $this->assertTrue((new OutreachStore)->closeEnrollment((int) $p['job']->enrollment_id, 'handed_off', 'needs_review', self::utcAt('2026-11-17 15:06:00')));
        $this->assertSame([$uuid], array_column($cola->queue($gente['ana']), 'uuid'), 'human_requested no retira el trabajo (L9-66)');
        $this->assertSame([$uuid], array_column($cola->queue($gente['ana'], 'unclaimed'), 'uuid'));

        // Después de salir, la IA marca una queja en su conversación (el emisor real registra `complaint_created`).
        $this->complaintFromAi($p['member']['lead']);
        $this->assertSame(1, DB::table('commercial_events')->where('event', 'complaint_created')->count());
        $antes = self::rowsSnapshot();
        Log::spy();

        $this->assertSame([], $cola->queue($gente['ana']), 'ya no se ofrece');
        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'));
        $this->assertClaimWithheld($cola, $uuid, 'queja de hace un instante');
        $this->assertSame($antes, self::rowsSnapshot(), 'la bandeja no escribe: el trabajo y la inscripción quedan como estaban');
        $this->assertSame(['started', null], [DB::table('outreach_jobs')->value('status'), DB::table('outreach_jobs')->value('assisted_claimed_by_admin_id')]);

        // El motivo real, al registro de la aplicación: el trabajo y el motivo, sin teléfono ni nombre.
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $uuid, 'reason' => 'complaint_recent', 'known' => true]);

        // La queja de hace 31 días de Bogotá ya no frena (la misma ventana que N6): la de otra persona no la toca.
        DB::table('commercial_events')->where('event', 'complaint_created')->update(['occurred_at' => '2026-10-17 15:00:00']);
        $this->assertSame([$uuid], array_column($cola->queue($gente['ana']), 'uuid'), 'fuera de la ventana de 30 días vuelve a ofrecerse');
        $this->assertFalse($cola->claim($gente['ana'], $uuid, (string) Str::uuid())['replayed']);
    }

    public function test_quien_ya_lo_reclamo_lo_sigue_viendo_y_registra_su_resultado_y_el_otro_trabajo_se_retiene(): void
    {
        $gente = $this->threePeople();
        [$reclamado, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $reclamado['job']->uuid, (string) Str::uuid());

        // Las dos personas se quejan DESPUÉS: una ya tenía a Ana; la otra, nadie.
        $this->complaintFromAi($reclamado['member']['lead']);
        $this->complaintFromAi($libre['member']['lead']);

        $this->assertSame([(string) $reclamado['job']->uuid], array_column($cola->queue($gente['ana']), 'uuid'), 'el reclamado se queda; el libre ya no se ofrece');
        $this->assertSame([(string) $reclamado['job']->uuid], array_column($cola->queue($gente['ana'], 'mine'), 'uuid'));
        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'));
        $this->assertClaimWithheld($cola, (string) $libre['job']->uuid, 'el libre, con queja');

        // Quien ya lo tenía: la repetición idempotente sigue y el resultado se registra (requisito 3). Desde I-1, sin el
        // enlace a la ficha ni el guion: se quejó después de que lo reclamara.
        $this->assertTrue($cola->claim($gente['ana'], (string) $reclamado['job']->uuid, (string) Str::uuid())['replayed']);
        $mio = $cola->queue($gente['ana'], 'mine')[0];
        $this->assertSame([null, null, 'complaint_recent'], [$mio['person'], $mio['script'], $mio['contactWithheld']], 'quien reclamó sigue, con el contacto retenido (I-1)');
        $r = $cola->recordOutcome($gente['ana'], (string) $reclamado['job']->uuid, 'not_interested', (string) Str::uuid());
        $this->assertSame([OutcomeRecorder::RESULT_RECORDED, 'not_interested'], [$r['result'], $r['outcome']]);
        $this->assertSame(1, DB::table('outreach_outcomes')->where('source', 'staff')->count());
        $this->assertStringNotContainsString('300123456', json_encode([$cola->queue($gente['ana']), $cola->queue($gente['beto'])]));
    }

    public function test_sin_datos_de_eventos_falla_cerrado_y_con_cero_dias_no_retiene(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;

        // La fuente de eventos apagada (o la persona sin resolver): no se sabe si se quejó → no se ofrece ni se reclama.
        config(['commercial.events_enabled' => false]);
        Log::spy();
        $this->assertSame([], $cola->queue($gente['ana']), 'sin datos, retenido (falla cerrado, como N6)');
        $this->assertClaimWithheld($cola, $uuid, 'sin datos de eventos');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $uuid, 'reason' => 'complaint_recent', 'known' => false]);

        // Con la fuente encendida y una queja de ayer, pero el freno apagado por configuración (solo 0 lo apaga): se ofrece.
        config(['commercial.events_enabled' => true, 'outreach.complaint_days' => 0]);
        $this->commercialEvent('complaint_created', '2026-11-16 15:00:00', $p['member']['member']);
        $this->assertSame([$uuid], array_column($cola->queue($gente['ana']), 'uuid'));
        // Con 7 días configurados, la de ayer frena (la ventana es la configurada, no una constante).
        config(['outreach.complaint_days' => 7]);
        $this->assertSame([], $cola->queue($gente['ana']));
        config(['outreach.complaint_days' => 0]);
        $this->assertFalse($cola->claim($gente['ana'], $uuid, (string) Str::uuid())['replayed']);
    }

    public function test_una_pagina_lee_las_quejas_con_una_sola_consulta_tambien_la_vista_mine(): void
    {
        $gente = $this->threePeople();
        $trabajos = $this->startedFor(['3001234561', '3001234562', '3001234563']);
        $this->commercialEvent('complaint_created', '2026-11-17 15:10:00', $trabajos[1]['member']['member']);
        $cola = new AssistedQueue;

        $leidas = [];
        DB::listen(function ($q) use (&$leidas): void {
            if (str_contains($q->sql, 'commercial_events')) {
                $leidas[] = $q->sql;
            }
        });
        $vistos = array_column($cola->queue($gente['ana'], 'all', 50), 'uuid');
        $this->assertEqualsCanonicalizing([(string) $trabajos[0]['job']->uuid, (string) $trabajos[2]['job']->uuid], $vistos, 'el de la persona con queja no se ofrece');
        $this->assertCount(1, $leidas, 'una consulta a commercial_events por página, no una por fila: '.json_encode($leidas));

        // I-1: lo reclamado también se vuelve a mirar (la queja puede llegar después de reclamar), con una sola consulta.
        $cola->claim($gente['ana'], (string) $trabajos[0]['job']->uuid, (string) Str::uuid());
        $leidas = [];
        $this->assertCount(1, $cola->queue($gente['ana'], 'mine'));
        $this->assertCount(1, $leidas, 'la vista mine lee las quejas una vez: '.json_encode($leidas));
    }
}
