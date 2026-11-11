<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAS CARRERAS DE PUBLICAR EN `/2` (Enmienda M-1; plan de motores E13, «carreras
 * de publicar en `/2` como en H5»), con las sesiones reales de PostgreSQL del
 * H5 ({@see PublicationRaceSessions}) y la llave de prompts del motor encendida
 * en la prueba y en cada hijo.
 *
 * Lo que se afirma, en cualquier orden del planificador:
 *  - una sola entrada nueva, en `/2`, cuyo paquete firmado es EXACTAMENTE el de
 *    la versión ganadora (ninguna sesión firma lo que calculó otra);
 *  - nada a medias, la cadena verifica entera (huella, firma, forma atada a
 *    uuid/seq, contrato del texto = columna) y el lector y la oferta REALES de
 *    `decide` la leen;
 *  - volver a Legacy en plena carrera con una publicación escribe `/1`.
 *
 * Seis sesiones (no veinte como el H5): la máquina de pruebas es pequeña; lo
 * que se prueba aquí es el contrato nuevo de la escritura, no la tormenta, que
 * ya cubre `UltronStudioPublicationRaceTest`. Solo PostgreSQL.
 */
class UltronStudioPublicationPromptsRaceTest extends TestCase
{
    use PublicationFixtures;
    use PublicationRaceSessions;
    use RefreshDatabase;
    use StudioFixtures;

    /** Sin transacción envolvente: los hijos tienen que ver lo que siembra la prueba. */
    protected array $connectionsToTransact = [];

    private const BARRIER = 716001;

    private const N = 6;

    private Admin $ana;

    private Admin $root;

    private Admin $pablo;

    private Admin $paula;

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'pgsql') {
            RefreshDatabaseState::$migrated = false;
        }
        parent::setUp();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Sesiones rivales de verdad y cerrojos consultivos: solo PostgreSQL.');
        }
        $this->confirmoFuera = true;
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00Z'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03T15:00:00Z'));
        $this->openKeys(['marketing.ultron.studio_prompts' => 'canary']);
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $this->pablo = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
        $this->paula = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Paula Publica');
    }

    protected function tearDown(): void
    {
        $this->stopRacers();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_la_misma_publicacion_en_seis_sesiones_deja_una_entrada_v2_y_cinco_replayed(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Carrera G8', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
        $cuerpo = $this->publishBody($this->pablo, $v, (string) Str::uuid());

        $peticiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $peticiones[] = ["p{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo)];
        }
        $r = $this->raceAll($peticiones, self::BARRIER);

        $this->assertRacersClean($r);
        $creadas = array_values(array_filter($r, static fn (array $x): bool => $x['status'] === 201));
        $this->assertCount(1, $creadas, 'una sola 201: '.$this->summary($r));
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, $creadas[0]['json']['data']], [$x['status'], $x['json']['replayed'] ?? null, $x['json']['data'] ?? null], $this->summary($r));
            }
        }

        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies([D::CONTRACT_V2]);
        $this->assertSignedBundleIsTheVersions($v);
        $this->assertDecideOffers($v);
    }

    public function test_seis_versiones_distintas_a_la_vez_firman_solo_el_paquete_de_la_ganadora(): void
    {
        // Seis versiones con seis paquetes distintos (longitudes distintas).
        $versiones = [];
        foreach ([160, 200, 240, 280, 360, 400] as $n) {
            $versiones[] = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['maxChars' => $n]]));
        }
        $peticiones = [];
        foreach ($versiones as $i => $v) {
            $quien = $i % 2 === 0 ? $this->paula : $this->pablo;
            $peticiones[] = ['v'.($i + 1), $this->raceRequest('POST', "versions/{$v->uuid}/publish", $quien, $this->publishBody($quien, $v))];
        }
        $r = $this->raceAll($peticiones, self::BARRIER);

        $this->assertRacersClean($r);
        $creadas = array_values(array_filter($r, static fn (array $x): bool => $x['status'] === 201));
        $this->assertCount(1, $creadas, 'una sola 201: '.$this->summary($r));
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame(409, $x['status'], $this->summary($r));
                $this->assertContains($x['json']['code'], ['publication_moved', 'canary_in_progress'], $this->summary($r));
            }
        }

        $ganadora = StudioVersion::query()->where('uuid', $creadas[0]['json']['data']['version']['uuid'])->sole();
        $this->assertSame([$ganadora->uuid], StudioVersion::query()->where('status', 'CANARY')->pluck('uuid')->all());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies([D::CONTRACT_V2]);
        $this->assertSignedBundleIsTheVersions($ganadora);
        $this->assertDecideOffers($ganadora);
    }

    public function test_publicar_y_volver_a_legacy_a_la_vez_dejan_la_cadena_coherente_y_legacy_en_v1(): void
    {
        // Sin cadena: Legacy, si corre antes, no tiene nada que retirar
        // (`already_legacy`); si corre después, retira la canaria recién
        // publicada. Las dos son válidas; lo que importa es el contrato de cada
        // entrada y que la cadena quede coherente.
        $v = $this->approvedVersion($this->ana, $this->root, 'Nueva', ['persona' => ['emojis' => false]]);
        $publicar = $this->publishBody($this->paula, $v);
        $antes = 0;

        $r = $this->raceAll([
            ['publicar', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->paula, $publicar)],
            ['legacy', $this->raceRequest('POST', 'publications/legacy', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Carrera con Legacy'])],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $porEtiqueta = array_column($r, null, 'label');
        $this->assertSame(201, $porEtiqueta['publicar']['status'], $this->summary($r));
        // Legacy: o retira la canaria recién publicada (201) o no tenía nada que retirar (409 already_legacy).
        $this->assertContains($porEtiqueta['legacy']['status'], [201, 409], $this->summary($r));
        if ($porEtiqueta['legacy']['status'] === 409) {
            $this->assertSame('already_legacy', $porEtiqueta['legacy']['json']['code']);
        }

        $nuevas = StudioPublication::query()->where('seq', '>', $antes)->orderBy('seq')->get();
        foreach ($nuevas as $f) {
            $this->assertSame($f->action === D::ACTION_LEGACY ? D::CONTRACT : D::CONTRACT_V2, $f->engine_contract, "{$f->action} en su contrato");
        }
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies([D::CONTRACT, D::CONTRACT_V2]);
        $cabeza = $this->chainHead();
        if ($cabeza->action === D::ACTION_LEGACY) {
            $this->assertSame('part_legacy', $this->offer()['reason']);
        } else {
            $this->assertDecideOffers($v);
        }
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function publishBody(Admin $as, StudioVersion $v, ?string $clientRequestId = null): array
    {
        $vista = $this->previewOf($as, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame('prompts', $vista['effect']);

        return ['clientRequestId' => $clientRequestId ?? (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']];
    }

    /** @return array{mode:string,version:?string,bundle_hash:?string,reason:?string} */
    private function offer(): array
    {
        $o = app(UltronPromptOffer::class)->forConversation(self::CANARY_ID)['response'];

        return ['mode' => $o['mode'], 'version' => $o['version'], 'bundle_hash' => $o['bundle_hash'], 'reason' => $o['reason']];
    }

    /** La parte canaria de la cabeza lleva el paquete que compila el contenido de ESA versión. */
    private function assertSignedBundleIsTheVersions(StudioVersion $v): void
    {
        $cabeza = $this->chainHead();
        $parte = D::entry($cabeza->effective_json, $cabeza->uuid, (int) $cabeza->seq)['canary'];
        $paquete = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($v->fresh()->content));
        $this->assertSame($v->uuid, $parte['version']);
        $this->assertSame($paquete['governed'], $parte['governed']);
        $this->assertSame($paquete['prompts'], $parte['prompts']);
    }

    /** El lector y la oferta REALES de `decide` aplican la versión, con su paquete, y `status` dice lo mismo. */
    private function assertDecideOffers(StudioVersion $v): void
    {
        $this->assertSame(['applied', $v->uuid], [app(UltronPublishedConfig::class)->forConversation(self::CANARY_ID)['state'], app(UltronPublishedConfig::class)->forConversation(self::CANARY_ID)['version']]);
        $paquete = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($v->fresh()->content));
        $this->assertSame(['mode' => 'studio', 'version' => $v->uuid, 'bundle_hash' => $paquete['prompts']['bundleHash'], 'reason' => null], $this->offer());
        $this->assertSame(
            ['mode' => 'studio', 'version' => $v->uuid, 'bundleHash' => $paquete['prompts']['bundleHash'], 'reason' => null],
            $this->studio('GET', 'status', $this->pablo)->assertOk()->json('data.engine.prompts.current'),
        );
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function assertRacersClean(array $resultados): void
    {
        foreach ($resultados as $x) {
            $this->assertNull($x['error'], "{$x['label']} lanzó: ".json_encode($x['error']));
            $this->assertIsInt($x['status'], "{$x['label']} sin estado");
            $this->assertLessThan(500, $x['status'], "{$x['label']}: ".json_encode($x['json']));
            $this->assertIsArray($x['json'], "{$x['label']} no respondió JSON");
        }
        $pids = array_column($resultados, 'pid');
        $this->assertSame(count($pids), count(array_unique($pids)), 'dos sesiones compartieron conexión');
        $this->assertNotContains((int) DB::scalar('select pg_backend_pid()'), $pids);
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function summary(array $resultados): string
    {
        return json_encode(array_map(static fn (array $x): array => [
            $x['label'], $x['status'], $x['json']['code'] ?? (($x['json']['replayed'] ?? false) ? 'replayed' : null),
        ], $resultados));
    }

    /** Cada entrada tiene su evento principal del libro y ningún evento nombra una entrada que no existe. */
    private function assertNothingHalfDone(): void
    {
        $entradas = StudioPublication::query()->orderBy('seq')->get(['uuid', 'action']);
        $porEntrada = [];
        foreach (StudioAuditEvent::query()->whereNotNull('diff')->get(['event', 'diff']) as $e) {
            if (is_array($e->diff) && isset($e->diff['entry'])) {
                $porEntrada[(string) $e->diff['entry']][] = $e->event;
            }
        }
        $uuids = $entradas->pluck('uuid')->all();
        foreach (array_keys($porEntrada) as $entry) {
            $this->assertContains($entry, $uuids, "un evento nombra la entrada {$entry}, que no está en la cadena");
        }
        $principal = [D::ACTION_PUBLISH => 'version.published', D::ACTION_PROMOTE => 'version.promoted', D::ACTION_ROLLBACK => 'version.rolled_back', D::ACTION_LEGACY => 'system.publication_legacy'];
        foreach ($entradas as $p) {
            $this->assertSame(1, count(array_keys($porEntrada[$p->uuid] ?? [], $principal[$p->action], true)), "la entrada {$p->uuid} ({$p->action})");
        }
    }

    /**
     * La cadena entera: `seq` sin huecos y enlazada; cada entrada verifica como
     * la verifica el motor y su texto dice el contrato de su columna, que es uno
     * de los permitidos.
     *
     * @param  list<string>  $contratos
     */
    private function assertChainVerifies(array $contratos): void
    {
        $filas = StudioPublication::query()->orderBy('seq')->get();
        $n = $filas->count();
        $this->assertSame(range(1, $n), $filas->pluck('seq')->all(), 'seq con huecos o duplicados');
        $this->assertSame([null, ...$filas->pluck('id')->take($n - 1)->all()], $filas->pluck('previous_publication_id')->all());
        foreach ($filas as $f) {
            $this->assertSame(hash('sha256', $f->effective_json), $f->effective_hash, "huella de la entrada {$f->seq}");
            $this->assertSame(UltronPublishedConfig::signatureFor($f->effective_json), $f->signature, "firma de la entrada {$f->seq}");
            $this->assertSame($f->engine_contract, D::entry($f->effective_json, $f->uuid, (int) $f->seq)['contract']);
        }
        $this->assertContains($filas->last()->engine_contract, $contratos);
        $cabeza = app(UltronPublishedConfig::class)->describe()['head'];
        $this->assertSame([true, true, true], [$cabeza['hashOk'], $cabeza['signatureOk'], $cabeza['parseOk']], 'la cabeza no verifica para el motor');
    }
}
