<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Support\Privacy\PhoneFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IntegrationRaceSessions;
use Tests\TestCase;

/**
 * LAS CARRERAS DE LAS INTEGRACIONES CON SESIONES REALES DE POSTGRESQL
 * (contrato H6 §I11, fila «PostgreSQL»; §I3.4 pasos 5, 9–11; §I4.4; §I4.8;
 * §I5.3 ruta 55; §I5.4 ruta 56; §I7 idempotencia; Enmienda 1 D-1).
 *
 * Cada lado de cada carrera es OTRA SESIÓN de la base: un proceso hijo que
 * manda la petición por el kernel HTTP con la sesión real de una persona
 * ({@see IntegrationRaceSessions}). Dos maneras de juntarlas:
 *  - NATURAL: N sesiones esperan en una barrera de la base y salen a la vez;
 *    lo que se afirma vale en CUALQUIER orden;
 *  - FORZADA: una sesión se detiene en un punto exacto de su transacción hasta
 *    que la rival espera uno de sus cerrojos (`pg_blocking_pids` + escalón de
 *    40 ms) o hasta que la prueba la suelta; así el camino difícil (el 23505
 *    en una transacción de verdad, el tope contado tras el cerrojo, el UPDATE
 *    condicionado que reevalúa) se recorre con certeza.
 *
 * Después de cada carrera, primero la INTEGRIDAD (una sola fila o efecto, nada
 * a medias, el libro con un solo evento por efecto y ninguno de la perdedora)
 * y después el contrato de la respuesta. En las respuestas de las perdedoras
 * no aparece el teléfono, sus dígitos ni su huella.
 *
 * Solo PostgreSQL (SQLite escribe en serie y no tiene cerrojos consultivos).
 * Los hijos ven solo lo confirmado: aquí no hay transacción envolvente y cada
 * prueba empieza con el esquema recién creado.
 */
class UltronStudioIntegrationsRaceTest extends TestCase
{
    use IntegrationFixtures;
    use IntegrationRaceSessions;
    use RefreshDatabase;
    use StudioFixtures;

    /** Sin transacción envolvente: los hijos tienen que ver lo que siembra la prueba. */
    protected array $connectionsToTransact = [];

    /** Cerrojos consultivos de la coordinación (otros que los del H5). */
    private const BARRIER = 716001;

    private const RELEASE = 716003;

    /** Las sesiones simultáneas de las carreras naturales. */
    private const N = 10;

    private const PHONE = '+573001110001';

    private const CAMPAIGN = '120000000000000387';

    private const TWILIO_SID = 'AC0123456789abcdef0123456789abcdef';

    /** La respuesta del simulador de Twilio dentro de cada hijo (la sonda nunca sale). */
    private const TWILIO_FAKE = ['status' => 200, 'body' => ['sid' => self::TWILIO_SID, 'status' => 'active', 'type' => 'Full']];

    private Admin $ana;

    private Admin $bea;

    private Admin $root;

    private Admin $rita;

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        // Se omite ANTES de arrancar la aplicación: con `$connectionsToTransact = []`,
        // una clase que migrase la base en memoria de SQLite no guardaría su PDO y
        // las clases siguientes del mismo proceso arrancarían sin esquema.
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Sesiones rivales de verdad y cerrojos consultivos: solo PostgreSQL (SQLite escribe en serie).');
        }
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->assertTrue($this->isPostgres());
        $this->confirmoFuera = true;
        $this->withoutMiddleware(ThrottleRequests::class);
        // La prueba misma tampoco sale a la red (la sonda de siembra va contra este falso).
        Http::preventStrayRequests();
        Http::fake(static fn () => Http::response(self::TWILIO_FAKE['body'], self::TWILIO_FAKE['status']));
        self::at('2026-11-03T15:00:00Z');
        $this->integrationsFlags(writes: true);
        config(['ultron_studio.integrations.canary.max_active' => 3]);
        $this->ana = $this->personWith(self::ADMIN_KEYS, 'Ana Registra');
        $this->bea = $this->personWith(self::ADMIN_KEYS, 'Bea Activa');
        $this->root = $this->personWith(self::SUPER_KEYS, 'Root Autoriza');
        $this->rita = $this->personWith(self::SUPER_KEYS, 'Rita Revisa');
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

    // ── Registrar el mismo teléfono ─────────────────────────────────────────

    public function test_diez_registros_simultaneos_del_mismo_telefono_dejan_una_fila_viva_y_un_evento(): void
    {
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $quien = $i % 2 === 0 ? $this->bea : $this->ana;
            $sesiones[] = ["r{$i}", $this->raceRequest('POST', 'integrations/canaries', $quien, $this->registerBody(self::PHONE, "Móvil de pruebas {$i}"))];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, 'una sola 201: '.$this->summary($r));
        // Integridad: una sola fila viva con esa huella, y un solo evento.
        $huella = PhoneFingerprint::of(self::PHONE);
        $this->assertSame(1, StudioCanaryNumber::query()->where('channel', 'whatsapp')->where('phone_hash', $huella)->count());
        $this->assertSame([$ganadoras[0]['json']['data']['number']['uuid']], StudioCanaryNumber::query()->pluck('uuid')->all());
        $this->assertSame(1, $this->events('canary_number.registered'));
        $this->assertSame(1, StudioAuditEvent::query()->count(), 'ningún evento de las perdedoras');
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([409, 'canary_number_exists'], [$x['status'], $x['json']['code'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
        $this->assertNoPhoneIn($r, self::PHONE);
    }

    public function test_el_registro_que_espera_el_cerrojo_de_vivos_de_la_ganadora_recibe_canary_number_exists(): void
    {
        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', 'integrations/canaries', $this->ana, $this->registerBody(self::PHONE, 'Móvil A'))],
            ['B', $this->raceRequest('POST', 'integrations/canaries', $this->bea, $this->registerBody(self::PHONE, 'Móvil B'))],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo de A: la carrera no ocurrió');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'canary_number_exists'], [$b['status'], $b['json']['code'] ?? null], $this->summary([$a, $b]));
        $this->assertSame(1, StudioCanaryNumber::query()->count());
        $this->assertSame(1, $this->events('canary_number.registered'));
        $this->assertNoPhoneIn([$a, $b], self::PHONE);
    }

    /**
     * EL ÍNDICE `uscn_one_live` ENTRE SESIONES. Con dos registros reales no se
     * llega a esperarlo: el cerrojo de vivos los pone en fila y la segunda ve la
     * fila CONFIRMADA. Para recorrer la espera en el índice hace falta un rival
     * que inserte el mismo teléfono sin el cerrojo y sin confirmar: el registro
     * real cuenta (no lo ve), inserta, ESPERA en el índice, recibe el 23505
     * cuando el rival confirma y lo resuelve fuera de su transacción abortada
     * (25P02): ni su fila ni su evento.
     */
    public function test_un_rival_sin_cerrojo_hace_esperar_al_registro_en_uscn_one_live_y_la_transaccion_abortada_no_deja_nada(): void
    {
        $ahora = now()->utc()->format('Y-m-d H:i:s');
        $ir = $this->spawnRacer('rival', null, ['raw' => [
            'statements' => [[
                'INSERT INTO ultron_studio_canary_numbers (uuid, client_request_id, channel, alias, phone_hash, phone_last4, country_code, status, consent_attested, registered_by_admin_id, registered_by_name, registered_by_role, status_changed_at, created_at, updated_at)
                    VALUES (?, ?, \'whatsapp\', \'Rival crudo\', ?, \'0001\', \'57\', \'pending\', true, ?, ?, ?, ?, ?, ?)',
                [(string) Str::uuid(), (string) Str::uuid(), PhoneFingerprint::of(self::PHONE), $this->bea->id, $this->bea->name, $this->bea->role, $ahora, $ahora, $ahora],
            ]],
            'timeout' => 20.0,
        ]]);
        $this->awaitRacerLine($ir, 'HOLDING');
        $a = $this->collectRacer($this->spawnRacer('A', $this->raceRequest('POST', 'integrations/canaries', $this->ana, $this->registerBody(self::PHONE, 'Móvil A'))));
        $rival = $this->collectRacer($ir);

        $this->assertNull($rival['error'], json_encode($rival['error']));
        $this->assertGreaterThanOrEqual(1, $rival['waiters'], 'el registro no esperó el índice uscn_one_live del rival');
        $this->assertRacersClean([$a]);
        $this->assertSame([409, 'canary_number_exists'], [$a['status'], $a['json']['code'] ?? null], (string) $a['body']);
        $this->assertSame(['Rival crudo'], StudioCanaryNumber::query()->pluck('alias')->all(), 'la fila de la transacción abortada quedó');
        $this->assertSame(0, $this->events('canary_number.registered'), 'el evento de la transacción abortada quedó');
        $this->assertNoPhoneIn([$a], self::PHONE);
    }

    // ── El doble clic con la misma clientRequestId ──────────────────────────

    public function test_diez_registros_con_la_misma_client_request_id_dan_una_201_y_nueve_replayed_con_el_mismo_numero(): void
    {
        $cuerpo = $this->registerBody(self::PHONE, 'Móvil de pruebas 1');
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $sesiones[] = ["r{$i}", $this->raceRequest('POST', 'integrations/canaries', $this->ana, $cuerpo)];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $this->assertSame(1, StudioCanaryNumber::query()->count());
        $this->assertSame(1, $this->events('canary_number.registered'));
        $uuid = $ganadoras[0]['json']['data']['number']['uuid'];
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, $uuid], [$x['status'], $x['json']['data']['replayed'] ?? null, $x['json']['data']['number']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
        $this->assertNoPhoneIn($r, self::PHONE);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function doubleClicks(): array
    {
        return ['registrar' => ['register'], 'asociar' => ['link'], 'sustituir' => ['replace'], 'reconocer' => ['acknowledge']];
    }

    /**
     * EL SEGUNDO CLIC QUE ESPERA A LA PRIMERA. A se para justo antes de su
     * evento (dentro de su transacción) hasta que B, la MISMA petición, espera
     * uno de sus cerrojos (el consultivo del tope o el índice de
     * `client_request_id`); A confirma; B choca y lo resuelve fuera de su
     * transacción abortada con lo de la primera vez.
     */
    #[DataProvider('doubleClicks')]
    public function test_el_doble_clic_que_espera_a_la_primera_recibe_replayed_con_un_solo_efecto(string $accion): void
    {
        [$ruta, $cuerpo, $quien, $clave] = $this->sameRequestTwice($accion);

        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', $ruta, $quien, $cuerpo)],
            ['B', $this->raceRequest('POST', $ruta, $quien, $cuerpo)],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó a A: la carrera no ocurrió');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        // Integridad primero: un solo efecto.
        match ($accion) {
            'register' => $this->assertSame([1, 1], [StudioCanaryNumber::query()->count(), $this->events('canary_number.registered')]),
            'link' => $this->assertSame([1, 1], [StudioCampaignLink::query()->count(), $this->events('campaign_link.created')]),
            'replace' => $this->assertSame([2, 2, 1], [StudioCampaignLink::query()->count(), $this->events('campaign_link.created'), $this->events('campaign_link.deactivated')]),
            'acknowledge' => $this->assertSame([1, 1], [StudioIntegrationSnapshot::query()->count(), $this->events('integration.config_acknowledged')]),
        };
        $this->assertSame(
            [200, true, data_get($a['json'], $clave)],
            [$b['status'], $b['json']['data']['replayed'] ?? null, data_get($b['json'], $clave)],
            'el reintento de la MISMA petición no devolvió lo de la primera vez: '.$b['body'],
        );
    }

    // ── El tope de vivos al registrar (§I4.8: «el tope de vivos se cuenta igual
    //    en el registro, con su propia clave de cerrojo») ─────────────────────

    public function test_tres_registros_simultaneos_contra_el_ultimo_hueco_de_vivos_registran_uno(): void
    {
        $this->nineLiveNumbers();
        $r = $this->raceAll([
            ['x', $this->raceRequest('POST', 'integrations/canaries', $this->ana, $this->registerBody('+573001110091', 'Móvil X'))],
            ['y', $this->raceRequest('POST', 'integrations/canaries', $this->bea, $this->registerBody('+573001110092', 'Móvil Y'))],
            ['z', $this->raceRequest('POST', 'integrations/canaries', $this->ana, $this->registerBody('+573001110093', 'Móvil Z'))],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame(10, StudioCanaryNumber::query()->where('channel', 'whatsapp')->where('status', '<>', 'revoked')->count(), 'el tope de 10 vivos se pasó: '.$this->summary($r));
        $this->assertSame(10, $this->events('canary_number.registered'));
        $this->assertCount(1, $this->withStatus($r, 201), $this->summary($r));
        foreach ($this->withStatus($r, 409) as $x) {
            $this->assertSame(['canary_live_limit', 10, 'whatsapp'], [$x['json']['code'] ?? null, $x['json']['max'] ?? null, $x['json']['channel'] ?? null], $this->summary($r));
        }
    }

    public function test_el_registro_que_espera_el_cerrojo_de_vivos_cuenta_despues_y_ve_el_tope(): void
    {
        $this->nineLiveNumbers();
        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', 'integrations/canaries', $this->ana, $this->registerBody('+573001110091', 'Móvil X'))],
            ['B', $this->raceRequest('POST', 'integrations/canaries', $this->bea, $this->registerBody('+573001110092', 'Móvil Y'))],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo de vivos de A');
        $this->assertSame(10, StudioCanaryNumber::query()->where('status', '<>', 'revoked')->count(), 'el tope de 10 vivos se pasó');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'canary_live_limit', 10], [$b['status'], $b['json']['code'] ?? null, $b['json']['max'] ?? null], $this->summary([$a, $b]));
    }

    // ── El tope de activos (§I4.8) ──────────────────────────────────────────

    public function test_cuatro_activaciones_simultaneas_contra_un_tope_de_tres_activan_tres(): void
    {
        $uuids = [];
        foreach (['+573001110011', '+573001110012', '+573001110013', '+573001110014'] as $telefono) {
            $uuids[] = $this->authorizedNumber($telefono);
        }
        $sesiones = [];
        foreach ($uuids as $i => $uuid) {
            $sesiones[] = ['a'.($i + 1), $this->raceRequest('POST', "integrations/canaries/{$uuid}/activate", $i % 2 === 0 ? $this->bea : $this->root)];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame(3, StudioCanaryNumber::query()->where('channel', 'whatsapp')->where('status', 'active')->count(), 'el tope de 3 se pasó: '.$this->summary($r));
        $this->assertSame(3, $this->events('canary_number.activated'));
        $this->assertCount(3, $this->withStatus($r, 200), $this->summary($r));
        $perdedoras = $this->withStatus($r, 409);
        $this->assertCount(1, $perdedoras, $this->summary($r));
        $this->assertSame(['canary_active_limit', 3, 'whatsapp'], [$perdedoras[0]['json']['code'] ?? null, $perdedoras[0]['json']['max'] ?? null, $perdedoras[0]['json']['channel'] ?? null]);
    }

    /**
     * EL ÚLTIMO HUECO, FORZADO. Dos números autorizados, uno activo y tope 2:
     * A activa y se para antes de su evento con el cerrojo consultivo del canal
     * tomado; B, que ya pasó sus puertas, espera ese cerrojo; A confirma y B
     * cuenta DESPUÉS (READ COMMITTED primero): ve los dos activos y se queda
     * fuera. En REPEATABLE READ por defecto (la lección B-2 de G) tiene que dar
     * lo mismo: si el servicio no fijara READ COMMITTED antes del cerrojo, la
     * instantánea de B se tomaría al pedirlo, antes de esperar, y no vería la
     * activación de A.
     *
     * @return array<string, array{0:?string}>
     */
    public static function isolations(): array
    {
        return ['por defecto' => [null], 'repeatable read' => ['repeatable read']];
    }

    #[DataProvider('isolations')]
    public function test_la_activacion_que_espera_el_cerrojo_del_canal_cuenta_despues_y_ve_el_tope(?string $isolation): void
    {
        config(['ultron_studio.integrations.canary.max_active' => 2]);
        $this->activeNumber('+573001110021');
        $x = $this->authorizedNumber('+573001110022');
        $y = $this->authorizedNumber('+573001110023');

        $ia = $this->spawnRacer('A', $this->raceRequest('POST', "integrations/canaries/{$x}/activate", $this->bea), [
            'pause' => self::pauseBefore(['insert into "ultron_studio_audit_events"'], true, 'blocked'),
            'isolation' => $isolation,
        ]);
        $this->awaitRacerLine($ia, 'PAUSED');
        $ib = $this->spawnRacer('B', $this->raceRequest('POST', "integrations/canaries/{$y}/activate", $this->root), ['isolation' => $isolation]);
        [$a, $b] = [$this->collectRacer($ia), $this->collectRacer($ib)];

        $this->assertRacersClean([$a, $b]);
        if ($isolation !== null) {
            $this->assertSame([$isolation, $isolation], [$a['isolation'], $b['isolation']]);
        }
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo del canal: la carrera no ocurrió');
        $this->assertSame(2, StudioCanaryNumber::query()->where('status', 'active')->count(), 'el tope de 2 se pasó');
        $this->assertSame(2, $this->events('canary_number.activated'));
        $this->assertSame(200, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'canary_active_limit', 2], [$b['status'], $b['json']['code'] ?? null, $b['json']['max'] ?? null], $this->summary([$a, $b]));
    }

    // ── Autorizar frente a revocar ──────────────────────────────────────────

    public function test_autorizar_y_revocar_a_la_vez_dejan_el_numero_revocado_y_un_libro_que_cuenta_lo_que_paso(): void
    {
        $uuid = $this->pendingNumber(self::PHONE);

        $r = $this->raceAll([
            ['authorize', $this->raceRequest('POST', "integrations/canaries/{$uuid}/authorize", $this->root)],
            ['revoke', $this->raceRequest('POST', "integrations/canaries/{$uuid}/revoke", $this->bea, ['reason' => 'Revocado en plena autorización'])],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        [$aut, $rev] = $r;
        $this->assertSame(200, $rev['status'], 'revocar admite pending y authorized: '.$this->summary($r));
        $this->assertSame('revoked', StudioCanaryNumber::query()->where('uuid', $uuid)->value('status'));
        $this->assertSame(1, $this->events('canary_number.revoked'));
        if ($aut['status'] === 200) {
            $this->assertSame(1, $this->events('canary_number.authorized'));
            $this->assertSame('authorized', $this->eventOf('canary_number.revoked', $uuid)->from_status, 'el libro dice que se revocó desde «pending», pero se revocó ya autorizado');
        } else {
            $this->assertSame([409, 'invalid_transition', 'revoked'], [$aut['status'], $aut['json']['code'] ?? null, $aut['json']['currentStatus'] ?? null], $this->summary($r));
            $this->assertSame(0, $this->events('canary_number.authorized'));
            $this->assertSame('pending', $this->eventOf('canary_number.revoked', $uuid)->from_status);
        }
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function authorizeVersusRevoke(): array
    {
        return ['gana autorizar' => ['authorize'], 'gana revocar' => ['revoke']];
    }

    /**
     * FORZADA: la que gana hace su UPDATE condicionado y se para antes de su
     * evento hasta que la otra espera el cerrojo de la FILA (su UPDATE, que ya
     * leyó el número en `pending` fuera de la transacción). Al confirmar, la
     * otra reevalúa su WHERE con la fila nueva (READ COMMITTED):
     *  - gana autorizar: revocar admite `authorized` y revoca; su evento tiene
     *    que decir que venía de `authorized` (el libro cuenta lo que pasó);
     *  - gana revocar: autorizar no toca ninguna fila y responde 409
     *    `invalid_transition` con el estado de AHORA (`revoked`).
     */
    #[DataProvider('authorizeVersusRevoke')]
    public function test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila(string $gana): void
    {
        $uuid = $this->pendingNumber(self::PHONE);
        $autorizar = ['authorize', $this->raceRequest('POST', "integrations/canaries/{$uuid}/authorize", $this->root)];
        $revocar = ['revoke', $this->raceRequest('POST', "integrations/canaries/{$uuid}/revoke", $this->bea, ['reason' => 'Revocado en plena autorización'])];

        [$a, $b] = $gana === 'authorize' ? $this->holdUntilRivalWaits($autorizar, $revocar) : $this->holdUntilRivalWaits($revocar, $autorizar);

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'la otra nunca esperó la fila: la carrera no ocurrió');
        $this->assertSame(200, $a['status'], $this->summary([$a, $b]));
        $fila = StudioCanaryNumber::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame('revoked', $fila->status);
        $this->assertSame(1, $this->events('canary_number.revoked'));
        if ($gana === 'authorize') {
            $this->assertSame(200, $b['status'], 'revocar un número recién autorizado: '.$this->summary([$a, $b]));
            $this->assertSame($this->root->id, $fila->authorized_by_admin_id);
            $this->assertSame(1, $this->events('canary_number.authorized'));
            // El libro: pending → authorized y DESPUÉS authorized → revoked.
            $this->assertSame(
                [['canary_number.registered', null, 'pending'], ['canary_number.authorized', 'pending', 'authorized'], ['canary_number.revoked', 'authorized', 'revoked']],
                $this->bookOf($uuid),
                'el evento de revocar lleva el estado que se leyó ANTES de esperar la fila, no el que tenía al revocarla',
            );
        } else {
            $this->assertSame([409, 'invalid_transition', 'revoked', ['pending']], [$b['status'], $b['json']['code'] ?? null, $b['json']['currentStatus'] ?? null, $b['json']['allowedFrom'] ?? null], $this->summary([$a, $b]));
            $this->assertNull($fila->authorized_by_admin_id);
            $this->assertSame(0, $this->events('canary_number.authorized'));
            $this->assertSame([['canary_number.registered', null, 'pending'], ['canary_number.revoked', 'pending', 'revoked']], $this->bookOf($uuid));
        }
    }

    // ── Doble clic sin clientRequestId (autorizar y activar) y cuatro ojos ──

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function transitionTwice(): array
    {
        return ['autorizar' => ['authorize', 'authorized', 'canary_number.authorized'], 'activar' => ['activate', 'active', 'canary_number.activated']];
    }

    /**
     * Autorizar y activar no llevan cuerpo (§I4.9, rutas 50 y 51): el doble
     * clic no es un `replayed`, es UN efecto y, para las demás, 409
     * `invalid_transition` con el estado de destino («ya estaba hecho»).
     */
    #[DataProvider('transitionTwice')]
    public function test_cinco_clics_simultaneos_en_autorizar_o_activar_hacen_un_solo_efecto(string $accion, string $destino, string $evento): void
    {
        $uuid = $accion === 'authorize' ? $this->pendingNumber(self::PHONE) : $this->authorizedNumber(self::PHONE);
        $sesiones = [];
        for ($i = 1; $i <= 5; $i++) {
            $sesiones[] = ["c{$i}", $this->raceRequest('POST', "integrations/canaries/{$uuid}/{$accion}", $this->root)];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame($destino, StudioCanaryNumber::query()->where('uuid', $uuid)->value('status'));
        $this->assertSame(1, $this->events($evento), 'más de un evento para un solo efecto');
        $this->assertCount(1, $this->withStatus($r, 200), $this->summary($r));
        foreach ($r as $x) {
            if ($x['status'] !== 200) {
                $this->assertSame([409, 'invalid_transition', $destino], [$x['status'], $x['json']['code'] ?? null, $x['json']['currentStatus'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
    }

    /**
     * Diez intentos simultáneos de quien registró (cada uno en su pestaña):
     * todos 403 `four_eyes_required`, el número no se mueve y el libro guarda
     * UN `four_eyes_denied` por acción (deduplicado con cerrojo, §I8). Autorizar
     * va en REPEATABLE READ por defecto (§A10.6 del H5).
     *
     * @return array<string, array{0:string,1:?string}>
     */
    public static function fourEyesStorms(): array
    {
        return ['autorizar en repeatable read' => ['authorize', 'repeatable read'], 'activar' => ['activate', null]];
    }

    #[DataProvider('fourEyesStorms')]
    public function test_diez_intentos_simultaneos_de_quien_registro_dejan_un_solo_four_eyes_denied(string $accion, ?string $isolation): void
    {
        $quien = $this->personWith(self::SUPER_KEYS, 'Sara Registra');
        $uuid = (string) $this->registerCanary($quien, self::PHONE)->assertCreated()->json('data.number.uuid');
        if ($accion === 'activate') {
            $this->integrations('POST', "canaries/{$uuid}/authorize", $this->root)->assertOk();
        }
        $antes = (string) StudioCanaryNumber::query()->where('uuid', $uuid)->value('status');
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $sesiones[] = ["s{$i}", $this->raceRequest('POST', "integrations/canaries/{$uuid}/{$accion}", $quien), ['isolation' => $isolation]];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        // Primero la integridad (la base la garantiza aunque fallara el servicio:
        // `uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk`)…
        $fila = StudioCanaryNumber::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($antes, $fila->status, 'quien registró movió su propio número');
        $this->assertNotSame($quien->id, $fila->authorized_by_admin_id);
        $this->assertNotSame($quien->id, $fila->activated_by_admin_id);
        // …y después el contrato del servicio.
        $this->assertRacersClean($r);
        foreach ($r as $x) {
            $this->assertSame([403, 'four_eyes_required', $accion], [$x['status'], $x['json']['code'] ?? null, $x['json']['action'] ?? null], "{$x['label']}: ".$this->summary($r));
            if ($isolation !== null) {
                $this->assertSame($isolation, $x['isolation']);
            }
        }
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'canary_number.four_eyes_denied')->where('subject_uuid', $uuid)->where('actor_admin_id', $quien->id)->count(), 'la deduplicación dejó más de una fila');
    }

    // ── Dos asociaciones de la misma campaña ────────────────────────────────

    public function test_diez_asociaciones_simultaneas_de_la_misma_campana_dejan_una_activa_y_un_evento(): void
    {
        $this->campaignReady();
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $quien = $i % 2 === 0 ? $this->bea : $this->ana;
            $sesiones[] = ["l{$i}", $this->raceRequest('POST', 'integrations/meta/links', $quien, $this->linkBody())];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $this->assertSame(1, StudioCampaignLink::query()->where('status', 'active')->count());
        $this->assertSame(1, StudioCampaignLink::query()->count(), 'ninguna fila de las perdedoras');
        $this->assertSame(1, $this->events('campaign_link.created'));
        $uuid = $ganadoras[0]['json']['data']['link']['uuid'];
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([409, 'campaign_already_linked', $uuid], [$x['status'], $x['json']['code'] ?? null, $x['json']['current']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
        $this->assertNoMetaIdIn($r);
    }

    public function test_la_asociacion_que_espera_el_cerrojo_de_la_ganadora_recibe_campaign_already_linked(): void
    {
        $this->campaignReady();
        [$a, $b] = $this->holdUntilRivalWaits(
            ['A', $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $this->linkBody())],
            ['B', $this->raceRequest('POST', 'integrations/meta/links', $this->bea, $this->linkBody())],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'B nunca esperó el cerrojo de A');
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $this->assertSame([409, 'campaign_already_linked', $a['json']['data']['link']['uuid']], [$b['status'], $b['json']['code'] ?? null, $b['json']['current']['uuid'] ?? null], $this->summary([$a, $b]));
        $this->assertSame([1, 1], [StudioCampaignLink::query()->count(), $this->events('campaign_link.created')]);
    }

    /**
     * EL ÍNDICE `uscl_one_active` ENTRE SESIONES (§I3.4 paso 11: «un 23505 en
     * `uscl_one_active` → 409 `campaign_already_linked`, resuelto FUERA de la
     * transacción abortada»). Un rival crudo inserta una activa de la misma
     * campaña sin el cerrojo y sin confirmar; la asociación real pasa sus
     * puertas, inserta, espera el índice, recibe el 23505 al confirmar el rival
     * y responde con la activa de ahora (la del rival). Nada suyo queda.
     */
    public function test_un_rival_sin_cerrojo_hace_esperar_a_la_asociacion_en_uscl_one_active_y_no_queda_nada_suyo(): void
    {
        $this->campaignReady();
        $ahora = now()->utc()->format('Y-m-d H:i:s');
        $rivalUuid = (string) Str::uuid();
        $ir = $this->spawnRacer('rival', null, ['raw' => [
            'statements' => [[
                "INSERT INTO ultron_studio_campaign_links (uuid, client_request_id, ad_account_id, campaign_id, campaign_name_at_link, target_kind, status, created_by_admin_id, created_by_name, created_by_role, created_at)
                    VALUES (?, ?, ?, ?, 'Pauta del rival', 'baseline', 'active', ?, ?, ?, ?)",
                [$rivalUuid, (string) Str::uuid(), self::ACCOUNTS[0], self::CAMPAIGN, $this->bea->id, $this->bea->name, $this->bea->role, $ahora],
            ]],
            'timeout' => 20.0,
        ]]);
        $this->awaitRacerLine($ir, 'HOLDING');
        $a = $this->collectRacer($this->spawnRacer('A', $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $this->linkBody())));
        $rival = $this->collectRacer($ir);

        $this->assertNull($rival['error'], json_encode($rival['error']));
        $this->assertGreaterThanOrEqual(1, $rival['waiters'], 'la asociación no esperó el índice uscl_one_active');
        $this->assertRacersClean([$a]);
        $this->assertSame([409, 'campaign_already_linked', $rivalUuid], [$a['status'], $a['json']['code'] ?? null, $a['json']['current']['uuid'] ?? null], (string) $a['body']);
        $this->assertSame([$rivalUuid], StudioCampaignLink::query()->pluck('uuid')->all());
        $this->assertSame(0, $this->events('campaign_link.created'));
    }

    // ── El tope global de asociaciones activas (§I3.4 paso 10, D-3 de S) ────

    public function test_dos_asociaciones_simultaneas_de_campanas_distintas_contra_el_ultimo_hueco_crean_una(): void
    {
        [$b, $c] = $this->linkCapWithOneSlotLeft();
        $r = $this->raceAll([
            ['b', $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $this->linkBody(campaign: $b))],
            ['c', $this->raceRequest('POST', 'integrations/meta/links', $this->bea, $this->linkBody(campaign: $c))],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame(2, StudioCampaignLink::query()->where('status', 'active')->count(), 'el tope de 2 activas se pasó: '.$this->summary($r));
        $this->assertSame(2, $this->events('campaign_link.created'));
        $this->assertCount(1, $this->withStatus($r, 201), $this->summary($r));
        $perdedora = $this->withStatus($r, 409);
        $this->assertSame(['campaign_links_limit', 2], [$perdedora[0]['json']['code'] ?? null, $perdedora[0]['json']['max'] ?? null], $this->summary($r));
    }

    public function test_la_asociacion_que_espera_el_cerrojo_del_tope_cuenta_despues_y_ve_el_tope(): void
    {
        [$b, $c] = $this->linkCapWithOneSlotLeft();
        [$x, $y] = $this->holdUntilRivalWaits(
            ['B', $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $this->linkBody(campaign: $b))],
            ['C', $this->raceRequest('POST', 'integrations/meta/links', $this->bea, $this->linkBody(campaign: $c))],
        );

        $this->assertRacersClean([$x, $y]);
        $this->assertGreaterThanOrEqual(1, $x['waiters'], 'C nunca esperó el cerrojo de las asociaciones');
        $this->assertSame(2, StudioCampaignLink::query()->where('status', 'active')->count(), 'el tope de 2 activas se pasó');
        $this->assertSame(201, $x['status'], $this->summary([$x, $y]));
        $this->assertSame([409, 'campaign_links_limit', 2], [$y['status'], $y['json']['code'] ?? null, $y['json']['max'] ?? null], $this->summary([$x, $y]));
    }

    // ── La sustitución concurrente (D-1: la sustituta se anota una vez) ─────

    public function test_seis_sustituciones_simultaneas_de_la_misma_activa_dejan_una_sustituta_anotada_una_vez(): void
    {
        $this->campaignReady();
        $vieja = $this->linkNow($this->ana);
        $sesiones = [];
        for ($i = 1; $i <= 6; $i++) {
            $quien = $i % 2 === 0 ? $this->bea : $this->ana;
            $sesiones[] = ["s{$i}", $this->raceRequest('POST', 'integrations/meta/links', $quien, $this->linkBody($this->versionIn($this->root, 'APPROVED')->uuid, $vieja))];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $nueva = $ganadoras[0]['json']['data']['link']['uuid'];
        $this->assertReplacedOnce($vieja, $nueva);
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([409, 'campaign_link_moved', $nueva], [$x['status'], $x['json']['code'] ?? null, $x['json']['current']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
    }

    /**
     * FORZADA: B ya pasó sus puertas (la activa era la vieja y `replaces` casaba)
     * y entra en su transacción después de que A sustituyó: su UPDATE
     * condicionado no cierra nada y responde `campaign_link_moved` con la activa
     * de AHORA. La vieja queda anotada una vez, con la de A.
     */
    public function test_la_sustitucion_que_entra_despues_de_otra_no_cierra_nada_y_recibe_campaign_link_moved(): void
    {
        $this->campaignReady();
        $vieja = $this->linkNow($this->ana);
        [$a, $b] = $this->entersAfter(
            ['B', $this->raceRequest('POST', 'integrations/meta/links', $this->bea, $this->linkBody($this->versionIn($this->root, 'APPROVED')->uuid, $vieja))],
            ['A', $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $this->linkBody($this->versionIn($this->root, 'CANARY')->uuid, $vieja))],
            ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'],
        );

        $this->assertRacersClean([$a, $b]);
        $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
        $nueva = $a['json']['data']['link']['uuid'];
        $this->assertSame([409, 'campaign_link_moved', $nueva], [$b['status'], $b['json']['code'] ?? null, $b['json']['current']['uuid'] ?? null], $this->summary([$a, $b]));
        $this->assertReplacedOnce($vieja, $nueva);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function replaceVersusDeactivate(): array
    {
        return ['gana sustituir' => ['replace'], 'gana desactivar' => ['deactivate']];
    }

    #[DataProvider('replaceVersusDeactivate')]
    public function test_sustituir_frente_a_desactivar_la_misma_activa(string $gana): void
    {
        $this->campaignReady();
        $vieja = $this->linkNow($this->ana);
        $sustituir = ['replace', $this->raceRequest('POST', 'integrations/meta/links', $this->bea, $this->linkBody($this->versionIn($this->root, 'APPROVED')->uuid, $vieja))];
        $desactivar = ['deactivate', $this->raceRequest('POST', "integrations/meta/links/{$vieja}/deactivate", $this->ana, ['reason' => 'Desactivada en plena sustitución'])];

        [$a, $b] = $gana === 'replace' ? $this->holdUntilRivalWaits($sustituir, $desactivar) : $this->holdUntilRivalWaits($desactivar, $sustituir);

        $this->assertRacersClean([$a, $b]);
        $this->assertGreaterThanOrEqual(1, $a['waiters'], 'la otra nunca esperó: la carrera no ocurrió');
        $filaVieja = StudioCampaignLink::query()->where('uuid', $vieja)->firstOrFail();
        $this->assertSame('inactive', $filaVieja->status);
        $this->assertSame(1, $this->events('campaign_link.deactivated', $vieja), 'la vieja se cerró dos veces en el libro');
        if ($gana === 'replace') {
            $this->assertSame(201, $a['status'], $this->summary([$a, $b]));
            $this->assertSame([409, 'invalid_transition', 'inactive'], [$b['status'], $b['json']['code'] ?? null, $b['json']['currentStatus'] ?? null], $this->summary([$a, $b]));
            $this->assertReplacedOnce($vieja, $a['json']['data']['link']['uuid']);
        } else {
            $this->assertSame(200, $a['status'], $this->summary([$a, $b]));
            $this->assertSame([409, 'campaign_link_moved', null], [$b['status'], $b['json']['code'] ?? null, array_key_exists('current', $b['json']) ? $b['json']['current'] : 'ausente'], $this->summary([$a, $b]));
            $this->assertNull($filaVieja->replaced_by_link_id);
            $this->assertSame('Desactivada en plena sustitución', $filaVieja->deactivation_reason);
            $this->assertSame([1, 1], [StudioCampaignLink::query()->count(), $this->events('campaign_link.created')], 'la sustitución perdedora dejó una fila o un evento');
        }
    }

    public function test_diez_asociaciones_con_la_misma_client_request_id_dan_una_201_y_nueve_replayed(): void
    {
        $this->campaignReady();
        $cuerpo = $this->linkBody();
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $sesiones[] = ["l{$i}", $this->raceRequest('POST', 'integrations/meta/links', $this->ana, $cuerpo)];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $this->assertSame([1, 1], [StudioCampaignLink::query()->count(), $this->events('campaign_link.created')]);
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, $ganadoras[0]['json']['data']['link']], [$x['status'], $x['json']['data']['replayed'] ?? null, $x['json']['data']['link'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
    }

    // ── Reconocer la configuración ──────────────────────────────────────────

    public function test_dos_reconocimientos_simultaneos_de_dos_personas_son_dos_instantaneas_con_su_evento_cada_una(): void
    {
        $this->twilioConfigured();
        $huella = $this->effectiveFingerprint('twilio');
        $r = $this->raceAll([
            ['root', $this->raceRequest('POST', 'integrations/config/acknowledge', $this->root, $this->ackBody('twilio', $huella))],
            ['rita', $this->raceRequest('POST', 'integrations/config/acknowledge', $this->rita, $this->ackBody('twilio', $huella))],
        ], self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame([201, 201], array_column($r, 'status'), $this->summary($r));
        $filas = StudioIntegrationSnapshot::query()->orderBy('id')->get();
        $this->assertCount(2, $filas);
        foreach ($filas as $fila) {
            $this->assertSame(['twilio', 'config', 'admin', $huella], [$fila->provider, $fila->kind, $fila->actor_type, $fila->fingerprint]);
            $this->assertSame(1, $this->events('integration.config_acknowledged', (string) $fila->uuid), 'cada instantánea, un evento');
        }
        $this->assertSame(2, $this->events('integration.config_acknowledged'));
        $estado = collect($this->integrations('GET', 'config', $this->root)->assertOk()->json('data.providers'))->firstWhere('provider', 'twilio');
        $this->assertSame('authorized', $estado['state']);
        $this->assertSame($filas->last()->uuid, $estado['authorized']['uuid'], 'la autorizada es la última de una persona');
    }

    public function test_diez_reconocimientos_con_la_misma_client_request_id_dejan_una_instantanea(): void
    {
        $this->twilioConfigured();
        $cuerpo = $this->ackBody('twilio', $this->effectiveFingerprint('twilio'));
        $sesiones = [];
        for ($i = 1; $i <= self::N; $i++) {
            $sesiones[] = ["k{$i}", $this->raceRequest('POST', 'integrations/config/acknowledge', $this->root, $cuerpo)];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $this->assertSame([1, 1], [StudioIntegrationSnapshot::query()->count(), $this->events('integration.config_acknowledged')]);
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, $ganadoras[0]['json']['data']['snapshot']], [$x['status'], $x['json']['data']['replayed'] ?? null, $x['json']['data']['snapshot'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
    }

    /**
     * RECONOCER FRENTE A UN CAMBIO DE CONFIGURACIÓN (§I11). La persona vio la
     * huella F en el panel. A la vez: un proceso que sigue con la configuración
     * de antes reconoce F; otro ya desplegado (con el SID de Verify cambiado)
     * recibe el mismo reconocimiento, y el comando de instantáneas del
     * despliegue anota lo observado. Lo que tiene que quedar, en cualquier
     * orden: el proceso viejo autoriza F (su efectiva), el nuevo responde
     * `config_changed` con SU huella y nunca autoriza F, y el panel del proceso
     * nuevo enseña DERIVA (autorizada F, observada la nueva, la clave que
     * cambió), nunca «autorizada».
     */
    public function test_reconocer_frente_a_un_cambio_de_configuracion_nunca_autoriza_una_huella_que_no_es_la_efectiva(): void
    {
        $this->twilioConfigured();
        $vieja = $this->effectiveFingerprint('twilio');
        $cambio = ['otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'1a2b'];
        $nueva = $this->effectiveFingerprint('twilio', $cambio);
        $this->assertNotSame($vieja, $nueva);

        $r = $this->raceAll([
            ['viejo', $this->raceRequest('POST', 'integrations/config/acknowledge', $this->root, $this->ackBody('twilio', $vieja))],
            ['nuevo', $this->raceRequest('POST', 'integrations/config/acknowledge', $this->rita, $this->ackBody('twilio', $vieja)), ['config' => $cambio]],
            ['comando', null, ['config' => $cambio, 'artisan' => ['command' => 'ultron-studio:integrations-snapshot', 'params' => ['--json' => true]]]],
        ], self::BARRIER);

        [$viejo, $nuevo, $comando] = $r;
        $this->assertRacersClean([$viejo, $nuevo]);
        $this->assertNull($comando['error'], json_encode($comando['error']));
        $this->assertSame(0, $comando['exit'], (string) $comando['output']);
        $this->assertSame(201, $viejo['status'], $this->summary($r));
        $this->assertSame([409, 'config_changed', $nueva], [$nuevo['status'], $nuevo['json']['code'] ?? null, $nuevo['json']['effectiveFingerprint'] ?? null], $this->summary($r));

        $personas = StudioIntegrationSnapshot::query()->where('provider', 'twilio')->where('kind', 'config')->where('actor_type', 'admin')->get();
        $this->assertSame([[$this->root->id, $vieja]], $personas->map(fn ($s): array => [(int) $s->actor_admin_id, $s->fingerprint])->all(), 'solo el proceso viejo reconoció, y lo que reconoció es su efectiva');
        $this->assertSame($vieja, StudioIntegrationConfig::fingerprintOf($personas->sole()->facts), 'la huella guardada es la de sus facts');
        $this->assertSame(1, $this->events('integration.config_acknowledged'));
        $sistema = StudioIntegrationSnapshot::query()->where('provider', 'twilio')->where('kind', 'config')->where('actor_type', 'system')->pluck('fingerprint')->all();
        $this->assertSame([$nueva], $sistema, 'el comando anotó lo observado por el proceso nuevo');

        // El panel del proceso nuevo: deriva.
        config($cambio);
        $estado = collect($this->integrations('GET', 'config', $this->root)->assertOk()->json('data.providers'))->firstWhere('provider', 'twilio');
        $this->assertSame(['drift', $nueva, $vieja, $nueva], [$estado['state'], $estado['effectiveFingerprint'], $estado['authorized']['fingerprint'] ?? null, $estado['observed']['fingerprint'] ?? null]);
        $this->assertSame(['verifyServiceSid'], array_column($estado['drift'], 'key'));
    }

    // ── La sonda (§I5.4: enfriamiento, cerrojo de la caché compartida) ──────

    /**
     * CUATRO PULSACIONES A LA VEZ, SIN SONDA PREVIA (dos personas, dos
     * pestañas cada una). Con la caché de producción (`database`), el cerrojo
     * las pone en fila: la primera hace los 5 GET y las demás, dentro del
     * enfriamiento de la primera, la reutilizan sin red ni evento.
     */
    public function test_cuatro_pulsaciones_simultaneas_de_la_sonda_salen_una_vez(): void
    {
        $this->probeConfigured();
        $sesiones = [];
        foreach ([$this->root, $this->rita, $this->root, $this->rita] as $i => $quien) {
            $sesiones[] = ['p'.($i + 1), $this->raceRequest('POST', 'integrations/twilio/probe', $quien, ['clientRequestId' => (string) Str::uuid()]), $this->probeSession()];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame(1, StudioIntegrationSnapshot::query()->where('kind', 'probe')->count(), 'más de una sonda salió: '.$this->summary($r));
        $this->assertSame(1, $this->events('integration.probe_run'));
        $this->assertSame(5, array_sum(array_map(static fn (array $x): int => count($x['http']), $r)), 'los 5 GET, una sola vez: '.json_encode(array_column($r, 'http')));
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        $this->assertCount(5, $ganadoras[0]['http']);
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, false, $ganadoras[0]['json']['data']['snapshot']['uuid']], [$x['status'], $x['json']['data']['reused'] ?? null, $x['json']['data']['replayed'] ?? null, $x['json']['data']['snapshot']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
                $this->assertSame([], $x['http'], "{$x['label']} salió a la red");
            }
        }
    }

    /**
     * FORZADA: A tiene el cerrojo de la caché, ya hizo sus 5 GET y se para
     * antes de guardar la instantánea; B llega al cerrojo (lo intenta y espera
     * en su bucle); A guarda y suelta; B entra, vuelve a mirar el enfriamiento y
     * reutiliza la de A. B no hizo ninguna petición.
     */
    public function test_la_segunda_pulsacion_que_espera_el_cerrojo_de_la_cache_reutiliza_la_primera_sin_red(): void
    {
        $this->probeConfigured();
        $this->holdLock(self::RELEASE);
        try {
            $ia = $this->spawnRacer('A', $this->raceRequest('POST', 'integrations/twilio/probe', $this->root, ['clientRequestId' => (string) Str::uuid()]),
                $this->probeSession() + ['pause' => self::pauseBefore(['insert into "ultron_studio_integration_snapshots"'], true, 'released', self::RELEASE)]);
            $this->awaitRacerLine($ia, 'PAUSED');
            $ib = $this->spawnRacer('B', $this->raceRequest('POST', 'integrations/twilio/probe', $this->rita, ['clientRequestId' => (string) Str::uuid()]),
                $this->probeSession() + ['pause' => self::pauseBefore(['insert into "cache_locks"'], false, 'signal')]);
            $this->awaitRacerLine($ib, 'PAUSED');
            // B ya pidió el cerrojo; que falle al menos dos veces (cada intento, 250 ms).
            usleep(700_000);
            $this->pumpRacer($ib, 0.0);
            $this->assertNull($this->racers[$ib]['result'], 'B terminó sin esperar el cerrojo de la caché de A');
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        [$a, $b] = [$this->collectRacer($ia), $this->collectRacer($ib)];

        $this->assertRacersClean([$a, $b]);
        $this->assertSame(1, StudioIntegrationSnapshot::query()->where('kind', 'probe')->count());
        $this->assertSame(1, $this->events('integration.probe_run'));
        $this->assertSame([201, 5], [$a['status'], count($a['http'])], $this->summary([$a, $b]));
        $this->assertSame([200, true, $a['json']['data']['snapshot']['uuid']], [$b['status'], $b['json']['data']['reused'] ?? null, $b['json']['data']['snapshot']['uuid'] ?? null], $this->summary([$a, $b]));
        $this->assertSame([], $b['http'], 'B salió a la red');
    }

    public function test_tres_clics_de_la_sonda_con_la_misma_client_request_id_salen_una_vez_y_dos_replayed(): void
    {
        $this->probeConfigured();
        $cuerpo = ['clientRequestId' => (string) Str::uuid()];
        $sesiones = [];
        for ($i = 1; $i <= 3; $i++) {
            $sesiones[] = ["c{$i}", $this->raceRequest('POST', 'integrations/twilio/probe', $this->root, $cuerpo), $this->probeSession()];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        $this->assertSame([1, 1], [StudioIntegrationSnapshot::query()->where('kind', 'probe')->count(), $this->events('integration.probe_run')]);
        $this->assertSame(5, array_sum(array_map(static fn (array $x): int => count($x['http']), $r)));
        $ganadoras = $this->withStatus($r, 201);
        $this->assertCount(1, $ganadoras, $this->summary($r));
        foreach ($r as $x) {
            if ($x['status'] !== 201) {
                $this->assertSame([200, true, $ganadoras[0]['json']['data']['snapshot']['uuid']], [$x['status'], $x['json']['data']['replayed'] ?? null, $x['json']['data']['snapshot']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
            }
        }
    }

    public function test_tres_pulsaciones_simultaneas_dentro_del_enfriamiento_reutilizan_la_ultima_sin_red_ni_evento(): void
    {
        $this->probeConfigured();
        // Una sonda de hace 100 s (dentro de los 300 del enfriamiento), por la API.
        self::at('2026-11-03T14:58:20Z');
        $this->integrations('POST', 'twilio/probe', $this->root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        self::at('2026-11-03T15:00:00Z');
        $previa = (string) StudioIntegrationSnapshot::query()->where('kind', 'probe')->value('uuid');
        $sesiones = [];
        foreach ([$this->root, $this->rita, $this->root] as $i => $quien) {
            $sesiones[] = ['p'.($i + 1), $this->raceRequest('POST', 'integrations/twilio/probe', $quien, ['clientRequestId' => (string) Str::uuid()]), $this->probeSession()];
        }
        $r = $this->raceAll($sesiones, self::BARRIER);

        $this->assertRacersClean($r);
        foreach ($r as $x) {
            $this->assertSame([200, true, $previa], [$x['status'], $x['json']['data']['reused'] ?? null, $x['json']['data']['snapshot']['uuid'] ?? null], "{$x['label']}: ".$this->summary($r));
            $this->assertSame([], $x['http'], "{$x['label']} salió a la red");
        }
        $this->assertSame([1, 1], [StudioIntegrationSnapshot::query()->where('kind', 'probe')->count(), $this->events('integration.probe_run')]);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * A se para antes de su evento (dentro de su transacción) hasta que B espera
     * uno de sus cerrojos; B se lanza cuando A ya está parada.
     *
     * @param  array{0:string,1:array<string,mixed>}  $a
     * @param  array{0:string,1:array<string,mixed>}  $b
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function holdUntilRivalWaits(array $a, array $b): array
    {
        $ia = $this->spawnRacer($a[0], $a[1], ['pause' => self::pauseBefore(['insert into "ultron_studio_audit_events"'], true, 'blocked')]);
        $this->awaitRacerLine($ia, 'PAUSED');
        $ib = $this->spawnRacer($b[0], $b[1]);

        return [$this->collectRacer($ia), $this->collectRacer($ib)];
    }

    /**
     * B pasa sus puertas y se para antes de la primera sentencia de su
     * transacción que contiene `$fragmentos`; A se ejecuta entera; B sigue.
     *
     * @param  array{0:string,1:array<string,mixed>}  $b
     * @param  array{0:string,1:array<string,mixed>}  $a
     * @param  list<string>  $fragmentos
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [A, B]
     */
    private function entersAfter(array $b, array $a, array $fragmentos): array
    {
        $this->holdLock(self::RELEASE);
        try {
            $ib = $this->spawnRacer($b[0], $b[1], ['pause' => self::pauseBefore($fragmentos, true, 'released', self::RELEASE)]);
            $this->awaitRacerLine($ib, 'PAUSED');
            $ra = $this->collectRacer($this->spawnRacer($a[0], $a[1]));
        } finally {
            $this->releaseLock(self::RELEASE);
        }
        $rb = $this->collectRacer($ib);
        $this->assertTrue($rb['paused'], "{$b[0]} no llegó a su transacción");

        return [$ra, $rb];
    }

    /**
     * La petición que se manda dos veces y dónde está lo que tiene que repetir.
     *
     * @return array{0:string,1:array<string,mixed>,2:Admin,3:string}
     */
    private function sameRequestTwice(string $accion): array
    {
        return match ($accion) {
            'register' => ['integrations/canaries', $this->registerBody(self::PHONE, 'Móvil de pruebas 1'), $this->ana, 'data.number.uuid'],
            'link' => (function (): array {
                $this->campaignReady();

                return ['integrations/meta/links', $this->linkBody(), $this->ana, 'data.link'];
            })(),
            'replace' => (function (): array {
                $this->campaignReady();
                $vieja = $this->linkNow($this->bea);

                return ['integrations/meta/links', $this->linkBody($this->versionIn($this->root, 'APPROVED')->uuid, $vieja), $this->ana, 'data.link.uuid'];
            })(),
            'acknowledge' => (function (): array {
                $this->twilioConfigured();

                return ['integrations/config/acknowledge', $this->ackBody('twilio', $this->effectiveFingerprint('twilio')), $this->root, 'data.snapshot'];
            })(),
        };
    }

    /** @return array<string, mixed> */
    private function registerBody(string $phone, string $alias): array
    {
        return ['clientRequestId' => (string) Str::uuid(), 'channel' => 'whatsapp', 'phone' => $phone, 'alias' => $alias, 'consentAttested' => true];
    }

    /** @return array<string, mixed> */
    private function linkBody(?string $versionUuid = null, ?string $replaces = null, string $campaign = self::CAMPAIGN): array
    {
        return ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId($campaign),
            'target' => $versionUuid === null ? ['kind' => 'baseline'] : ['kind' => 'version', 'versionUuid' => $versionUuid]]
            + ($replaces === null ? [] : ['replaces' => $replaces]);
    }

    /** @return array<string, mixed> */
    private function ackBody(string $provider, string $fingerprint): array
    {
        return ['clientRequestId' => (string) Str::uuid(), 'provider' => $provider, 'fingerprint' => $fingerprint];
    }

    private function pendingNumber(string $phone): string
    {
        return (string) $this->registerCanary($this->ana, $phone)->assertCreated()->json('data.number.uuid');
    }

    private function authorizedNumber(string $phone): string
    {
        $uuid = $this->pendingNumber($phone);
        $this->integrations('POST', "canaries/{$uuid}/authorize", $this->root)->assertOk();

        return $uuid;
    }

    private function activeNumber(string $phone): string
    {
        $uuid = $this->authorizedNumber($phone);
        $this->integrations('POST', "canaries/{$uuid}/activate", $this->root)->assertOk();

        return $uuid;
    }

    private function campaignReady(): void
    {
        if (DB::table('meta_ad_entities')->where('level', 'campaign')->where('entity_id', self::CAMPAIGN)->exists()) {
            return;
        }
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::CAMPAIGN, 'Pauta octubre');
    }

    /** Nueve números vivos de WhatsApp (el tope de vivos es 10, fijo): queda un hueco. */
    private function nineLiveNumbers(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->pendingNumber(sprintf('+5730011100%02d', 30 + $i));
        }
        $this->assertSame(10, StudioIntegrations::canaryMaxLive());
    }

    /**
     * Tope de 2 asociaciones activas, una ya hecha (campaña A) y dos campañas
     * más en el espejo: queda un hueco. Devuelve las dos campañas libres.
     *
     * @return array{0:string,1:string}
     */
    private function linkCapWithOneSlotLeft(): array
    {
        $this->campaignReady();
        $this->metaCampaign(self::ACCOUNTS[0], '120000000000000388', 'Pauta noviembre');
        $this->metaCampaign(self::ACCOUNTS[1], '120000000000000389', 'Pauta diciembre');
        config(['ultron_studio.integrations.campaign_links.max_active' => 2]);
        $this->linkNow($this->ana);

        return ['120000000000000388', '120000000000000389'];
    }

    /** Una asociación activa de la campaña, por la API. */
    private function linkNow(Admin $as): string
    {
        return (string) $this->integrations('POST', 'meta/links', $as, $this->linkBody())->assertCreated()->json('data.link.uuid');
    }

    private function twilioConfigured(): void
    {
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => self::TWILIO_SID,
            'otp.twilio.token' => 'token-de-las-carreras',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
        ]);
    }

    private function probeConfigured(): void
    {
        $this->twilioConfigured();
        $this->integrationsFlags(writes: true, probe: true);
    }

    /**
     * Una sesión de la sonda: la caché de producción (`database`, compartida
     * entre procesos: el cerrojo y los cupos) y el simulador de Twilio dentro.
     *
     * @return array<string, mixed>
     */
    private function probeSession(): array
    {
        return ['config' => ['cache.default' => 'database'], 'env' => ['CACHE_STORE' => 'database'], 'http' => self::TWILIO_FAKE];
    }

    /** @param  array<string, mixed>  $retoques */
    private function effectiveFingerprint(string $provider, array $retoques = []): string
    {
        $antes = [];
        foreach ($retoques as $clave => $valor) {
            $antes[$clave] = config($clave);
        }
        config($retoques);
        try {
            return StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts($provider));
        } finally {
            config($antes);
        }
    }

    /** La vieja: inactiva, cerrada por sustitución y anotada UNA vez con la nueva, que es la única activa. */
    private function assertReplacedOnce(string $vieja, string $nueva): void
    {
        $filaVieja = StudioCampaignLink::query()->where('uuid', $vieja)->firstOrFail();
        $filaNueva = StudioCampaignLink::query()->where('uuid', $nueva)->firstOrFail();
        $this->assertSame(['inactive', 'Sustituida por otra asociación', (int) $filaNueva->id], [$filaVieja->status, $filaVieja->deactivation_reason, (int) $filaVieja->replaced_by_link_id]);
        $this->assertSame([$nueva], StudioCampaignLink::query()->where('status', 'active')->pluck('uuid')->all());
        $this->assertSame(2, StudioCampaignLink::query()->count(), 'una sustitución perdedora dejó su fila');
        $this->assertSame(1, StudioCampaignLink::query()->whereNotNull('replaced_by_link_id')->count());
        $this->assertSame(2, $this->events('campaign_link.created'), 'la vieja y una sola sustituta');
        $this->assertSame(1, $this->events('campaign_link.deactivated'));
        $creada = $this->eventOf('campaign_link.created', $nueva);
        $cerrada = $this->eventOf('campaign_link.deactivated', $vieja);
        $this->assertSame($vieja, $creada->diff['replaces'] ?? null);
        $this->assertSame($nueva, $cerrada->diff['replacedBy'] ?? null);
    }

    private function events(string $event, ?string $subject = null): int
    {
        return StudioAuditEvent::query()->where('event', $event)->when($subject !== null, fn ($q) => $q->where('subject_uuid', $subject))->count();
    }

    private function eventOf(string $event, string $subject): StudioAuditEvent
    {
        return StudioAuditEvent::query()->where('event', $event)->where('subject_uuid', $subject)->sole();
    }

    /** @return list<array{0:string,1:?string,2:?string}> El libro de un número, en orden: [evento, desde, hacia]. */
    private function bookOf(string $uuid): array
    {
        return StudioAuditEvent::query()->where('subject_uuid', $uuid)->where('event', '<>', 'canary_number.four_eyes_denied')->orderBy('id')->get()
            ->map(static fn (StudioAuditEvent $e): array => [(string) $e->event, $e->from_status, $e->to_status])->all();
    }

    /**
     * @param  list<array<string,mixed>>  $resultados
     * @return list<array<string,mixed>>
     */
    private function withStatus(array $resultados, int $status): array
    {
        return array_values(array_filter($resultados, static fn (array $x): bool => $x['status'] === $status));
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function assertRacersClean(array $resultados): void
    {
        foreach ($resultados as $x) {
            $this->assertNull($x['error'], "{$x['label']} lanzó: ".json_encode($x['error']));
            $this->assertIsInt($x['status'], "{$x['label']} sin estado");
            $this->assertLessThan(500, $x['status'], "{$x['label']}: ".$x['body']);
            $this->assertIsArray($x['json'], "{$x['label']} no respondió JSON");
        }
        $pids = array_column($resultados, 'pid');
        $this->assertSame(count($pids), count(array_unique($pids)), 'dos sesiones compartieron conexión');
        $this->assertNotContains((int) DB::scalar('select pg_backend_pid()'), $pids);
    }

    /**
     * Ni el teléfono, ni sus 10 dígitos nacionales, ni su huella en las
     * respuestas, el log o el stderr de ninguna sesión (P5).
     *
     * @param  list<array<string,mixed>>  $resultados
     */
    private function assertNoPhoneIn(array $resultados, string $e164): void
    {
        $todo = implode("\n", array_map(static fn (array $x): string => $x['body'].' '.implode(' ', $x['logs']).' '.$x['stderr'], $resultados));
        foreach ([$e164, substr($e164, 3), PhoneFingerprint::of($e164)] as $centinela) {
            $this->assertStringNotContainsString($centinela, $todo, "«{$centinela}» salió por alguna sesión de la carrera.");
        }
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function assertNoMetaIdIn(array $resultados): void
    {
        $todo = implode("\n", array_map(static fn (array $x): string => $x['body'].' '.implode(' ', $x['logs']).' '.$x['stderr'], $resultados));
        $this->assertStringNotContainsString(self::CAMPAIGN, $todo, 'el id de Meta salió del servidor');
        $this->assertStringNotContainsString(self::ACCOUNTS[0], $todo, 'el id de la cuenta salió del servidor');
    }

    /** @param  list<array<string,mixed>>  $resultados */
    private function summary(array $resultados): string
    {
        return json_encode(array_map(static fn (array $x): array => [
            $x['label'], $x['status'], $x['json']['code'] ?? (($x['json']['data']['replayed'] ?? false) ? 'replayed' : (($x['json']['data']['reused'] ?? false) ? 'reused' : null)),
            $x['json']['currentStatus'] ?? null, count($x['http'] ?? []),
        ], $resultados));
    }
}
