<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\MarketingAiAction;
use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachStop;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Las cuatro condiciones de security para abrir el asistido en vivo
 * (`informe-security-bandeja.md` §4), sobre las clases REALES de la ejecución:
 *
 *  - I-1 (R-1): la queja que llega DESPUÉS de reclamar retiene el contacto
 *    (`person` y `script` en null, `contactWithheld`) y deja registrar el
 *    resultado; sin datos de eventos, `complaint_unknown`;
 *  - I-2 (H-1): lo SIN reclamar vencido (`expires_at <= ahora`) no se ofrece
 *    ni se reclama; lo reclamado y vencido se queda con `expired`;
 *  - I-3: la PARADA GLOBAL, el freno del canal `assisted` (`legal_hold`) y el
 *    de la estrategia retienen lo mismo; los frenos ilegibles fallan cerrado;
 *  - I-4: la página se llena por detrás de los retenidos y `withheld` dice
 *    cuántos, sin decir de quién; con un tope de lectura por página;
 *  - la prioridad entre motivos y la persona suprimida (`x:<id>`).
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), en vivo, con la fuente
 * de eventos encendida (L9-26), como el resto de B9-run. El reloj, en UTC.
 */
class H9AssistedConditionsTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    /** @var array<string, mixed> la estrategia viva de la prueba ({@see liveStrategy()}) */
    private array $vivo = [];

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

    // ── El mundo ────────────────────────────────────────────────────────────

    /**
     * Una estrategia viva y un trabajo asistido que SALIÓ en vivo por cada teléfono (uno por persona), por el
     * planificador y el despachador reales. El reloj queda 15 min después (el desfase).
     *
     * @param  list<string>  $phones
     * @return list<array{member: array<string, mixed>, job: object}>
     */
    private function startedFor(array $phones): array
    {
        $ahora = self::utcAt(self::NOW);
        $this->runFlags('live');
        $this->vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
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

    /**
     * `$n` trabajos asistidos `started` en vivo de la estrategia de la prueba, sembrados por las filas del
     * esquema (nacen `planned` y avanzan por los disparadores reales). Con `$resolvable`, de socios que existen
     * (sin queja: se ofrecen); sin él, de personas que no se pueden resolver (sin datos de eventos: N6 no sabe,
     * la bandeja retiene).
     *
     * @return list<int> ids de los trabajos
     */
    private function seedStarted(int $n, string $expiresAt, bool $resolvable = false): array
    {
        $plantilla = $this->assistedTemplate();
        $huella = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $persona = [];
            if ($resolvable) {
                $socio = $this->socio();
                $persona = ['subject_key' => 'm:'.$socio['member'], 'member_id' => $socio['member']];
            }
            $e = $this->insertEnrollment($this->vivo, ['mode' => 'live'] + $persona);
            $ids[] = $this->insertJob($e, ['channel' => 'assisted', 'medium' => 'whatsapp', 'template_version_id' => $plantilla, 'template_hash' => $huella,
                'not_before' => '2026-11-17 15:00:00', 'expires_at' => $expiresAt, 'budget_day' => '2026-11-17']);
        }
        foreach ([['status' => 'claimed', 'claimed_at' => now()], ['status' => 'dispatching'], ['status' => 'started']] as $paso) {
            DB::table('outreach_jobs')->whereIn('id', $ids)->update($paso + ['status_changed_at' => now()]);
        }

        return $ids;
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
        $this->assertNull(DB::table('outreach_jobs')->where('uuid', $jobUuid)->value('assisted_claimed_by_admin_id'), "{$why}: no se reclamó");
    }

    /** La fila de un trabajo en una vista (o null si no está). @param  list<array<string, mixed>>  $vista @return array<string, mixed>|null */
    private static function row(array $vista, string $uuid): ?array
    {
        foreach ($vista as $fila) {
            if ($fila['uuid'] === $uuid) {
                return $fila;
            }
        }

        return null;
    }

    /** Lo que el reclamante ve de su trabajo: el enlace, el guion y el motivo de la retención. @param  array<string, mixed>|null  $fila @return array{0: mixed, 1: bool, 2: mixed} */
    private static function contact(?array $fila): array
    {
        if ($fila === null) {
            return ['falta la fila', false, 'falta la fila'];
        }

        return [$fila['person'], $fila['script'] !== null, array_key_exists('contactWithheld', $fila) ? $fila['contactWithheld'] : 'falta la clave'];
    }

    // ── I-1 · La queja que llega DESPUÉS de reclamar ────────────────────────

    public function test_i1_la_queja_despues_de_reclamar_retiene_el_contacto_y_deja_registrar_el_resultado(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $ficha = ['kind' => 'member', 'id' => $p['member']['member']];
        $antes = $cola->queue($gente['ana'], 'mine')[0];
        $this->assertSame([$ficha, true], [$antes['person'], $antes['script'] !== null], 'antes de la queja: el enlace y el guion');

        // Después de reclamar, la IA marca una queja (el emisor real registra `complaint_created`).
        $this->complaintFromAi($p['member']['lead']);
        $this->assertSame(1, DB::table('commercial_events')->where('event', 'complaint_created')->count());

        foreach (['mine', 'all'] as $vista) {
            $this->assertSame([null, false, 'complaint_recent'], self::contact(self::row($cola->queue($gente['ana'], $vista), $uuid)), "{$vista}: sin enlace ni guion, con el motivo");
        }
        $this->assertSame([null, null], [$antes['contactWithheld'], self::row($cola->queue($gente['caro'], 'all'), $uuid)['contactWithheld']], 'la clave va siempre; sin retención (o sin ser suyo), null');
        // La repetición idempotente de `claim()`, igual.
        $r = $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $this->assertTrue($r['replayed']);
        $this->assertSame([null, false, 'complaint_recent'], self::contact($r['job']));
        // A otra persona no se le cuenta la queja: su fila (de Ana) va sin enlace, sin guion y sin motivo, como siempre.
        $this->assertSame([null, false, null], self::contact(self::row($cola->queue($gente['beto'], 'all'), $uuid)));
        $this->assertStringNotContainsString('3001234567', json_encode([$cola->queue($gente['ana']), $r]));

        // `recordOutcome()` no cambia: quien reclamó registra su resultado.
        $res = $cola->recordOutcome($gente['ana'], $uuid, 'not_interested', (string) Str::uuid());
        $this->assertSame([OutcomeRecorder::RESULT_RECORDED, 'not_interested'], [$res['result'], $res['outcome']]);
        $this->assertSame([], $cola->queue($gente['ana'], 'mine'), 'con resultado deja la bandeja');
    }

    public function test_i1_sin_datos_de_eventos_el_reclamante_ve_complaint_unknown_y_con_cero_dias_vuelve_el_contacto(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());

        config(['commercial.events_enabled' => false]);
        $this->assertSame([null, false, 'complaint_unknown'], self::contact($cola->queue($gente['ana'], 'mine')[0]), 'sin datos: falla cerrado, como N6');
        $this->assertSame([null, false, 'complaint_unknown'], self::contact($cola->claim($gente['ana'], $uuid, (string) Str::uuid())['job']));

        // Con la queja apagada por configuración (solo 0 la apaga), el reclamante vuelve a tener el contacto.
        config(['outreach.complaint_days' => 0]);
        $this->assertSame([['kind' => 'member', 'id' => $p['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]));
    }

    // ── I-2 · El vencimiento ────────────────────────────────────────────────

    public function test_i2_vencido_sin_reclamar_no_se_ofrece_ni_se_reclama_y_el_reclamado_vencido_retiene_el_contacto(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        $vence = max((string) $mio['job']->expires_at, (string) $libre['job']->expires_at);
        $venceLibre = Carbon::parse((string) $libre['job']->expires_at, 'UTC');

        // Un segundo antes del plazo del libre: se ofrece.
        Carbon::setTestNow($venceLibre->copy()->subSecond());
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), (string) $libre['job']->uuid), 'dentro del plazo, se ofrece');
        // En el plazo exacto (`expires_at <= ahora`, el borde del despachador): ya no.
        Carbon::setTestNow($venceLibre);
        $this->assertNull(self::row($cola->queue($gente['beto'], 'all'), (string) $libre['job']->uuid), 'vencido: no se ofrece');
        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'));
        Log::spy();
        $this->assertClaimWithheld($cola, (string) $libre['job']->uuid, 'vencido');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => (string) $libre['job']->uuid, 'reason' => 'expired']);
        $this->assertSame(0, (new AssistedQueue)->page($gente['beto'], 'all')['withheld'], 'un vencido no cuenta como retenido: salió por plazo');

        // El reclamado, pasado su plazo: se queda para su reclamante, sin enlace ni guion.
        Carbon::setTestNow(Carbon::parse($vence, 'UTC')->addHour());
        $this->assertSame([null, false, 'expired'], self::contact($cola->queue($gente['ana'], 'mine')[0]));
        $this->assertSame([null, false, 'expired'], self::contact($cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid())['job']));
        $res = $cola->recordOutcome($gente['ana'], (string) $mio['job']->uuid, 'no_answer', (string) Str::uuid());
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $res['result'], 'el resultado se registra igual');
        $this->assertSame(['started', 'started'], DB::table('outreach_jobs')->orderBy('id')->pluck('status')->all(), 'la bandeja no escribe: los trabajos siguen started');
    }

    // ── I-3 · Los frenos ────────────────────────────────────────────────────

    public function test_i3_la_parada_global_el_freno_legal_hold_del_canal_y_el_de_la_estrategia_retienen_y_al_soltarlos_vuelve(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $stop = new OutreachStop;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        $uuidLibre = (string) $libre['job']->uuid;

        $frenos = [
            'parada global' => fn (): array => $stop->stopAll('Parada global de la prueba', $gente['ana']),
            'legal_hold del canal asistido' => fn (): array => $stop->open('channel', 'assisted', 'legal_hold', 'Retención legal de la prueba', $gente['ana'], (string) Str::uuid()),
            'freno de la estrategia' => fn (): array => $stop->open('strategy', $this->vivo['strategyUuid'], 'manual', 'Freno de la estrategia', $gente['ana'], (string) Str::uuid()),
        ];
        Log::spy();
        $vez = 0;
        foreach ($frenos as $cual => $abrir) {
            $vez++;
            $freno = $abrir()['brake'];
            $this->assertNull(self::row($cola->queue($gente['beto'], 'all'), $uuidLibre), "{$cual}: lo sin reclamar no se ofrece");
            $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'), $cual);
            $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld'], "{$cual}: se cuenta, sin decir de quién");
            $this->assertClaimWithheld($cola, $uuidLibre, $cual);
            Log::shouldHaveReceived('warning')->times($vez)->with('outreach.assisted.claim_withheld', ['job' => $uuidLibre, 'reason' => 'brake']);
            $this->assertSame([null, false, 'brake'], self::contact($cola->queue($gente['ana'], 'mine')[0]), "{$cual}: el reclamado retiene el contacto");
            $this->assertSame([null, false, 'brake'], self::contact($cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid())['job']), "{$cual}: y la repetición");
            $this->assertSame(0, $cola->page($gente['ana'], 'mine')['withheld'], 'en «mine» no se quita ninguna fila');

            // Soltarlo (otra persona, a cuatro ojos): todo vuelve.
            $stop->release($gente['beto'], $freno['uuid'], 'Revisado: se suelta', (string) Str::uuid());
            $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $uuidLibre), "{$cual} suelto: se ofrece otra vez");
            $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]), "{$cual} suelto");
        }

        // Los frenos que NO son de este trabajo no retienen nada: otro canal y otra estrategia.
        $stop->open('channel', 'whatsapp_template', 'manual', 'Otro canal', $gente['ana'], (string) Str::uuid());
        $stop->open('strategy', (string) Str::uuid(), 'manual', 'Otra estrategia', $gente['ana'], (string) Str::uuid());
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $uuidLibre));
        $this->assertSame(0, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]));
        $this->assertFalse($cola->claim($gente['caro'], $uuidLibre, (string) Str::uuid())['replayed'], 'y se reclama');
    }

    public function test_i3_si_los_frenos_no_se_pueden_leer_la_bandeja_falla_cerrada(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        Schema::drop('outreach_brakes');
        // En PostgreSQL un error aborta la transacción de la prueba: cada intento va en su punto de guardado. En SQLite,
        // sin él, para ver además que la reclamación no llegó a escribir antes del error.
        $pg = DB::connection()->getDriverName() === 'pgsql';
        $intento = static fn (callable $f): mixed => $pg ? DB::transaction($f) : $f();

        foreach ([
            'la bandeja' => fn () => $cola->queue($gente['beto'], 'all'),
            'la vista del reclamante' => fn () => $cola->queue($gente['ana'], 'mine'),
            'la página' => fn () => $cola->page($gente['beto'], 'unclaimed'),
            'reclamar' => fn () => $cola->claim($gente['beto'], (string) $libre['job']->uuid, (string) Str::uuid()),
            'la repetición' => fn () => $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid()),
        ] as $que => $f) {
            try {
                $intento($f);
                $this->fail("{$que}: con los frenos ilegibles tenía que fallar (cerrado), no responder.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('outreach_brakes', $e->getMessage(), "{$que}: falla por los frenos, no por otra cosa");
            }
        }
        $this->assertNull(DB::table('outreach_jobs')->where('id', $libre['job']->id)->value('assisted_claimed_by_admin_id'), 'nada se reclamó');
    }

    public function test_i3_los_frenos_se_leen_antes_de_decidir_y_de_escribir(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = strtolower($q->sql);
        });
        $primera = static function (array $sql, string $trozo): int {
            foreach ($sql as $i => $q) {
                if (str_contains($q, $trozo)) {
                    return $i;
                }
            }

            return PHP_INT_MAX;
        };

        // Reclamar: los frenos, antes de la queja (la decisión) y antes del UPDATE (la escritura); y otra vez DESPUÉS
        // del UPDATE (m-2 de `informe-security-bandeja-condiciones.md`: un freno echado entre las dos retiene el contacto).
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $frenos = $primera($sql, '"outreach_brakes"');
        $this->assertLessThan($primera($sql, '"commercial_events"'), $frenos, 'los frenos antes de mirar la queja: '.json_encode($sql));
        $this->assertLessThan($primera($sql, 'update "outreach_jobs"'), $frenos, 'los frenos antes de reclamar');
        $lecturas = array_keys(array_filter($sql, static fn (string $q): bool => str_contains($q, '"outreach_brakes"')));
        $this->assertCount(2, $lecturas, 'una lectura de frenos para decidir y otra tras escribir (m-2)');
        $this->assertGreaterThan($primera($sql, 'update "outreach_jobs"'), $lecturas[1], 'la segunda, después del UPDATE');
        // La repetición: los frenos antes de decidir si se le retiene el contacto.
        $sql = [];
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $this->assertNotSame(PHP_INT_MAX, $primera($sql, '"outreach_brakes"'), 'la repetición lee los frenos');
        $this->assertLessThan($primera($sql, '"commercial_events"'), $primera($sql, '"outreach_brakes"'), 'repetición: '.json_encode($sql));
        // La página: los frenos antes de leer ninguna fila de la bandeja.
        $sql = [];
        $cola->queue($gente['ana'], 'mine');
        $this->assertSame(0, $primera($sql, '"outreach_brakes"'), 'página: la primera consulta son los frenos: '.json_encode($sql));
    }

    // ── La prioridad entre motivos ──────────────────────────────────────────

    public function test_un_solo_motivo_por_fila_freno_queja_sin_datos_y_vencido_en_ese_orden(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $motivo = fn (): mixed => self::contact($cola->queue($gente['ana'], 'mine')[0])[2];

        // Vencido solo.
        Carbon::setTestNow(Carbon::parse((string) $p['job']->expires_at, 'UTC')->addMinute());
        $this->assertSame('expired', $motivo());
        // Vencido y sin datos de eventos: `complaint_unknown`.
        config(['commercial.events_enabled' => false]);
        $this->assertSame('complaint_unknown', $motivo());
        // Vencido y con queja: `complaint_recent`.
        config(['commercial.events_enabled' => true]);
        $this->commercialEvent('complaint_created', Carbon::now()->subMinute()->format('Y-m-d H:i:s'), $p['member']['member']);
        $this->assertSame('complaint_recent', $motivo());
        // Con todo eso y un freno: `brake`.
        (new OutreachStop)->open('channel', 'assisted', 'legal_hold', 'Retención legal', $gente['beto'], (string) Str::uuid());
        $this->assertSame('brake', $motivo());
        // La lista completa (con `strategy_stopped`, `opted_out` y `policy_unknown`) la fija H9AssistedOptOutStrategyTest.
        $this->assertSame(AssistedQueue::WITHHELD_REASONS, ['brake', 'strategy_stopped', 'opted_out', 'complaint_recent', 'policy_unknown', 'complaint_unknown', 'expired']);
    }

    // ── I-4 · La página que se llena ────────────────────────────────────────

    public function test_i4_la_pagina_se_llena_por_detras_de_los_retenidos_y_dice_cuantos_sin_decir_de_quien(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        // 55 retenidos (personas sin datos de eventos) en cabeza del orden, y detrás 3 que se ofrecen: cruza una tanda (50).
        $retenidos = $this->seedStarted(55, '2026-11-18 09:00:00');
        $ofrecibles = $this->seedStarted(3, '2026-11-18 12:00:00', resolvable: true);
        $uuids = DB::table('outreach_jobs')->whereIn('id', $ofrecibles)->orderBy('id')->pluck('uuid')->all();
        $cola = new AssistedQueue;

        $this->assertSame(array_slice($uuids, 0, 2), array_column($cola->queue($gente['ana'], 'unclaimed', 2), 'uuid'), 'la página se llena con lo que hay detrás');
        $pagina = $cola->page($gente['ana'], 'all', 2);
        $this->assertSame(array_slice($uuids, 0, 2), array_column($pagina['items'], 'uuid'));
        $this->assertSame(55, $pagina['withheld']);
        $toda = $cola->page($gente['ana'], 'all', 50);
        $this->assertCount(4, $toda['items'], 'los 3 sembrados y el del despachador');
        $this->assertSame(55, $toda['withheld']);
        $this->assertSame(['items' => [], 'withheld' => 0, 'scanCapped' => false], $cola->page($gente['ana'], 'mine', 50));
        $sinPersonas = json_encode($toda);
        foreach ($retenidos as $id) {
            $this->assertStringNotContainsString((string) DB::table('outreach_jobs')->where('id', $id)->value('uuid'), $sinPersonas, 'de los retenidos, solo el número');
        }

        // Con el freno de la estrategia, todo lo sin reclamar queda fuera y se cuenta (un recuento en SQL, sin leerlo).
        (new OutreachStop)->open('strategy', $this->vivo['strategyUuid'], 'manual', 'Freno de la estrategia', $gente['ana'], (string) Str::uuid());
        $this->assertSame(['items' => [], 'withheld' => 59, 'scanCapped' => false], $cola->page($gente['ana'], 'all', 50));
    }

    public function test_i4_la_lectura_de_una_pagina_tiene_tope_y_no_pasa_de_la_foto_maxima(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $this->seedStarted(AssistedQueue::MAX_SCAN + 1, '2026-11-18 09:00:00');
        $cola = new AssistedQueue;
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        // Tras MAX_SCAN filas retenidas la lectura para: el trabajo del despachador, detrás, no llega a esta página.
        $this->assertSame(['items' => [], 'withheld' => AssistedQueue::MAX_SCAN, 'scanCapped' => true], $cola->page($gente['ana'], 'all', 20));
        $cuenta = static fn (string $tabla): int => count(array_filter($sql, static fn (string $q): bool => str_contains($q, $tabla)));
        $tandas = AssistedQueue::MAX_SCAN / AssistedQueue::SCAN_BATCH;
        // Una lectura de frenos por página; por tanda, una lectura de trabajos y una foto (aquí nadie se resuelve: solo el resolutor de socios).
        $this->assertSame([1, $tandas, $tandas], [$cuenta('"outreach_brakes"'), $cuenta('"enrollment_uuid"'), $cuenta('from "members"')], 'una foto por tanda, nunca una por fila');
        // N-2: la cadena, UNA vez por página (no por tanda): las partes de la vista (1) y `liveStrategies()` de su única
        // estrategia (9: la estrategia, las 3 de la cadena, la estrategia y la persona de su entrada, las versiones y los 2
        // dominios del catálogo con que se relee el contenido). La foto de personas sin resolver no lee la política.
        $this->assertSame(1, $cuenta('select distinct "j"."strategy_id"'));
        $this->assertCount(1 + (1 + 9) + 2 * $tandas, $sql, 'y nada más: '.json_encode($sql));
        $this->assertLessThanOrEqual(BatchSnapshot::MAX_SUBJECTS, AssistedQueue::MAX_SCAN);
        $this->assertSame(AssistedQueue::MAX_PAGE, AssistedQueue::SCAN_BATCH);
    }

    public function test_i4_una_pagina_normal_lee_los_frenos_una_vez_y_las_quejas_una_vez(): void
    {
        $gente = $this->threePeople();
        $trabajos = $this->startedFor(['3001234561', '3001234562', '3001234563']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $trabajos[0]['job']->uuid, (string) Str::uuid());
        $leidas = ['brakes' => 0, 'events' => 0];
        DB::listen(function ($q) use (&$leidas): void {
            $leidas['brakes'] += str_contains($q->sql, 'outreach_brakes') ? 1 : 0;
            $leidas['events'] += str_contains($q->sql, 'commercial_events') ? 1 : 0;
        });

        $this->assertCount(3, $cola->queue($gente['ana'], 'all', 50));
        $this->assertSame(['brakes' => 1, 'events' => 1], $leidas, 'una lectura de frenos y una foto por página');
        $leidas = ['brakes' => 0, 'events' => 0];
        $this->assertCount(1, $cola->queue($gente['ana'], 'mine'));
        $this->assertSame(['brakes' => 1, 'events' => 1], $leidas, 'lo reclamado también se vuelve a mirar (I-1)');
        $leidas = ['brakes' => 0, 'events' => 0];
        $this->assertCount(3, $cola->queue($gente['beto'], 'all', 50));
        $this->assertSame(['brakes' => 1, 'events' => 1], $leidas, 'las filas de otro no añaden lecturas');
    }

    // ── La persona suprimida (1581) ─────────────────────────────────────────

    public function test_un_trabajo_de_una_persona_suprimida_no_se_ofrece_y_su_reclamante_no_tiene_contacto(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        $this->suppress([(int) $mio['job']->id, (int) $libre['job']->id]);
        $this->assertSame(2, DB::table('outreach_jobs')->where('subject_key', 'like', 'x:%')->count());

        // N-1: la clave suprimida no se lee, así que tampoco la política: `policy_unknown` (antes de N-1, `complaint_unknown`).
        $this->assertSame([null, false, 'policy_unknown'], self::contact($cola->queue($gente['ana'], 'mine')[0]), 'su reclamante no tiene ni enlace ni guion');
        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'), 'la clave suprimida no se lee: sin datos, retenido');
        $this->assertClaimWithheld($cola, (string) $libre['job']->uuid, 'persona suprimida');
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $cola->recordOutcome($gente['ana'], (string) $mio['job']->uuid, 'wrong_person', (string) Str::uuid())['result']);
    }

    /**
     * La forma suprimida de la 1581 en los trabajos (`OutreachSuppressSubjectCommand::ownerSql()`): la clave pasa a
     * `x:<id>`, el seudónimo a ceros, sin teléfono. La aplica el DUEÑO con los disparadores apagados; aquí, igual:
     * en PostgreSQL con `DISABLE TRIGGER USER` y en SQLite quitando y reponiendo los disparadores de la tabla.
     *
     * @param  list<int>  $jobIds
     */
    private function suppress(array $jobIds): void
    {
        $fila = static fn (int $id): array => ['subject_key' => 'x:'.$id, 'subject_ref' => str_repeat('0', 64), 'phone_ref' => null, 'redacted_at' => now()];
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE outreach_jobs DISABLE TRIGGER USER');
            foreach ($jobIds as $id) {
                DB::table('outreach_jobs')->where('id', $id)->update($fila($id));
            }
            DB::statement('ALTER TABLE outreach_jobs ENABLE TRIGGER USER');

            return;
        }
        $disparadores = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'outreach_jobs'");
        foreach ($disparadores as $d) {
            DB::statement('DROP TRIGGER '.$d->name);
        }
        foreach ($jobIds as $id) {
            DB::table('outreach_jobs')->where('id', $id)->update($fila($id));
        }
        foreach ($disparadores as $d) {
            DB::statement($d->sql);
        }
    }
}
