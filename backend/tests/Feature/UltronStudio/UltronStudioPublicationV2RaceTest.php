<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
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
 * LA PARTE DEL RESTO QUE CAMBIA ENTRE LA VISTA Y LA TRANSACCIÓN (tester; §6 del
 * informe de B-S; Enmienda M-1, M1-12), con las sesiones reales de PostgreSQL
 * del H5 ({@see PublicationRaceSessions}) y la llave de prompts encendida.
 *
 * Con la llave encendida, la vista previa enseña (y `previewHash` ata) la parte
 * del resto que la entrada nueva va a arrastrar. Si entre `facts()` (las
 * puertas, fuera de la transacción) y la transacción otra sesión cambia esa
 * parte, publicar no puede firmar un arrastre que nadie vio: 409
 * `publication_moved`, sin escribir nada. La sesión que publica se para JUSTO
 * antes de su primera sentencia sobre la cadena dentro de la transacción (ya ha
 * pasado las puertas); la prueba vuelve a Legacy (el resto pasa de P a nada) y
 * la suelta. Control: la misma pausa sin mover la cabeza publica en `/2` con el
 * arrastre que enseñó la vista. Solo PostgreSQL.
 */
class UltronStudioPublicationV2RaceTest extends TestCase
{
    use PublicationFixtures;
    use PublicationRaceSessions;
    use RefreshDatabase;
    use StudioFixtures;

    /** Sin transacción envolvente: los hijos tienen que ver lo que siembra la prueba. */
    protected array $connectionsToTransact = [];

    private const RELEASE = 716101;

    /** Lo primero que la publicación hace sobre la cadena DENTRO de la transacción (como el H5). */
    private const FIRST_CHAIN_STATEMENT = ['"ultron_studio_publications"'];

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

    public function test_si_la_parte_del_resto_cambia_entre_la_vista_y_la_transaccion_es_publication_moved_y_no_se_escribe(): void
    {
        [$p, $v, $cuerpo] = $this->publishedRestAndNewVersion();
        $antes = (int) StudioPublication::query()->max('seq');

        $this->holdLock(self::RELEASE);
        try {
            $i = $this->spawnRacer('publicar', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo), null,
                self::pauseBefore(self::FIRST_CHAIN_STATEMENT, true, 'released', self::RELEASE));
            $this->awaitRacerLine($i, 'PAUSED', 120.0);
            // Ya pasó las puertas con la cabeza {P, P}. Otra persona vuelve a Legacy: el resto pasa de P a nada.
            $this->legacyAs($this->paula, 'Legacy mientras otra sesión publica')->assertCreated();
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $r = $this->collectRacer($i);

        $this->assertRacersClean([$r]);
        $this->assertTrue($r['paused'], 'la sesión no llegó a la transacción');
        $this->assertSame([409, 'publication_moved'], [$r['status'], $r['json']['code'] ?? null], $this->summary([$r]));
        // Nada a medias: solo la entrada de Legacy, en /1; la versión sigue APROBADA; la cadena verifica.
        $this->assertSame(0, StudioPublication::query()->where('client_request_id', $cuerpo['clientRequestId'])->count());
        $this->assertSame([D::ACTION_LEGACY], StudioPublication::query()->where('seq', '>', $antes)->orderBy('seq')->pluck('action')->all());
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);
        $this->assertSame('APPROVED', $v->fresh()->status);
        $this->assertSame('RETIRED', $p->fresh()->status);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies();
        $this->assertSame(['legacy', 'part_legacy'], $this->decideOffer());
    }

    public function test_control_la_misma_pausa_sin_mover_la_cabeza_publica_en_v2_con_el_arrastre_que_enseno_la_vista(): void
    {
        [$p, $v, $cuerpo, $arrastre] = $this->publishedRestAndNewVersion();

        $this->holdLock(self::RELEASE);
        try {
            $i = $this->spawnRacer('publicar', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo), null,
                self::pauseBefore(self::FIRST_CHAIN_STATEMENT, true, 'released', self::RELEASE));
            $this->awaitRacerLine($i, 'PAUSED', 120.0);
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $r = $this->collectRacer($i);

        $this->assertRacersClean([$r]);
        $this->assertTrue($r['paused']);
        $this->assertSame(201, $r['status'], $this->summary([$r]));
        $cabeza = $this->chainHead();
        $e = D::entry((string) $cabeza->effective_json, (string) $cabeza->uuid, (int) $cabeza->seq);
        $this->assertSame([D::CONTRACT_V2, D::CONTRACT_V2], [$cabeza->engine_contract, $e['contract']]);
        $this->assertSame([$v->uuid, $p->uuid], [$e['canary']['version'], $e['rest']['version']]);
        $this->assertSame($arrastre, $e['rest']['prompts']['bundleHash'], 'se firma el arrastre que enseñó la vista');
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies();
        $this->assertSame(['studio', null], $this->decideOffer());
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * P PUBLISHED (tono entusiasta), promovida antes de M-1 (cabeza `/1` {P, P}),
     * y V (el cambio de aceptación) APROBADA, con el cuerpo de publicar sacado
     * de su vista previa (que enseña el arrastre de P).
     *
     * @return array{0:StudioVersion,1:StudioVersion,2:array<string,mixed>,3:string}
     */
    private function publishedRestAndNewVersion(): array
    {
        $p = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['tone' => 'entusiasta']]), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PROMOTE, $p, $this->partOf($p), $this->partOf($p));
        $v = $this->approvedVersion($this->ana, $this->root, 'G8 en carrera', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);

        $vista = $this->previewOf($this->pablo, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame($p->uuid, $vista['prompts']['carried']['versionUuid'] ?? null, 'la vista enseña el arrastre de P');

        return [$p, $v, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']], $vista['prompts']['carried']['bundleHash']];
    }

    /** @return array{0:string,1:?string} */
    private function decideOffer(): array
    {
        $o = app(UltronPromptOffer::class)->forConversation(self::CANARY_ID)['response'];

        return [$o['mode'], $o['reason']];
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
        $this->assertNotContains((int) DB::scalar('select pg_backend_pid()'), array_column($resultados, 'pid'));
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function summary(array $resultados): string
    {
        return json_encode(array_map(static fn (array $x): array => [$x['label'], $x['status'], $x['json']['code'] ?? null], $resultados));
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
        $principal = [D::ACTION_PUBLISH => 'version.published', D::ACTION_LEGACY => 'system.publication_legacy'];
        foreach ($entradas as $e) {
            if (isset($principal[$e->action])) {
                $this->assertSame(1, count(array_keys($porEntrada[$e->uuid] ?? [], $principal[$e->action], true)), "la entrada {$e->uuid} ({$e->action})");
            }
        }
    }

    /** La cadena entera verifica como la verifica el motor: huella, firma, contrato del texto = columna. */
    private function assertChainVerifies(): void
    {
        $filas = StudioPublication::query()->orderBy('seq')->get();
        $this->assertSame(range(1, $filas->count()), $filas->pluck('seq')->all(), 'seq con huecos o duplicados');
        foreach ($filas as $f) {
            $this->assertSame(hash('sha256', $f->effective_json), $f->effective_hash, "huella de la entrada {$f->seq}");
            $this->assertSame(UltronPublishedConfig::signatureFor($f->effective_json), $f->signature, "firma de la entrada {$f->seq}");
            $this->assertSame($f->engine_contract, D::entry($f->effective_json, $f->uuid, (int) $f->seq)['contract']);
        }
        $cabeza = app(UltronPublishedConfig::class)->describe()['head'];
        $this->assertSame([true, true, true], [$cabeza['hashOk'], $cabeza['signatureOk'], $cabeza['parseOk']], 'la cabeza no verifica para el motor');
    }
}
