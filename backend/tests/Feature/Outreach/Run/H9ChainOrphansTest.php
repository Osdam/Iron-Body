<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachReconciler;
use App\Services\UltronStudio\StudioCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Huérfana ≠ cadena que no verifica (contrato H9, D9-3, D9-8, D9-11.3; REV9
 * M-5 y Q-M4, L9-69; TEST9-c): UNA regla en los tres sitios
 * (`LiveStrategy::orphanReason()`, en el conciliador, el planificador y el
 * despachador):
 *
 *  - la cadena NO verifica (`signature_mismatch`, `row_mismatch`; p. ej. una
 *    entrada con la firma rota o una rotación de `APP_KEY`) → null: NADIE
 *    cancela nada. El conciliador no toca las inscripciones (`canceledByChain
 *    0`), el planificador deja ESPERAR a la vencida (+15 min, `waiting`), el
 *    despachador cancela el trabajo ya reclamado con `chain_unreadable` (N5)
 *    y la inscripción espera; N5 niega y el monitor frena
 *    (`OutreachStopAndGateTest`). Cuando la cadena vuelve a leerse, todo sigue
 *    (cancelar por una firma rota sería irreversible: la ocurrencia del ciclo
 *    se consume, L9-1);
 *  - la cadena verifica pero el contenido no se puede releer (`content ===
 *    null`) → `catalog_incompatible`: se cancela en los tres sitios;
 *  - la versión ya no está en el estado vivo de su parte (reversión, retirada
 *    o la PROMOCIÓN canario → resto, Q-M4) → `strategy_moved`: las de la parte
 *    canario sin trabajo vivo se cancelan en la conciliación, las de la parte
 *    resto siguen, y las personas tocadas en canario no vuelven a entrar en el
 *    mismo ciclo.
 */
class H9ChainOrphansTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    /** Martes 17-11-2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    private const LATER = '2026-11-17 15:15:00';

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

    // ── Mundos ──────────────────────────────────────────────────────────────

    /**
     * Una estrategia viva en sombra con tres inscripciones abiertas: A salió
     * (`simulated`, sin trabajo vivo, espera la ventana con `next_due_at`
     * nulo), B tiene su trabajo `planned` (vivo) y D está inscrita y vencida
     * sin trabajo (`next_due_at` = LATER). El reloj queda en LATER.
     *
     * @return array{live: array<string, mixed>, a: object, b: object, d: object}
     */
    private function threeOpenEnrollments(): array
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $this->qualifyingMember(self::utcAt(self::NOW), 6, '3001234568');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(2, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse(self::LATER, 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), 1, true)['results']);
        $d = $this->qualifyingMember(self::utcAt(self::NOW), 4, '3001234569');
        $this->assertSame(1, $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']]['enrolled']);
        $a = DB::table('outreach_enrollments')->where('id', DB::table('outreach_jobs')->where('status', 'simulated')->value('enrollment_id'))->first();
        $b = DB::table('outreach_enrollments')->where('id', DB::table('outreach_jobs')->where('status', 'planned')->value('enrollment_id'))->first();
        $dFila = DB::table('outreach_enrollments')->where('subject_key', $d['key'])->first();
        $this->assertNull($a->next_due_at);
        $this->assertSame(self::LATER, (string) $dFila->next_due_at);

        return ['live' => $vivo, 'a' => $a, 'b' => $b, 'd' => $dFila];
    }

    /**
     * Una entrada de la cadena con el ESTADO bien formado (la versión sigue
     * `active` en su parte) pero una FIRMA que no verifica: la cadena de esa
     * estrategia deja de leerse (`signature_mismatch`) sin que el puente
     * pierda su estado. La entrada de relleno de `appendPublication()` no
     * sirve aquí: sin `state`, el puente omite la estrategia entera.
     */
    private function brokenSignaturePublication(array $live): int
    {
        $people = $this->people();
        $beto = (string) $this->db()->table('identities')->where('id', $people[2])->value('uuid');
        $s = DB::table('ultron_studio_strategies')->where('id', $live['strategy'])->first(['uuid', 'key', 'purpose']);
        $cabeza = DB::table('ultron_studio_strategy_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = (int) $cabeza->seq + 1;
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $mensaje = StrategyChainSignature::message([
            'action' => 'resume',
            'actedByPerson' => strtolower($beto),
            'approvedByPerson' => null,
            'contract' => StrategyChainSignature::CONTRACT,
            'issuedAt' => RelativeTime::format(now()),
            'prevHash' => (string) $cabeza->effective_hash,
            'seq' => $seq,
            'state' => $live['part'] === 'rest' ? ['canary' => null, 'rest' => $parte, 'status' => 'active'] : ['canary' => $parte, 'rest' => null, 'status' => 'active'],
            'strategy' => ['key' => (string) $s->key, 'purpose' => (string) $s->purpose, 'uuid' => (string) $s->uuid],
        ]);
        $this->insertPublication($seq, (int) $cabeza->id, [
            'strategy_id' => $live['strategy'], 'version_id' => null, 'action' => 'resume', 'effective_json' => $mensaje,
            'effective_hash' => StrategyChainSignature::entryHash($mensaje), 'signature' => str_repeat('c', 64),
            'simulation_gate' => null, 'evidence' => null, 'reason' => 'Firma rota (prueba)',
        ]);

        return $seq;
    }

    /**
     * Una entrada de REVERSIÓN FALSIFICADA en la cabeza de la cadena (REV9
     * m-10, L9-72): una inserción cruda, como la haría quien manipule la base,
     * con el estado «todo apagado» y una firma que no verifica. El par
     * (versión, parte) desaparece del estado de la cabeza; la versión no se
     * toca (sigue `CANARY`).
     */
    private function forgedRollbackPublication(array $live): int
    {
        $people = $this->people();
        $beto = (string) $this->db()->table('identities')->where('id', $people[2])->value('uuid');
        $s = DB::table('ultron_studio_strategies')->where('id', $live['strategy'])->first(['uuid', 'key', 'purpose']);
        $cabeza = DB::table('ultron_studio_strategy_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = (int) $cabeza->seq + 1;
        $mensaje = StrategyChainSignature::message([
            'action' => 'rollback',
            'actedByPerson' => strtolower($beto),
            'approvedByPerson' => null,
            'contract' => StrategyChainSignature::CONTRACT,
            'issuedAt' => RelativeTime::format(now()),
            'prevHash' => (string) $cabeza->effective_hash,
            'seq' => $seq,
            'state' => ['canary' => null, 'rest' => null, 'status' => 'off'],
            'strategy' => ['key' => (string) $s->key, 'purpose' => (string) $s->purpose, 'uuid' => (string) $s->uuid],
        ]);
        $this->insertPublication($seq, (int) $cabeza->id, [
            'strategy_id' => $live['strategy'], 'version_id' => $live['version'], 'action' => 'rollback', 'effective_json' => $mensaje,
            'effective_hash' => StrategyChainSignature::entryHash($mensaje), 'signature' => str_repeat('c', 64),
            'simulation_gate' => null, 'evidence' => null, 'reason' => 'Reversión falsificada (prueba)',
        ]);

        return $seq;
    }

    /**
     * Retira la fila falsificada como lo haría el DUEÑO de la base: la
     * aplicación no puede (`usspub_forbid_delete`, la cadena es de solo
     * inserción). En PostgreSQL, con los disparadores apagados dentro de la
     * transacción de la prueba; en SQLite, soltando y recreando ese disparador.
     */
    private function removeForgedRow(int $seq): void
    {
        $tabla = 'ultron_studio_strategy_publications';
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET LOCAL session_replication_role = replica');
            try {
                $this->assertSame(1, DB::table($tabla)->where('seq', $seq)->delete());
            } finally {
                DB::statement('SET LOCAL session_replication_role = DEFAULT');
            }

            return;
        }
        $disparador = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $tabla)->where('name', 'like', '%forbid_delete%')->first(['name', 'sql']);
        $this->assertNotNull($disparador, 'el disparador que prohíbe el DELETE en la cadena tiene que existir');
        DB::statement('DROP TRIGGER '.$disparador->name);
        try {
            $this->assertSame(1, DB::table($tabla)->where('seq', $seq)->delete());
        } finally {
            DB::statement((string) $disparador->sql);
        }
    }

    /** Una entrada `resume` FIRMADA de verdad: la cadena vuelve a leerse (la cabeza enlaza con la anterior por su huella). */
    private function resumeStrategy(array $live): int
    {
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $estado = $live['part'] === 'rest' ? ['canary' => null, 'rest' => $parte, 'status' => 'active'] : ['canary' => $parte, 'rest' => null, 'status' => 'active'];

        return $this->signedPublication($live['strategy'], null, 'resume', $estado, 'La cadena vuelve a leerse');
    }

    /** Promueve la versión viva de la parte canario a la parte resto (una entrada `promote` firmada; CANARY → PUBLISHED). */
    private function promoteStrategy(array $live): int
    {
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $seq = $this->signedPublication($live['strategy'], $live['version'], 'promote', ['canary' => null, 'rest' => $parte, 'status' => 'active'], 'Promoción de la prueba');
        $this->setVersionStatus($live['version'], 'PUBLISHED');

        return $seq;
    }

    /**
     * Una estrategia cuya cadena VERIFICA pero cuyo contenido guardado no se
     * puede releer: el JSON canónico nombra un hecho que el catálogo no tiene
     * (`catalog_incompatible`), con su huella correcta (no es un
     * `content_hash_mismatch`). Publicada en canario con una entrada firmada.
     *
     * @return array<string, mixed>
     */
    private function unreadableContentStrategy(): array
    {
        $leido = StrategyContent::fromArray($this->validContent($this->assistedTemplate()), (new CatalogDomains)->read());
        $this->assertNotNull($leido['canonical']);
        $json = str_replace('"membership.frozen"', '"membership.retired_fact"', (string) $leido['json']);
        $this->assertNotSame($leido['json'], $json);
        $s = $this->insertStrategy(['purpose' => 'commercial']);
        $v = $this->insertVersion($s, ['content_json' => $json, 'content_hash' => hash('sha256', $json), 'catalog_version' => OutreachCatalog::VERSION, 'studio_content_hash' => StudioCatalog::hash()]);
        $this->approveVersion($v);
        $version = DB::table('ultron_studio_strategy_versions')->where('id', $v)->first();
        $parte = ['contentHash' => (string) $version->content_hash, 'version' => (string) $version->uuid];
        $seq = $this->signedPublication($s, $v, 'publish', ['canary' => $parte, 'rest' => null, 'status' => 'active']);
        $this->setVersionStatus($v, 'CANARY');

        return [
            'strategy' => $s, 'strategyUuid' => (string) DB::table('ultron_studio_strategies')->where('id', $s)->value('uuid'),
            'version' => $v, 'versionUuid' => (string) $version->uuid, 'seq' => $seq, 'purpose' => 'commercial', 'part' => 'canary', 'v' => $version,
            'contentHash' => (string) $version->content_hash,
        ];
    }

    // ── 1 · La cadena no verifica: nadie cancela, todo espera ───────────────

    public function test_m5_con_la_firma_rota_nadie_cancela_la_vencida_espera_el_reclamado_se_cancela_chain_unreadable_y_al_volver_a_leerse_todo_sigue(): void
    {
        $w = $this->threeOpenEnrollments();
        $uuid = $w['live']['strategyUuid'];
        $this->brokenSignaturePublication($w['live']);
        $bridge = new OutreachPublishedStrategies;
        $live = $bridge->liveVersion($w['live']['version'], 'canary');
        $this->assertInstanceOf(LiveStrategy::class, $live, 'el puente conserva el estado: la estrategia no desaparece');
        $this->assertSame('signature_mismatch', $live->chainVerified);
        $this->assertFalse($live->chainReadable());
        $this->assertFalse($live->runnable());
        $this->assertNull($live->orphanReason(), 'una cadena que no verifica NO es una huérfana');
        $this->assertFalse($bridge->headVerified()['verified']);

        // El conciliador no toca nada: las tres siguen abiertas.
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(0, $r['canceledByChain'], json_encode($r));
        $this->assertSame(['active', 'active', 'active'], DB::table('outreach_enrollments')->orderBy('id')->pluck('status')->all());
        // El planificador deja ESPERAR a la vencida (D): +15 min, `waiting`, nunca `canceled`; el barrido no inscribe a nadie.
        $planner = new OutreachPlanner;
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due']);
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame(0, $r['canceled']);
        $this->assertSame('2026-11-17 15:30:00', (string) DB::table('outreach_enrollments')->where('id', $w['d']->id)->value('next_due_at'));
        $this->assertSame([], $planner->sweep(now(), true)['strategies']);
        // El despachador reclama el trabajo de B y lo cancela `chain_unreadable` (N5); la inscripción ESPERA, no se cierra.
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['canceled' => 1], $r['results']);
        $trabajoB = DB::table('outreach_jobs')->where('enrollment_id', $w['b']->id)->first();
        $this->assertSame('canceled', $trabajoB->status);
        $this->assertSame('chain_unreadable', $trabajoB->cancel_reason);
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->first();
        $this->assertSame('N5', $decision->level);
        $this->assertSame(['chain_unreadable'], json_decode((string) $decision->reasons, true));
        $b = DB::table('outreach_enrollments')->where('id', $w['b']->id)->first();
        $this->assertSame('active', $b->status);
        $this->assertNull($b->exit_reason);
        $this->assertSame('2026-11-17 15:30:00', (string) $b->next_due_at);
        // Lo enviado (A) y su inscripción, tal cual; y otra conciliación sigue sin cancelar.
        $this->assertSame('simulated', DB::table('outreach_jobs')->where('enrollment_id', $w['a']->id)->value('status'));
        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain']);
        $this->assertSame(0, DB::table('outreach_enrollments')->where('status', 'canceled')->count(), 'ninguna ocurrencia consumida por una firma rota');

        // La cadena vuelve a leerse (una entrada firmada de verdad): B y D se planifican (B, intento 2) y salen; A sigue esperando su ventana.
        $this->resumeStrategy($w['live']);
        $live = $bridge->liveVersion($w['live']['version'], 'canary');
        $this->assertTrue($live->runnable());
        $this->assertTrue($bridge->headVerified()['verified']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $r = $planner->planDue(now());
        $this->assertSame(2, $r['planned'], json_encode($r));
        $this->assertSame(2, (int) DB::table('outreach_jobs')->where('enrollment_id', $w['b']->id)->where('status', 'planned')->value('attempt'));
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:45:00', 'UTC'));
        $this->assertSame(['simulated' => 2], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame(0, DB::table('outreach_enrollments')->where('status', '<>', 'active')->count());
        $this->assertSame([1, 1, 1], DB::table('outreach_enrollments')->orderBy('id')->pluck('touches')->map(fn ($t) => (int) $t)->all());
        $barrido = $planner->sweep(now(), true)['strategies'][$uuid];
        $this->assertSame(3, $barrido['already_enrolled'], 'el barrido vuelve a correr: las tres siguen inscritas en su ciclo');
        $this->assertSame(0, $barrido['enrolled']);
    }

    public function test_m10_una_reversion_falsificada_en_la_cabeza_deja_el_par_ausente_y_nadie_cancela_hasta_que_el_dueno_retira_la_fila(): void
    {
        $w = $this->threeOpenEnrollments();
        $uuid = $w['live']['strategyUuid'];
        $seq = $this->forgedRollbackPublication($w['live']);
        $bridge = new OutreachPublishedStrategies;
        $this->assertSame([$w['live']['strategy']], $bridge->unverifiedStrategyIds(), 'la cabeza de ESA estrategia no verifica');
        $this->assertNull($bridge->liveVersion($w['live']['version'], 'canary'), 'el par (versión, parte) está AUSENTE: la cabeza falsificada dice «off»');
        $this->assertFalse($bridge->headVerified()['verified']);
        $this->assertSame('CANARY', DB::table('ultron_studio_strategy_versions')->where('id', $w['live']['version'])->value('status'), 'la falsificación no tocó la versión');

        // Par ausente + cabeza sin verificar = cadena ilegible, NO huérfana: nadie cancela.
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(0, $r['canceledByChain'], json_encode($r));
        $this->assertSame(['active', 'active', 'active'], DB::table('outreach_enrollments')->orderBy('id')->pluck('status')->all());
        $planner = new OutreachPlanner;
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame(0, $r['canceled']);
        $this->assertSame('2026-11-17 15:30:00', (string) DB::table('outreach_enrollments')->where('id', $w['d']->id)->value('next_due_at'));
        $this->assertSame([], $planner->sweep(now(), true)['strategies']);
        $this->assertSame(['canceled' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $trabajoB = DB::table('outreach_jobs')->where('enrollment_id', $w['b']->id)->first();
        $this->assertSame('chain_unreadable', $trabajoB->cancel_reason);
        $this->assertSame('N5', DB::table('outreach_decisions')->where('kind', 'cancel')->value('level'));
        $b = DB::table('outreach_enrollments')->where('id', $w['b']->id)->first();
        $this->assertSame('active', $b->status);
        $this->assertSame('2026-11-17 15:30:00', (string) $b->next_due_at);
        $this->assertSame(0, DB::table('outreach_enrollments')->where('status', 'canceled')->count(), 'la manipulación que la firma neutraliza no consume ninguna ocurrencia');
        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain']);

        // El dueño retira la fila falsificada: la cabeza vuelve a verificar, el par reaparece y todo sigue.
        $this->removeForgedRow($seq);
        $this->assertSame([], $bridge->unverifiedStrategyIds());
        $this->assertTrue($bridge->headVerified()['verified']);
        $this->assertTrue($bridge->liveVersion($w['live']['version'], 'canary')->runnable());
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $r = $planner->planDue(now());
        $this->assertSame(2, $r['planned'], json_encode($r));
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:45:00', 'UTC'));
        $this->assertSame(['simulated' => 2], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame(3, DB::table('outreach_enrollments')->where('status', 'active')->count());
        $this->assertSame(3, $planner->sweep(now(), true)['strategies'][$uuid]['already_enrolled']);
    }

    public function test_m5_una_rotacion_de_app_key_deja_toda_cadena_sin_verificar_y_nadie_cancela_hasta_que_vuelve_la_clave(): void
    {
        $w = $this->threeOpenEnrollments();
        $claveBuena = (string) config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $bridge = new OutreachPublishedStrategies;
        $live = $bridge->liveVersion($w['live']['version'], 'canary');
        $this->assertSame('signature_mismatch', $live->chainVerified);
        $this->assertNull($live->orphanReason());

        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain']);
        $r = (new OutreachPlanner)->planDue(now());
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame(0, $r['canceled']);
        $this->assertSame(['canceled' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame('chain_unreadable', DB::table('outreach_jobs')->where('enrollment_id', $w['b']->id)->value('cancel_reason'));
        $this->assertSame(3, DB::table('outreach_enrollments')->where('status', 'active')->count(), 'nada cancelado: la ocurrencia no se consume');

        // Vuelve la clave: la cadena verifica otra vez y todo sigue.
        config(['app.key' => $claveBuena]);
        $this->assertTrue($bridge->liveVersion($w['live']['version'], 'canary')->runnable());
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $this->assertSame(2, (new OutreachPlanner)->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:45:00', 'UTC'));
        $this->assertSame(['simulated' => 2], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
    }

    // ── 2 · La cadena verifica, el contenido no se lee: catalog_incompatible ─

    public function test_m5_con_la_cadena_verificando_y_el_contenido_ilegible_se_cancela_catalog_incompatible_en_los_tres_sitios(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->unreadableContentStrategy();
        $bridge = new OutreachPublishedStrategies;
        $live = $bridge->liveVersion($vivo['version'], 'canary');
        $this->assertInstanceOf(LiveStrategy::class, $live);
        $this->assertTrue($live->chainReadable(), 'la cadena verifica');
        $this->assertNull($live->content);
        $this->assertSame('catalog_incompatible', $live->contentReason);
        $this->assertSame('catalog_incompatible', $live->orphanReason());
        $this->assertFalse($live->runnable());
        $this->assertSame([], (new OutreachPlanner)->sweep(now(), true)['strategies'], 'no barre: no se reinterpreta nada');

        // Tres inscripciones abiertas de esa versión: E1 espera la ventana (sin `next_due_at`), E2 está vencida, E3 tiene un trabajo planificado.
        $e1 = $this->insertEnrollment($vivo, ['mode' => 'shadow', 'subject_key' => 'm:8001', 'member_id' => 8001, 'next_due_at' => null, 'window_ends_at' => '2026-11-30 15:00:00']);
        $e2 = $this->insertEnrollment($vivo, ['mode' => 'shadow', 'subject_key' => 'm:8002', 'member_id' => 8002, 'next_due_at' => self::NOW, 'window_ends_at' => '2026-11-30 15:00:00']);
        $e3 = $this->insertEnrollment($vivo, ['mode' => 'shadow', 'subject_key' => 'm:8003', 'member_id' => 8003, 'next_due_at' => null, 'window_ends_at' => '2026-11-30 15:00:00']);
        $trabajo = $this->insertJob($e3, ['mode' => 'shadow', 'not_before' => self::NOW, 'expires_at' => '2026-11-17 20:00:00', 'budget_day' => '2026-11-17']);

        // El conciliador: solo E1 (E2 también está sin trabajo vivo: las dos caen aquí si llega primero; E3 tiene trabajo vivo y espera al despachador).
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(2, $r['canceledByChain'], json_encode($r));
        foreach ([$e1, $e2] as $id) {
            $fila = DB::table('outreach_enrollments')->where('id', $id)->first();
            $this->assertSame('canceled', $fila->status);
            $this->assertSame('catalog_incompatible', $fila->exit_reason);
        }
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $e3)->value('status'));
        // El planificador: una vencida de esa versión se cancela con el mismo motivo (otra fila, para verlo pasar por él).
        $e4 = $this->insertEnrollment($vivo, ['mode' => 'shadow', 'subject_key' => 'm:8004', 'member_id' => 8004, 'next_due_at' => self::NOW, 'window_ends_at' => '2026-11-30 15:00:00']);
        $r = (new OutreachPlanner)->planDue(now());
        $this->assertSame(1, $r['canceled'], json_encode($r));
        $this->assertSame('catalog_incompatible', DB::table('outreach_enrollments')->where('id', $e4)->value('exit_reason'));
        // El despachador: el trabajo reclamado se cancela `catalog_incompatible` (N5) y su inscripción se cierra con el mismo motivo.
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['canceled' => 1], $r['results']);
        $fila = DB::table('outreach_jobs')->where('id', $trabajo)->first();
        $this->assertSame('canceled', $fila->status);
        $this->assertSame('catalog_incompatible', $fila->cancel_reason);
        $this->assertSame(['catalog_incompatible'], json_decode((string) DB::table('outreach_decisions')->where('kind', 'cancel')->value('reasons'), true));
        $this->assertSame('canceled', DB::table('outreach_enrollments')->where('id', $e3)->value('status'));
        $this->assertSame('catalog_incompatible', DB::table('outreach_enrollments')->where('id', $e3)->value('exit_reason'));
        $this->assertSame(0, DB::table('outreach_enrollments')->whereIn('status', ['active', 'waiting'])->count());
        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain'], 'idempotente');
    }

    // ── 3 · Promoción canario → resto (Q-M4) ────────────────────────────────

    public function test_q_m4_la_promocion_deja_huerfanas_las_inscripciones_de_la_parte_canario_sin_trabajo_vivo_y_las_de_la_parte_resto_siguen(): void
    {
        $w = $this->threeOpenEnrollments();
        $uuid = $w['live']['strategyUuid'];
        $this->assertSame(['canary', 'canary', 'canary'], DB::table('outreach_enrollments')->orderBy('id')->pluck('part')->all());
        $seq = $this->promoteStrategy($w['live']);
        $bridge = new OutreachPublishedStrategies;
        $this->assertNull($bridge->liveVersion($w['live']['version'], 'canary'), 'la parte canario queda sin vida');
        $resto = $bridge->liveVersion($w['live']['version'], 'rest');
        $this->assertTrue($resto->runnable());
        $this->assertNull($resto->orphanReason());

        // Una persona nueva entra por la parte resto; las tocadas en canario no vuelven a entrar en el mismo ciclo (L9-1).
        $c = $this->qualifyingMember(self::utcAt(self::NOW), 3, '3001234570');
        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$uuid];
        $this->assertSame(1, $r['enrolled']);
        $this->assertSame(3, $r['already_enrolled']);
        $deC = DB::table('outreach_enrollments')->where('subject_key', $c['key'])->first();
        $this->assertSame('rest', $deC->part);
        $this->assertSame($seq, (int) $deC->publication_seq);

        // La conciliación: A (canario, sin trabajo vivo) y D (canario, sin trabajo) → `canceled`/`strategy_moved`; B (trabajo vivo) y C (resto) siguen.
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(2, $r['canceledByChain'], json_encode($r));
        foreach ([$w['a']->id, $w['d']->id] as $id) {
            $fila = DB::table('outreach_enrollments')->where('id', $id)->first();
            $this->assertSame('canceled', $fila->status);
            $this->assertSame('strategy_moved', $fila->exit_reason);
        }
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $w['b']->id)->value('status'));
        $this->assertSame('active', $deC->status);
        $this->assertSame('simulated', DB::table('outreach_jobs')->where('enrollment_id', $w['a']->id)->value('status'), 'lo enviado en canario no se toca');

        // El resto sigue: C se planifica y sale; el trabajo de B lo cancela el despachador (`strategy_moved`) y cierra su inscripción.
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $r = (new OutreachDispatcher)->dispatch(now(), null, true)['results'];
        $this->assertSame(1, $r['canceled'] ?? 0, json_encode($r));
        $this->assertSame(1, $r['simulated'] ?? 0, json_encode($r));
        $this->assertSame('strategy_moved', DB::table('outreach_jobs')->where('enrollment_id', $w['b']->id)->value('cancel_reason'));
        $this->assertSame('canceled', DB::table('outreach_enrollments')->where('id', $w['b']->id)->value('status'));
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $deC->id)->value('status'));
        $this->assertSame(1, (int) DB::table('outreach_enrollments')->where('id', $deC->id)->value('touches'));
        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain']);
        $this->assertSame(1, DB::table('outreach_enrollments')->whereIn('status', ['active', 'waiting'])->count(), 'solo la del resto sigue abierta');
    }
}
