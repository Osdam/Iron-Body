<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LAS CARRERAS DE LA CADENA CON SESIONES REALES DE POSTGRESQL (contrato H5
 * §A12, fila «PostgreSQL»; §A2 invariante; §A5.1 pasos 3 y 12; §A10.6).
 *
 * Cada lado de cada carrera es OTRA SESIÓN de la base: un proceso hijo que
 * manda la petición por el kernel HTTP con la sesión real de una persona
 * ({@see PublicationRaceSessions}). Dos maneras de juntarlas:
 *  - NATURAL: N sesiones esperan en una barrera de la base y salen a la vez;
 *    el orden lo decide el planificador, y lo que se afirma es lo que tiene
 *    que valer en CUALQUIER orden (una sola entrada nueva, `seq` sin huecos,
 *    estados ↔ cabeza, libro ↔ cadena, la cabeza verifica y el motor la aplica).
 *  - FORZADA: una sesión se detiene en un punto exacto de su transacción hasta
 *    que la rival espera uno de sus cerrojos (`pg_blocking_pids` + escalón de
 *    40 ms), o hasta que la rival ya confirmó. Así cada camino de §A5.1 paso 12
 *    se recorre con certeza: el 23505 de `seq` en una transacción de verdad
 *    (no en un punto de guardado), el 23505 de `usv_one_canary`, la cabeza
 *    movida que ya no compone y el estado movido.
 *
 * Después de cada carrera: nada a medias (la transacción abortada no deja
 * entrada, transición ni evento; todo evento con `entry` apunta a una entrada
 * que existe y cada entrada tiene su evento), la invariante de §A2, la cadena
 * entera verifica (huella, firma del motor, forma atada a uuid/seq, enlaces) y
 * el LECTOR REAL del motor aplica la cabeza.
 *
 * Solo PostgreSQL (SQLite escribe en serie y no tiene cerrojos consultivos).
 * Los hijos ven solo lo confirmado: aquí no hay transacción envolvente y cada
 * prueba empieza con el esquema recién creado.
 */
class UltronStudioPublicationRaceTest extends TestCase
{
    use PublicationFixtures;
    use PublicationRaceSessions;
    use RefreshDatabase;
    use StudioFixtures;

    /** Sin transacción envolvente: los hijos tienen que ver lo que siembra la prueba. */
    protected array $connectionsToTransact = [];

    /** Cerrojos consultivos de la coordinación (clave bigint pequeña: classid 0, objid = clave). */
    private const BARRIER = 715001;

    private const RELEASE = 715003;

    /** Las 20 sesiones simultáneas de §A12. */
    private const N = 20;

    /** Otra conversación cualquiera (no la canaria). */
    private const OTHER_CONVERSATION = 4242;

    /**
     * DENTRO de la transacción de la escritura, la primera sentencia sobre la
     * cadena: hoy es la lectura de la cabeza. Se elige por la tabla y no por el
     * texto de la consulta, para no depender de cómo esté escrita (y para que,
     * si alguien sacara esa lectura fuera de la transacción, la pausa cayera en
     * la inserción y la prueba lo viera por su resultado).
     */
    private const FIRST_CHAIN_STATEMENT = ['"ultron_studio_publications"'];

    /** FUERA de la transacción, la lectura de la cabeza (Legacy la comprueba antes de abrirla; el reintento no ordena por `seq`). */
    private const HEAD_READ = ['from "ultron_studio_publications" order by "seq" desc'];

    private Admin $ana;

    private Admin $root;

    private Admin $pablo;

    private Admin $paula;

    /** La prueba confirmó filas fuera de toda transacción: la siguiente tiene que recrear el esquema. */
    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        // Cada prueba confirma filas que no se pueden borrar (la cadena y el libro
        // prohíben DELETE y TRUNCATE): en PostgreSQL, esquema nuevo siempre. En
        // SQLite la prueba se omite y no se toca el estado de las demás.
        if (getenv('DB_CONNECTION') === 'pgsql') {
            RefreshDatabaseState::$migrated = false;
        }
        parent::setUp();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Sesiones rivales de verdad y cerrojos consultivos: solo PostgreSQL (SQLite escribe en serie).');
        }
        $this->confirmoFuera = true;
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $this->pablo = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
        $this->paula = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Paula Promueve');
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

    private static function at(string $iso): void
    {
        Carbon::setTestNow(Carbon::parse($iso)->utc());
        CarbonImmutable::setTestNow(CarbonImmutable::parse($iso)->utc());
    }

    // ── 20 a la vez sobre la misma versión ──────────────────────────────────

    public function test_veinte_publicaciones_simultaneas_con_el_mismo_client_request_id_dejan_una_entrada_y_diecinueve_replayed(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Carrera');
        $cuerpo = $this->publishBody($this->pablo, $v, $crid = (string) Str::uuid());

        // La misma persona en 20 pestañas (20 sesiones), la misma petición.
        $peticiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $peticiones[] = ["p{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo)];
        }
        $r = $this->raceAll($peticiones, self::BARRIER);

        $this->assertRacersClean($r);
        $creadas = array_values(array_filter($r, static fn (array $x): bool => $x['status'] === 201));
        $this->assertCount(1, $creadas, 'una sola 201: '.$this->summary($r));
        $entrada = $creadas[0]['json']['data'];

        // Primero la integridad (vale aunque falle lo de abajo)…
        $fila = StudioPublication::query()->sole();
        $this->assertSame([$entrada['uuid'], 1, $crid], [$fila->uuid, $fila->seq, $fila->client_request_id]);
        $this->assertSame('CANARY', $v->fresh()->status);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.published')->where('subject_uuid', $v->uuid)->count(), 'la versión entró en CANARY más de una vez en el libro');
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($v->uuid, null);

        // …y después el contrato de los reintentos (§A5.1 paso 3).
        foreach ($r as $x) {
            if ($x['status'] === 201) {
                continue;
            }
            $this->assertSame([200, true, $entrada], [$x['status'], $x['json']['replayed'] ?? null, $x['json']['data'] ?? null], "{$x['label']}: el reintento no devolvió la entrada de la primera vez: ".$this->summary($r));
        }
    }

    public function test_veinte_publicaciones_simultaneas_con_distintos_client_request_id_dan_una_201_y_el_resto_409(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Carrera');

        // Dos personas, diez pestañas cada una, cada envío con su identificador.
        $peticiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $quien = $i % 2 === 0 ? $this->paula : $this->pablo;
            $peticiones[] = ["p{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $quien, $this->publishBody($quien, $v))];
        }
        $r = $this->raceAll($peticiones, self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertCount(1, array_filter($r, static fn (array $x): bool => $x['status'] === 201), 'una sola 201: '.$this->summary($r));
        foreach ($r as $x) {
            if ($x['status'] === 201) {
                continue;
            }
            $this->assertSame(409, $x['status'], "{$x['label']}: ".$this->summary($r));
            // La cabeza la ganó otra (`seq`), o la versión ya está en CANARY (las puertas o la transacción la vieron movida).
            $this->assertContains($x['json']['code'], ['publication_moved', 'invalid_transition'], "{$x['label']}: ".$this->summary($r));
            if ($x['json']['code'] === 'invalid_transition') {
                $this->assertSame('CANARY', $x['json']['currentStatus']);
            }
        }

        $this->assertSame(1, StudioPublication::query()->count());
        $this->assertSame('CANARY', $v->fresh()->status);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.published')->count());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($v->uuid, null);
    }

    public function test_veinte_versiones_distintas_publicadas_a_la_vez_dejan_una_sola_en_canario(): void
    {
        $versiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $versiones[] = $this->seededVersion($this->ana, $this->root, $this->baseline());
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
                $this->assertSame(409, $x['status'], "{$x['label']}: ".$this->summary($r));
                $this->assertContains($x['json']['code'], ['publication_moved', 'canary_in_progress'], "{$x['label']}: ".$this->summary($r));
            }
        }

        $ganadora = $creadas[0]['json']['data']['version']['uuid'];
        $this->assertSame([$ganadora], StudioVersion::query()->where('status', 'CANARY')->pluck('uuid')->all());
        $this->assertSame(self::N - 1, StudioVersion::query()->where('status', 'APPROVED')->whereIn('uuid', array_column($versiones, 'uuid'))->count());
        $this->assertSame(1, StudioPublication::query()->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.published')->count());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($ganadora, null);
    }

    /**
     * LAS 20 CHOCANDO A LA VEZ EN LA ENTRADA DE LA GANADORA. En la natural, el
     * planificador separa a las sesiones y muchas ya ven la confirmación de la
     * primera. Aquí no: las 20 pasan sus puertas y se paran justo antes de leer
     * la cabeza dentro de su transacción; la prueba las suelta juntas; la
     * primera que inserta no confirma hasta que las otras 19 esperan su
     * entrada. Al confirmar, 19 transacciones de verdad abortan a la vez por el
     * 23505 y lo resuelven fuera.
     *
     * @return array<string, array{0:bool}>
     */
    public static function stormOnTheWinnersEntry(): array
    {
        return ['20 versiones distintas' => [false], 'la misma petición 20 veces' => [true]];
    }

    #[DataProvider('stormOnTheWinnersEntry')]
    public function test_veinte_sesiones_que_chocan_a_la_vez_en_la_entrada_de_la_ganadora_dejan_una_sola(bool $mismaPeticion): void
    {
        $peticiones = [];
        if ($mismaPeticion) {
            $v = $this->approvedVersion($this->ana, $this->root, 'Tormenta');
            $cuerpo = $this->publishBody($this->pablo, $v);
            for ($i = 1; $i <= self::N; $i++) {
                $peticiones[] = ["p{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo)];
            }
        } else {
            for ($i = 1; $i <= self::N; $i++) {
                $v = $this->seededVersion($this->ana, $this->root, $this->baseline());
                $quien = $i % 2 === 0 ? $this->paula : $this->pablo;
                $peticiones[] = ["v{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $quien, $this->publishBody($quien, $v))];
            }
        }

        $this->holdLock(self::RELEASE);
        $ids = [];
        try {
            foreach ($peticiones as [$etiqueta, $peticion]) {
                $ids[] = $this->spawnRacer($etiqueta, $peticion, null, [
                    self::pauseBefore(self::FIRST_CHAIN_STATEMENT, true, 'released', self::RELEASE),
                    self::pauseBefore(['insert into "ultron_studio_audit_events"'], true, 'blocked', null, 60.0, self::N - 1),
                ]);
            }
            foreach ($ids as $i) {
                $this->awaitRacerLine($i, 'PAUSED', 120.0);
            }
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $r = $this->collectRacers($ids, 180.0);

        $this->assertRacersClean($r);
        $creadas = array_values(array_filter($r, static fn (array $x): bool => $x['status'] === 201));
        $this->assertCount(1, $creadas, 'una sola 201: '.$this->summary($r));
        $this->assertSame(self::N - 1, $creadas[0]['waiters'], 'cuando la ganadora confirmó, no la esperaban las otras 19');
        $entrada = $creadas[0]['json']['data'];
        foreach ($r as $x) {
            if ($x['status'] === 201) {
                continue;
            }
            $this->assertSame([0], $x['pausedAt'], "{$x['label']} pasó de su inserción");
            if ($mismaPeticion) {
                $this->assertSame([200, true, $entrada], [$x['status'], $x['json']['replayed'] ?? null, $x['json']['data'] ?? null], "{$x['label']}: ".$this->summary($r));
            } else {
                $this->assertSame([409, 'publication_moved'], [$x['status'], $x['json']['code'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }

        $this->assertSame(1, StudioPublication::query()->count());
        $this->assertSame([$entrada['version']['uuid']], StudioVersion::query()->where('status', 'CANARY')->pluck('uuid')->all());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.published')->count());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($entrada['version']['uuid'], null);
    }

    // ── Dos versiones a la vez, forzadas ────────────────────────────────────

    public function test_la_que_espera_el_seq_de_la_ganadora_recibe_publication_moved_y_su_transaccion_abortada_no_deja_nada(): void
    {
        $v1 = $this->approvedVersion($this->ana, $this->root, 'Primera');
        $v2 = $this->seededVersion($this->ana, $this->root, $this->baseline());

        // A inserta su entrada (seq 1) y se para antes de confirmar, hasta que B
        // espere su cerrojo. B lee la misma cabeza (vacía), inserta seq 1 y se
        // queda esperando a A; cuando A confirma, a B le salta el 23505 de `seq`
        // en una transacción de verdad.
        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', "versions/{$v1->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v1))],
            ['B', $this->raceRequest('POST', "versions/{$v2->uuid}/publish", $this->paula, $this->publishBody($this->paula, $v2))],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo de A: la carrera no ocurrió');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'publication_moved'], [$b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));

        // Nada de B: ni entrada, ni transición, ni evento.
        $this->assertSame([1, $a['json']['data']['uuid']], [StudioPublication::query()->count(), StudioPublication::query()->value('uuid')]);
        $this->assertSame(['CANARY', 'APPROVED'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertSame(0, StudioAuditEvent::query()->where('subject_uuid', $v2->uuid)->where('event', 'version.published')->count());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($v1->uuid, null);
    }

    /**
     * B ya pasó sus puertas (con la cadena vacía) y lee la cabeza DENTRO de su
     * transacción después de que A se confirmó: la cabeza ya no compone.
     *
     * @return array<string, array{0:bool,1:string,2:string|null}>
     */
    public static function headMovedUnderneath(): array
    {
        return [
            'otra versión' => [false, 'canary_in_progress', null],
            'la misma versión, otra petición' => [true, 'invalid_transition', 'CANARY'],
        ];
    }

    #[DataProvider('headMovedUnderneath')]
    public function test_la_que_lee_la_cabeza_despues_de_la_confirmacion_rival_no_compone_encima(bool $mismaVersion, string $codigo, ?string $estadoAhora): void
    {
        $v1 = $this->approvedVersion($this->ana, $this->root, 'Primera');
        $v2 = $mismaVersion ? $v1 : $this->seededVersion($this->ana, $this->root, $this->baseline());

        [$a, $b] = $this->readsHeadAfter(
            ['B', $this->raceRequest('POST', "versions/{$v2->uuid}/publish", $this->paula, $this->publishBody($this->paula, $v2))],
            ['A', $this->raceRequest('POST', "versions/{$v1->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v1))],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, $codigo, $estadoAhora], [$b['status'], $b['json']['code'] ?? null, $b['json']['currentStatus'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(1, StudioPublication::query()->count());
        $this->assertSame('CANARY', $v1->fresh()->status);
        if (! $mismaVersion) {
            $this->assertSame('APPROVED', $v2->fresh()->status);
        }
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1);
        $this->assertEngineApplies($v1->uuid, null);
    }

    /**
     * EL ÍNDICE `usv_one_canary` ENTRE SESIONES. Con dos publicaciones reales no
     * se llega a él: la segunda ya choca antes en `seq` (prueba de arriba). Para
     * recorrer este camino hace falta un rival que meta OTRA versión en CANARY
     * sin pasar por la cadena: una sesión cruda que la pone en CANARY sin
     * confirmar y espera. La publicación real inserta su entrada sin choque,
     * espera en el índice al hacer su transición, recibe el 23505 cuando el
     * rival confirma, y lo resuelve fuera de su transacción abortada.
     * Lo que se prueba: el índice para a la segunda CANARY entre sesiones, la
     * respuesta es `canary_in_progress` y la transacción abortada no deja ni su
     * entrada, ni su transición, ni su evento. (El rival crudo deja una CANARY
     * sin entrada en la cadena: por eso aquí no se comprueba la invariante.)
     */
    public function test_el_indice_usv_one_canary_para_la_segunda_canaria_entre_sesiones_y_la_transaccion_abortada_no_deja_nada(): void
    {
        $v1 = $this->approvedVersion($this->ana, $this->root, 'Primera');
        $v2 = $this->seededVersion($this->ana, $this->root, $this->baseline());

        $ir = $this->spawnRacer('R', null, raw: [
            'statements' => [[
                "UPDATE ultron_studio_versions SET status = 'CANARY', status_changed_at = ?, updated_at = ? WHERE id = ? AND status = 'APPROVED'",
                [now()->utc()->format('Y-m-d H:i:s'), now()->utc()->format('Y-m-d H:i:s'), $v2->id],
            ]],
            'timeout' => 15.0,
        ]);
        $this->awaitRacerLine($ir, 'HOLDING');
        $a = $this->collectRacer($this->spawnRacer('A', $this->raceRequest('POST', "versions/{$v1->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v1))));
        $r = $this->collectRacer($ir);

        $this->assertNull($r['error'], json_encode($r['error']));
        $this->assertGreaterThanOrEqual(1, $r['waiters'], 'la publicación no esperó el índice usv_one_canary del rival');
        $this->assertRacersClean([$a]);
        $this->assertSame([409, 'canary_in_progress'], [$a['status'], $a['json']['code'] ?? null], json_encode($a['json']));

        $this->assertSame(0, StudioPublication::query()->count(), 'la entrada de la transacción abortada quedó en la cadena');
        $this->assertSame(['APPROVED', 'CANARY'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.published')->count());
        $this->assertNothingHalfDone();
        $this->assertEngineSeesNoChain();
    }

    // ── Promover contra revertir, y doble promoción ─────────────────────────

    /** @return array<string, array{0:string}> */
    public static function promoteVersusRollback(): array
    {
        return ['gana promover' => [D::ACTION_PROMOTE], 'gana revertir' => [D::ACTION_ROLLBACK]];
    }

    #[DataProvider('promoteVersusRollback')]
    public function test_promover_contra_revertir_la_misma_canaria_gana_quien_tiene_la_cabeza(string $gana): void
    {
        [$v, $huella] = $this->canaryReadyToPromote();
        $promover = ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])];
        $revertir = ['rollback', $this->raceRequest('POST', "versions/{$v->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir en plena promoción'])];

        [$a, $b] = $gana === D::ACTION_PROMOTE ? $this->holdUntilRivalWaits($promover, $revertir) : $this->holdUntilRivalWaits($revertir, $promover);

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'la rival nunca esperó: la carrera no ocurrió');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'publication_moved'], [$b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(2, StudioPublication::query()->count());
        if ($gana === D::ACTION_PROMOTE) {
            $this->assertSame('PUBLISHED', $v->fresh()->status);
            $this->assertSame([1, 0], [$this->events('version.promoted', $v), $this->events('version.rolled_back', $v)]);
            $this->assertEngineApplies($v->uuid, $v->uuid);
        } else {
            $this->assertSame('ROLLED_BACK', $v->fresh()->status);
            $this->assertSame([0, 1], [$this->events('version.promoted', $v), $this->events('version.rolled_back', $v)]);
            $this->assertEngineApplies(null, null);
        }
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(2);
    }

    public function test_revertir_la_canaria_que_otra_sesion_acaba_de_promover_es_invalid_transition_con_el_estado_de_ahora(): void
    {
        [$v, $huella] = $this->canaryReadyToPromote();

        // Revertir ya pasó sus puertas (CANARY) y lee la cabeza después de la promoción.
        [$a, $b] = $this->readsHeadAfter(
            ['rollback', $this->raceRequest('POST', "versions/{$v->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir tarde'])],
            ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'invalid_transition', 'PUBLISHED'], [$b['status'], $b['json']['code'] ?? null, $b['json']['currentStatus'] ?? null], $this->summary([$a, $b]));
        $this->assertSame('PUBLISHED', $v->fresh()->status);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(2);
        $this->assertEngineApplies($v->uuid, $v->uuid);
    }

    public function test_promover_y_revertir_a_la_vez_la_misma_canaria_dejan_un_estado_coherente(): void
    {
        [$v, $huella] = $this->canaryReadyToPromote();

        $r = $this->raceAll([
            ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
            ['rollback', $this->raceRequest('POST', "versions/{$v->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir a la vez'])],
        ], self::BARRIER);

        // Cualquier orden vale si las respuestas dicen la verdad: promover y
        // después revertir la publicada son dos 201 en ese orden; si gana
        // revertir, promover ya no puede (no hay CANARY).
        $this->assertRacersClean($r);
        $this->assertOutcomeMatchesChain($r, 1, ['publication_moved', 'invalid_transition']);
        $orden = StudioPublication::query()->where('seq', '>', 1)->orderBy('seq')->pluck('action')->all();
        $this->assertContains($orden, [[D::ACTION_PROMOTE], [D::ACTION_ROLLBACK], [D::ACTION_PROMOTE, D::ACTION_ROLLBACK]], json_encode($orden));
        $this->assertSame(end($orden) === D::ACTION_PROMOTE ? 'PUBLISHED' : 'ROLLED_BACK', $v->fresh()->status);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(1 + count($orden));
        $this->assertEngineAppliesHead();
    }

    public function test_doble_promocion_simultanea_promueve_una_vez(): void
    {
        [$v, $huella] = $this->canaryReadyToPromote();

        $r = $this->raceAll([
            ['paula', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
            ['pablo', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertCount(1, array_filter($r, static fn (array $x): bool => $x['status'] === 201), $this->summary($r));
        $this->assertOutcomeMatchesChain($r, 1, ['publication_moved', 'invalid_transition']);
        $this->assertSame('PUBLISHED', $v->fresh()->status);
        $this->assertSame(1, $this->events('version.promoted', $v));
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(2);
        $this->assertEngineApplies($v->uuid, $v->uuid);
    }

    public function test_doble_promocion_forzada_la_segunda_espera_el_seq_y_recibe_publication_moved(): void
    {
        [$v, $huella] = $this->canaryReadyToPromote();

        [$a, $b] = $this->holdUntilRivalWaits(
            ['paula', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
            ['pablo', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters']);
        $this->assertSame([201, 409, 'publication_moved'], [$a['status'], $b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(1, $this->events('version.promoted', $v));
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(2);
        $this->assertEngineApplies($v->uuid, $v->uuid);
    }

    // ── Publicar mientras otra sesión vuelve a Legacy ───────────────────────

    /** @return array<string, array{0:string}> */
    public static function publishVersusLegacy(): array
    {
        return ['gana publicar' => [D::ACTION_PUBLISH], 'gana Legacy' => [D::ACTION_LEGACY]];
    }

    #[DataProvider('publishVersusLegacy')]
    public function test_publicar_contra_volver_a_legacy_gana_quien_tiene_la_cabeza(string $gana): void
    {
        [$p, $v] = $this->publishedAndAnApprovedOne();
        $publicar = ['publish', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v))];
        $legacy = ['legacy', $this->raceRequest('POST', 'publications/legacy', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Legacy en plena publicación'])];

        [$a, $b] = $gana === D::ACTION_PUBLISH ? $this->holdUntilRivalWaits($publicar, $legacy) : $this->holdUntilRivalWaits($legacy, $publicar);

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'la rival nunca esperó: la carrera no ocurrió');
        $this->assertSame([201, 409, 'publication_moved'], [$a['status'], $b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));
        if ($gana === D::ACTION_PUBLISH) {
            $this->assertSame(['PUBLISHED', 'CANARY'], [$p->fresh()->status, $v->fresh()->status]);
            $this->assertEngineApplies($v->uuid, $p->uuid);
        } else {
            $this->assertSame(['RETIRED', 'APPROVED'], [$p->fresh()->status, $v->fresh()->status]);
            $this->assertEngineApplies(null, null);
        }
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(3);
    }

    public function test_publicar_encima_de_un_legacy_recien_confirmado_compone_con_la_cabeza_nueva(): void
    {
        [$p, $v] = $this->publishedAndAnApprovedOne();

        // Publicar ya pasó sus puertas con P en el resto; Legacy se confirma
        // antes de que lea la cabeza. La parte resto sale de la cabeza leída
        // DENTRO de la transacción: nada (no la P que ya está RETIRED).
        [$a, $b] = $this->readsHeadAfter(
            ['publish', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v))],
            ['legacy', $this->raceRequest('POST', 'publications/legacy', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Legacy antes de leer'])],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame([201, 201], [$a['status'], $b['status']], $this->summary([$a, $b]));
        $this->assertSame(['canary' => $v->uuid, 'rest' => null], $this->headVersions());
        $this->assertSame(['RETIRED', 'CANARY'], [$p->fresh()->status, $v->fresh()->status]);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(4);
        $this->assertEngineApplies($v->uuid, null);
    }

    public function test_volver_a_legacy_justo_despues_de_una_publicacion_retira_tambien_la_canaria_recien_confirmada(): void
    {
        [$p, $v] = $this->publishedAndAnApprovedOne();

        // Legacy comprobó la cabeza fuera ({P, P}) y lee la de dentro tras la
        // publicación: la CANARY y la PUBLISHED que mueve son las de AHORA.
        [$a, $b] = $this->readsHeadAfter(
            ['legacy', $this->raceRequest('POST', 'publications/legacy', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Legacy después de leer'])],
            ['publish', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v))],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame([201, 201], [$a['status'], $b['status']], $this->summary([$a, $b]));
        $this->assertSame(['canary' => null, 'rest' => null], $this->headVersions());
        $this->assertSame(['RETIRED', 'ROLLED_BACK'], [$p->fresh()->status, $v->fresh()->status]);
        $this->assertSame(1, $this->events('version.rolled_back', $v));
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(4);
        $this->assertEngineApplies(null, null);
    }

    public function test_publicar_y_volver_a_legacy_a_la_vez_dejan_un_estado_coherente(): void
    {
        [$p, $v] = $this->publishedAndAnApprovedOne();

        $r = $this->raceAll([
            ['publish', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $this->publishBody($this->pablo, $v))],
            ['legacy', $this->raceRequest('POST', 'publications/legacy', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Legacy a la vez'])],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertOutcomeMatchesChain($r, 2, ['publication_moved']);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(StudioPublication::query()->count());
        $this->assertEngineAppliesHead();
    }

    // ── Dos personas pulsan a la vez la misma salida ────────────────────────

    /** @return array<string, array{0:string,1:list<string>}> */
    public static function sameExitTwice(): array
    {
        return [
            'volver a Legacy' => [D::ACTION_LEGACY, ['publication_moved', 'already_legacy']],
            'revertir la canaria' => [D::ACTION_ROLLBACK, ['publication_moved', 'invalid_transition', 'not_active_publication']],
        ];
    }

    #[DataProvider('sameExitTwice')]
    public function test_dos_personas_que_pulsan_a_la_vez_la_misma_salida_la_ejecutan_una_vez(string $accion, array $codigos): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Salida');
        $this->publishAs($this->pablo, $v->uuid)->assertCreated();
        $peticion = static fn (string $motivo): array => ['clientRequestId' => (string) Str::uuid(), 'reason' => $motivo];
        $ruta = $accion === D::ACTION_LEGACY ? 'publications/legacy' : "versions/{$v->uuid}/rollback";

        $r = $this->raceAll([
            ['pablo', $this->raceRequest('POST', $ruta, $this->pablo, $peticion('Lo paro yo'))],
            ['paula', $this->raceRequest('POST', $ruta, $this->paula, $peticion('Lo paro yo también'))],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertCount(1, array_filter($r, static fn (array $x): bool => $x['status'] === 201), $this->summary($r));
        $this->assertOutcomeMatchesChain($r, 1, $codigos);
        $this->assertSame('ROLLED_BACK', $v->fresh()->status);
        $this->assertSame(1, $this->events('version.rolled_back', $v));
        $this->assertSame(['canary' => null, 'rest' => null], $this->headVersions());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(2);
        $this->assertEngineApplies(null, null);
    }

    // ── Revertir la PUBLISHED mientras otra sesión promueve la CANARY ───────

    /** @return array<string, array{0:string}> */
    public static function rollbackPublishedVersusPromote(): array
    {
        return ['gana revertir' => [D::ACTION_ROLLBACK], 'gana promover' => [D::ACTION_PROMOTE]];
    }

    #[DataProvider('rollbackPublishedVersusPromote')]
    public function test_revertir_la_publicada_contra_promover_la_canaria_gana_quien_tiene_la_cabeza(string $gana): void
    {
        [$p0, $p1, $v, $huella] = $this->twoPublishedAndACanary();
        $revertir = ['rollback', $this->raceRequest('POST', "versions/{$p1->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir la publicada'])];
        $promover = ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])];

        [$a, $b] = $gana === D::ACTION_ROLLBACK ? $this->holdUntilRivalWaits($revertir, $promover) : $this->holdUntilRivalWaits($promover, $revertir);

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'la rival nunca esperó: la carrera no ocurrió');
        $this->assertSame([201, 409, 'publication_moved'], [$a['status'], $b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));
        if ($gana === D::ACTION_ROLLBACK) {
            // Q-2: la canaria conserva su parte; vuelve la P0 sustituida.
            $this->assertSame(['PUBLISHED', 'ROLLED_BACK', 'CANARY'], [$p0->fresh()->status, $p1->fresh()->status, $v->fresh()->status]);
            $this->assertSame([1, 1], [$this->events('version.rolled_back', $p1), $this->events('version.restored', $p0)]);
            $this->assertEngineApplies($v->uuid, $p0->uuid);
        } else {
            $this->assertSame(['RETIRED', 'RETIRED', 'PUBLISHED'], [$p0->fresh()->status, $p1->fresh()->status, $v->fresh()->status]);
            $this->assertSame([0, 0], [$this->events('version.rolled_back', $p1), $this->events('version.restored', $p0)]);
            $this->assertEngineApplies($v->uuid, $v->uuid);
        }
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(6);
    }

    public function test_revertir_la_publicada_que_otra_sesion_acaba_de_sustituir_es_invalid_transition(): void
    {
        [$p0, $p1, $v, $huella] = $this->twoPublishedAndACanary();

        // Revertir ya validó de dónde vuelve la parte resto (P0) y lee la cabeza
        // después de que la promoción de V sustituyera a P1.
        [$a, $b] = $this->readsHeadAfter(
            ['rollback', $this->raceRequest('POST', "versions/{$p1->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir tarde la publicada'])],
            ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'invalid_transition', 'RETIRED'], [$b['status'], $b['json']['code'] ?? null, $b['json']['currentStatus'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(['RETIRED', 'RETIRED', 'PUBLISHED'], [$p0->fresh()->status, $p1->fresh()->status, $v->fresh()->status]);
        $this->assertSame(0, $this->events('version.restored', $p0));
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(6);
        $this->assertEngineApplies($v->uuid, $v->uuid);
    }

    public function test_promover_justo_despues_de_revertir_la_publicada_sustituye_a_la_que_acaba_de_volver(): void
    {
        [$p0, $p1, $v, $huella] = $this->twoPublishedAndACanary();

        // Promover ya pasó sus puertas y lee la cabeza después de la reversión:
        // compone sobre {V, P0} y sustituye a P0, la que está PUBLISHED AHORA.
        [$a, $b] = $this->readsHeadAfter(
            ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
            ['rollback', $this->raceRequest('POST', "versions/{$p1->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir antes de la promoción'])],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame([201, 201], [$a['status'], $b['status']], $this->summary([$a, $b]));
        $this->assertSame(['RETIRED', 'ROLLED_BACK', 'PUBLISHED'], [$p0->fresh()->status, $p1->fresh()->status, $v->fresh()->status]);
        $this->assertSame(1, $this->events('version.restored', $p0));
        $retirada = StudioAuditEvent::query()->where('event', 'version.retired')->where('subject_uuid', $p0->uuid)->orderByDesc('id')->first();
        $this->assertSame(['entry' => $this->chainHead()->uuid, 'by' => 'promote'], $retirada?->diff);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(7);
        $this->assertEngineApplies($v->uuid, $v->uuid);
    }

    public function test_revertir_la_publicada_y_promover_la_canaria_a_la_vez_dejan_un_estado_coherente(): void
    {
        [, $p1, $v, $huella] = $this->twoPublishedAndACanary();

        $r = $this->raceAll([
            ['rollback', $this->raceRequest('POST', "versions/{$p1->uuid}/rollback", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir a la vez'])],
            ['promote', $this->raceRequest('POST', "versions/{$v->uuid}/promote", $this->paula, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella])],
        ], self::BARRIER);

        // Si la promoción se confirma antes de que revertir lea P1, P1 ya está
        // RETIRED: `not_active_publication` es la respuesta verdadera.
        $this->assertRacersClean($r);
        $this->assertOutcomeMatchesChain($r, 5, ['publication_moved', 'invalid_transition', 'not_active_publication']);
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(StudioPublication::query()->count());
        $this->assertEngineAppliesHead();
    }

    // ── recordDenied en REPEATABLE READ (§A10.6) ─────────────────────────────

    public function test_veinte_intentos_simultaneos_de_la_autora_en_repeatable_read_dejan_un_solo_four_eyes_denied(): void
    {
        $autora = $this->personWith(['ultron.view', 'ultron.edit_draft', 'ultron.publish'], 'Ada Autora');
        $v = $this->approvedVersion($autora, $this->root, 'De la autora');
        $cuerpo = $this->publishBody($this->pablo, $v);

        $peticiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $peticiones[] = ["a{$i}", $this->raceRequest('POST', "versions/{$v->uuid}/publish", $autora, ['clientRequestId' => (string) Str::uuid()] + $cuerpo)];
        }
        $r = $this->raceAll($peticiones, self::BARRIER, 'repeatable read');

        $this->assertRacersClean($r);
        foreach ($r as $x) {
            $this->assertSame('repeatable read', $x['isolation'], "{$x['label']}: la sesión no iba en REPEATABLE READ");
            $this->assertSame([403, 'four_eyes_required'], [$x['status'], $x['json']['code'] ?? null], "{$x['label']}: ".$this->summary($r));
        }
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->where('subject_uuid', $v->uuid)->where('actor_admin_id', $autora->id)->count(), 'la deduplicación dejó más de una fila');
        $this->assertSame(0, StudioPublication::query()->count());
        $this->assertSame('APPROVED', $v->fresh()->status);
        $this->assertEngineSeesNoChain();
    }

    public function test_record_denied_en_repeatable_read_espera_el_cerrojo_rival_y_ve_su_fila_al_soltarlo(): void
    {
        $autora = $this->personWith(['ultron.view', 'ultron.edit_draft', 'ultron.publish'], 'Ada Autora');
        $v = $this->approvedVersion($autora, $this->root, 'De la autora');
        $cuerpo = ['clientRequestId' => (string) Str::uuid()] + $this->publishBody($this->pablo, $v);

        // El rival: el MISMO cerrojo de deduplicación (evento, sujeto, persona) y
        // la misma fila que escribiría recordDenied, sin confirmar, hasta que la
        // sesión de la autora (REPEATABLE READ por defecto) espere el cerrojo.
        $ahora = now()->utc()->format('Y-m-d H:i:s');
        $ir = $this->spawnRacer('rival', null, raw: [
            'statements' => [
                ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED', []],
                ['SELECT pg_advisory_xact_lock(?)', [crc32("ultron_studio_audit:version.four_eyes_denied:{$v->uuid}:{$autora->id}")]],
                ["INSERT INTO ultron_studio_audit_events (event, subject_type, subject_uuid, actor_type, actor_admin_id, actor_name, actor_role, from_status, to_status, content_hash, summary, diff, metadata, ip_address, user_agent, created_at)
                    VALUES ('version.four_eyes_denied', 'version', ?, 'admin', ?, ?, ?, 'APPROVED', NULL, ?, ?, ?, NULL, NULL, NULL, ?)", [
                    $v->uuid, $autora->id, $autora->name, $autora->role, $v->content_hash,
                    "Intentó publicar su propia versión «{$v->label}»", '{"action":"publish","rival":true}', $ahora,
                ]],
            ],
            'timeout' => 15.0,
        ]);
        $this->awaitRacerLine($ir, 'HOLDING');
        $m = $this->collectRacer($this->spawnRacer('autora', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $autora, $cuerpo), isolation: 'repeatable read'));
        $rival = $this->collectRacer($ir);

        $this->assertNull($rival['error'], json_encode($rival['error']));
        $this->assertGreaterThanOrEqual(1, $rival['waiters'], 'la sesión de la autora no esperó el cerrojo de deduplicación');
        $this->assertRacersClean([$m]);
        $this->assertSame('repeatable read', $m['isolation']);
        $this->assertSame([403, 'four_eyes_required'], [$m['status'], $m['json']['code'] ?? null]);
        $filas = StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->where('subject_uuid', $v->uuid)->get();
        $this->assertCount(1, $filas, 'la autora no vio la fila del rival al soltar el cerrojo (instantánea anterior a la espera): '.$filas->pluck('diff')->toJson());
        $this->assertTrue($filas->sole()->diff['rival'] ?? false, 'la fila que queda no es la del rival');
        $this->assertEngineSeesNoChain();
    }

    // ── El reintento que cruza la confirmación de la primera (§A5.1 paso 3) ─

    public function test_el_doble_clic_que_espera_la_insercion_de_la_primera_recibe_replayed(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Doble clic');
        $cuerpo = $this->publishBody($this->pablo, $v);

        // B, la misma petición, choca con la entrada de A sin confirmar (23505
        // de `client_request_id` o de `seq`) y la resuelve FUERA de su
        // transacción abortada: la entrada de la primera vez.
        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo)],
            ['B', $this->raceRequest('POST', "versions/{$v->uuid}/publish", $this->pablo, $cuerpo)],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo de A: la carrera no ocurrió');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([200, true, $a['json']['data']], [$b['status'], $b['json']['replayed'] ?? null, $b['json']['data'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(1, StudioPublication::query()->count());
        $this->assertNothingHalfDone();
        $this->assertChainVerifies(1);
    }

    /**
     * EL REINTENTO QUE LLEGA TARDE A SU PROPIA CARRERA. El contrato (§A5.1 paso
     * 3) dice que la misma `clientRequestId`, de la misma persona y con el
     * mismo cuerpo, devuelve 200 `replayed` con la entrada de la primera vez,
     * «aunque la versión ya esté en CANARY». El servicio lo mira dos veces: al
     * principio y tras un 23505. Entre medias hay dos ventanas en las que la
     * primera puede confirmarse:
     *  - `gates`: B ya pasó el reintento (todavía no había nada) y la primera
     *    se confirma antes de que B lea la versión → las puertas ven el estado
     *    nuevo;
     *  - `transaction`: B ya pasó las puertas y la primera se confirma antes de
     *    que B lea la cabeza dentro de su transacción → la cabeza ya no compone.
     * En las dos, el contrato pide la entrada de la primera vez.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function lateRetries(): array
    {
        $casos = [];
        foreach ([D::ACTION_PUBLISH, D::ACTION_PROMOTE, D::ACTION_ROLLBACK, D::ACTION_LEGACY] as $accion) {
            foreach (['gates', 'transaction'] as $ventana) {
                $casos["{$accion} · {$ventana}"] = [$accion, $ventana];
            }
        }

        return $casos;
    }

    #[DataProvider('lateRetries')]
    public function test_el_reintento_que_cruza_la_confirmacion_de_la_primera_devuelve_su_entrada(string $accion, string $ventana): void
    {
        [$quien, $ruta, $cuerpo] = $this->sameRequestTwice($accion);

        // B se para en la ventana; A, la MISMA petición, se confirma entera; B sigue.
        $pausa = match (true) {
            $ventana === 'transaction' => self::pauseBefore(self::FIRST_CHAIN_STATEMENT, true, 'released', self::RELEASE),
            // Legacy no lee la versión: lo primero tras el reintento es la cabeza, fuera de la transacción.
            $accion === D::ACTION_LEGACY => self::pauseBefore(self::HEAD_READ, false, 'released', self::RELEASE),
            default => self::pauseBefore(['from "ultron_studio_versions" where "uuid" = ?'], false, 'released', self::RELEASE),
        };
        $this->holdLock(self::RELEASE);
        try {
            $ib = $this->spawnRacer('B', $this->raceRequest('POST', $ruta, $quien, $cuerpo), null, $pausa);
            $this->awaitRacerLine($ib, 'PAUSED');
            $a = $this->collectRacer($this->spawnRacer('A', $this->raceRequest('POST', $ruta, $quien, $cuerpo)));
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $b = $this->collectRacer($ib);

        $this->assertRacersClean([$a, $b]);
        $this->assertTrue($b['paused'], 'B no llegó a la ventana');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        // Primero la integridad (vale aunque falle lo de abajo): una sola entrada nueva, nada a medias.
        $this->assertSame(1, StudioPublication::query()->where('client_request_id', $cuerpo['clientRequestId'])->count());
        $this->assertNothingHalfDone();
        $this->assertChainMatchesStates();
        $this->assertChainVerifies(StudioPublication::query()->count());
        $this->assertEngineAppliesHead();

        // …y después el contrato del reintento (§A5.1 paso 3).
        $this->assertSame(
            [200, true, $a['json']['data']['uuid']],
            [$b['status'], $b['json']['replayed'] ?? null, $b['json']['data']['uuid'] ?? null],
            'El reintento de la MISMA petición (misma persona, mismo clientRequestId, mismo cuerpo) que cruzó la confirmación de la primera no devolvió su entrada: '.json_encode($b['json']),
        );
    }

    /**
     * El estado de partida de cada acción y la petición que se envía dos veces
     * (misma persona, mismo `clientRequestId`, mismo cuerpo).
     *
     * @return array{0:Admin,1:string,2:array<string,mixed>}
     */
    private function sameRequestTwice(string $accion): array
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Reintento');
        if ($accion === D::ACTION_PUBLISH) {
            return [$this->pablo, "versions/{$v->uuid}/publish", $this->publishBody($this->pablo, $v)];
        }

        $this->publishAs($this->pablo, $v->uuid)->assertCreated();
        if ($accion === D::ACTION_ROLLBACK) {
            return [$this->pablo, "versions/{$v->uuid}/rollback", ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir dos veces a la vez']];
        }
        if ($accion === D::ACTION_LEGACY) {
            return [$this->pablo, 'publications/legacy', ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Legacy dos veces a la vez']];
        }

        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::at('2026-11-04T16:00:00Z');
        $this->freshSessions();

        return [$this->paula, "versions/{$v->uuid}/promote", ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $this->evidenceOf($this->paula, $v->uuid)['evidenceHash']]];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * El cuerpo con el que publica el CRM: la huella de la vista previa y su
     * conjunto «sin efecto».
     *
     * @return array<string, mixed>
     */
    private function publishBody(Admin $as, StudioVersion $v, ?string $clientRequestId = null): array
    {
        $vista = $this->previewOf($as, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));

        return ['clientRequestId' => $clientRequestId ?? (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']];
    }

    /**
     * A se para justo antes de su primer evento del libro (ya insertó su entrada
     * e hizo sus transiciones: tiene los cerrojos) hasta que B espere uno de
     * ellos; B arranca solo cuando A ya está parada.
     *
     * @param  array{0:string,1:array<string,mixed>}  $a
     * @param  array{0:string,1:array<string,mixed>}  $b
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function holdUntilRivalWaits(array $a, array $b): array
    {
        $ia = $this->spawnRacer($a[0], $a[1], null, self::pauseBefore(['insert into "ultron_studio_audit_events"'], true, 'blocked'));
        $this->awaitRacerLine($ia, 'PAUSED');
        $ib = $this->spawnRacer($b[0], $b[1]);

        return [$this->collectRacer($ia), $this->collectRacer($ib)];
    }

    /**
     * B pasa sus puertas y se para justo antes de leer la cabeza DENTRO de su
     * transacción; A se ejecuta entera y se confirma; entonces B sigue.
     * Devuelve [B, A].
     *
     * @param  array{0:string,1:array<string,mixed>}  $b
     * @param  array{0:string,1:array<string,mixed>}  $a
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function readsHeadAfter(array $b, array $a): array
    {
        $this->holdLock(self::RELEASE);
        try {
            $ib = $this->spawnRacer($b[0], $b[1], null, self::pauseBefore(self::FIRST_CHAIN_STATEMENT, true, 'released', self::RELEASE));
            $this->awaitRacerLine($ib, 'PAUSED');
            $ra = $this->collectRacer($this->spawnRacer($a[0], $a[1]));
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $rb = $this->collectRacer($ib);
        $this->assertTrue($rb['paused'], "{$b[0]} no llegó a leer la cabeza");

        return [$ra, $rb];
    }

    private static function advanceHours(int $horas): void
    {
        self::at(now()->addHours($horas)->utc()->toIso8601String());
    }

    /**
     * Una versión en CANARY con evidencia suficiente para promover: publicada
     * por Pablo, tres turnos sellados una hora después y el reloj 25 h más
     * tarde (sesiones nuevas). Devuelve la versión y la `evidenceHash` que vería
     * quien promueve.
     *
     * @return array{0:StudioVersion,1:string}
     */
    private function canaryReadyToPromote(?StudioVersion $v = null): array
    {
        $v ??= $this->approvedVersion($this->ana, $this->root, 'En canario');
        $this->publishAs($this->pablo, $v->uuid)->assertCreated();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::advanceHours(25);
        $this->freshSessions();
        $evidencia = $this->evidenceOf($this->paula, $v->uuid);
        $this->assertTrue($evidencia['passes'], json_encode($evidencia['failing']));

        return [$v->fresh(['review']), $evidencia['evidenceHash']];
    }

    /**
     * P publicada a todas (entradas 1 y 2) y V aprobada, lista para publicar.
     *
     * @return array{0:StudioVersion,1:StudioVersion}
     */
    private function publishedAndAnApprovedOne(): array
    {
        [$p, $huella] = $this->canaryReadyToPromote($this->approvedVersion($this->ana, $this->root, 'Publicada'));
        $this->promoteAs($this->paula, $p->uuid, null, $huella)->assertCreated();
        $v = $this->approvedVersion($this->ana, $this->root, 'Siguiente');
        $this->assertSame(['canary' => $p->uuid, 'rest' => $p->uuid], $this->headVersions());

        return [$p, $v];
    }

    /**
     * P0 publicada y después sustituida por P1 (RETIRED), P1 publicada, y V en
     * canario con evidencia suficiente: la cabeza (entrada 5) es {V, P1}.
     *
     * @return array{0:StudioVersion,1:StudioVersion,2:StudioVersion,3:string}
     */
    private function twoPublishedAndACanary(): array
    {
        [$p0, $h0] = $this->canaryReadyToPromote($this->approvedVersion($this->ana, $this->root, 'P0'));
        $this->promoteAs($this->paula, $p0->uuid, null, $h0)->assertCreated();
        [$p1, $h1] = $this->canaryReadyToPromote($this->approvedVersion($this->ana, $this->root, 'P1'));
        $this->promoteAs($this->paula, $p1->uuid, null, $h1)->assertCreated();
        [$v, $huella] = $this->canaryReadyToPromote($this->approvedVersion($this->ana, $this->root, 'V'));
        $this->assertSame(['RETIRED', 'PUBLISHED', 'CANARY'], [$p0->fresh()->status, $p1->fresh()->status, $v->fresh()->status]);
        $this->assertSame(['canary' => $v->uuid, 'rest' => $p1->uuid], $this->headVersions());

        return [$p0, $p1, $v, $huella];
    }

    private function events(string $event, StudioVersion $v): int
    {
        return StudioAuditEvent::query()->where('event', $event)->where('subject_uuid', $v->uuid)->count();
    }

    /**
     * LAS RESPUESTAS DICEN LA VERDAD sobre la cadena: cada 201 es una entrada
     * nueva y cada entrada nueva es un 201 (en el orden de `seq`); cada
     * `replayed` nombra una entrada que existe; el resto son 409 con un código
     * de los que el contrato da para una carrera.
     *
     * @param  list<array<string, mixed>>  $resultados
     * @param  list<string>  $codigos
     */
    private function assertOutcomeMatchesChain(array $resultados, int $antes, array $codigos): void
    {
        $creadas = [];
        foreach ($resultados as $x) {
            if ($x['status'] === 201) {
                $creadas[] = $x['json']['data']['uuid'];
            } elseif ($x['status'] === 200) {
                $this->assertTrue($x['json']['replayed'] ?? false, "{$x['label']}: ".$this->summary($resultados));
                $this->assertTrue(StudioPublication::query()->where('uuid', $x['json']['data']['uuid'] ?? '')->exists());
            } else {
                $this->assertSame(409, $x['status'], "{$x['label']}: ".$this->summary($resultados));
                $this->assertContains($x['json']['code'] ?? null, $codigos, "{$x['label']}: ".$this->summary($resultados));
            }
        }
        $nuevas = StudioPublication::query()->where('seq', '>', $antes)->orderBy('seq')->pluck('uuid')->all();
        sort($creadas);
        $ordenadas = $nuevas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $creadas, 'las 201 no son exactamente las entradas nuevas: '.$this->summary($resultados));
    }

    /** Sin cadena: el motor no ve cabeza y sella Legacy sin entrada. */
    private function assertEngineSeesNoChain(): void
    {
        $motor = app(UltronPublishedConfig::class);
        $this->assertNull($motor->describe()['head']);
        $sello = $motor->forConversation(self::CANARY_ID);
        $this->assertSame(['legacy', null, null], [$sello['state'], $sello['entry'], $sello['seq']]);
    }

    /** {@see assertEngineApplies()} con lo que declara la cabeza. */
    private function assertEngineAppliesHead(): void
    {
        ['canary' => $c, 'rest' => $r] = $this->headVersions();
        $this->assertEngineApplies($c, $r);
    }

    /**
     * Ninguna sesión murió (sin excepción en el hijo, sin 5xx) y todas
     * respondieron JSON.
     *
     * @param  list<array<string, mixed>>  $resultados
     */
    private function assertRacersClean(array $resultados): void
    {
        foreach ($resultados as $x) {
            $this->assertNull($x['error'], "{$x['label']} lanzó: ".json_encode($x['error']));
            $this->assertIsInt($x['status'], "{$x['label']} sin estado");
            $this->assertLessThan(500, $x['status'], "{$x['label']}: ".json_encode($x['json']));
            $this->assertIsArray($x['json'], "{$x['label']} no respondió JSON");
        }
        // Cada una, su sesión de la base (y ninguna es la de la prueba).
        $pids = array_column($resultados, 'pid');
        $this->assertSame(count($pids), count(array_unique($pids)), 'dos sesiones compartieron conexión');
        $this->assertNotContains((int) DB::scalar('select pg_backend_pid()'), $pids);
    }

    /** @param  list<array<string, mixed>>  $resultados */
    private function summary(array $resultados): string
    {
        return json_encode(array_map(static fn (array $x): array => [
            $x['label'], $x['status'], $x['json']['code'] ?? (($x['json']['replayed'] ?? false) ? 'replayed' : null), $x['json']['currentStatus'] ?? null,
        ], $resultados));
    }

    /**
     * NADA A MEDIAS: cada entrada de la cadena tiene su evento del libro (el de
     * su acción, con su `entry`), y ningún evento nombra una entrada que no
     * existe (sería el rastro de una transacción abortada).
     */
    private function assertNothingHalfDone(): void
    {
        $entradas = StudioPublication::query()->orderBy('seq')->get(['uuid', 'action', 'version_id']);
        $porEntrada = [];
        foreach (StudioAuditEvent::query()->whereNotNull('diff')->get(['event', 'subject_uuid', 'diff']) as $e) {
            if (is_array($e->diff) && isset($e->diff['entry'])) {
                $porEntrada[(string) $e->diff['entry']][] = $e->event;
            }
        }
        $uuids = $entradas->pluck('uuid')->all();
        foreach (array_keys($porEntrada) as $entry) {
            $this->assertContains($entry, $uuids, "un evento del libro nombra la entrada {$entry}, que no está en la cadena: quedó de una transacción abortada");
        }
        $principal = [D::ACTION_PUBLISH => 'version.published', D::ACTION_PROMOTE => 'version.promoted', D::ACTION_ROLLBACK => 'version.rolled_back', D::ACTION_LEGACY => 'system.publication_legacy'];
        foreach ($entradas as $p) {
            $this->assertSame(1, count(array_keys($porEntrada[$p->uuid] ?? [], $principal[$p->action], true)), "la entrada {$p->uuid} ({$p->action}) no tiene exactamente un {$principal[$p->action]}");
        }
    }

    /**
     * La cadena entera: `seq` 1..N sin huecos, cada una enlazada a la anterior,
     * identificadores únicos, y cada entrada verifica como la verifica el motor
     * (huella, firma sobre los bytes, forma atada a su uuid y su seq).
     */
    private function assertChainVerifies(int $entradas): void
    {
        $filas = StudioPublication::query()->orderBy('seq')->get();
        $this->assertCount($entradas, $filas);
        $this->assertSame(range(1, $entradas), $filas->pluck('seq')->all(), 'seq con huecos o duplicados');
        $this->assertSame([null, ...$filas->pluck('id')->take($entradas - 1)->all()], $filas->pluck('previous_publication_id')->all(), 'una entrada no enlaza con la anterior');
        $this->assertSame($entradas, $filas->pluck('client_request_id')->unique()->count());
        foreach ($filas as $f) {
            $this->assertSame(UltronPublishedConfig::CONTRACT, $f->engine_contract);
            $this->assertSame(hash('sha256', $f->effective_json), $f->effective_hash, "huella de la entrada {$f->seq}");
            $this->assertSame(UltronPublishedConfig::signatureFor($f->effective_json), $f->signature, "firma de la entrada {$f->seq}");
            D::parts($f->effective_json, $f->uuid, $f->seq);
        }
    }

    /**
     * La cabeza verifica según el propio motor (`describe()`) y el lector REAL
     * la aplica: la conversación canaria con la parte canaria (o la del resto si
     * no hay), cualquier otra con la del resto (la llave está en `all`).
     */
    private function assertEngineApplies(?string $canary, ?string $rest): void
    {
        $motor = app(UltronPublishedConfig::class);
        $cabeza = $motor->describe()['head'];
        $this->assertSame([true, true, true], [$cabeza['hashOk'], $cabeza['signatureOk'], $cabeza['parseOk']], 'la cabeza no verifica');
        $this->assertSame($this->chainHead()->uuid, $cabeza['entry']);

        $enCanario = $canary ?? $rest;
        $sello = $motor->forConversation(self::CANARY_ID);
        $this->assertSame($enCanario === null ? ['legacy', null] : ['applied', $enCanario], [$sello['state'], $sello['version']], 'la canaria');
        $otra = $motor->forConversation(self::OTHER_CONVERSATION);
        $this->assertSame($rest === null ? ['legacy', null] : ['applied', $rest], [$otra['state'], $otra['version']], 'el resto');
        $this->assertSame([$this->chainHead()->uuid, (int) $this->chainHead()->seq], [$sello['entry'], $sello['seq']]);
    }
}
