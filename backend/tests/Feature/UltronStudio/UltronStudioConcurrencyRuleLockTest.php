<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioContentHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * El cerrojo de las escrituras de reglas con DOS SESIONES de PostgreSQL de
 * verdad (contrato G §A3, «Concurrencia»: «Sin ese cerrojo, dos escrituras
 * simultáneas y contradictorias entre sí pasarían las dos»).
 *
 * `UltronStudioRuleConditionsTest` comprueba el ORDEN del SQL (cerrojo antes de
 * leer las reglas vivas) en una sola conexión. Esta prueba comprueba la
 * CONSECUENCIA con otra conexión: un proceso hijo toma el mismo cerrojo,
 * inserta una regla del Studio que contradice a la de la petición y la
 * CONFIRMA solo cuando ve en `pg_locks` que la petición está esperando ese
 * cerrojo. Con el cerrojo, la petición lee las reglas vivas DESPUÉS de esa
 * confirmación y responde 409 `rule_contradiction` citándola. Sin él, la
 * petición no espera, no la ve y guarda una regla contradictoria (la mutación
 * «quitar `serializeRuleWrites()` de `update`/`create`» hace fallar estas
 * pruebas; ver el informe del tester del Hito G).
 *
 * Solo PostgreSQL: SQLite no tiene cerrojos consultivos y escribe en serie.
 *
 * La preparación NO pasa por la API ni por `import-baseline`: en PostgreSQL la
 * prueba entera es una transacción (`RefreshDatabase`) y un cerrojo
 * `pg_advisory_xact_lock` tomado en ella no se suelta hasta el final, así que
 * el hijo no podría tomarlo nunca. Las reglas del código no hacen falta: los
 * controles del motor salen del código, no de la tabla.
 *
 * El hijo CONFIRMA una fila que la transacción de la prueba no puede deshacer
 * (y la tabla prohíbe DELETE y TRUNCATE), así que al terminar se pide que la
 * siguiente prueba vuelva a crear el esquema.
 */
class UltronStudioConcurrencyRuleLockTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    /** @var resource|null */
    private $hijo = null;

    /** @var array<int, resource> */
    private array $tuberias = [];

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Dos sesiones y un cerrojo consultivo: solo PostgreSQL (SQLite escribe en serie).');
        }
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        // Red de seguridad: si algo fuera mal, la prueba falla en segundos en vez de colgarse.
        DB::statement("SET lock_timeout = '30s'");
    }

    protected function tearDown(): void
    {
        $this->cerrarHijo();
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_un_patch_espera_el_cerrojo_y_ve_la_regla_que_otra_sesion_confirmo_mientras(): void
    {
        $s001 = $this->seedLiveRule('S-001', self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 400)]));

        // La otra sesión: «Longitud máxima ≥ 320», que choca con el «≤ 300» que va a pedir el PATCH.
        $otra = $this->otraSesionConfirmaCuandoEspere('S-900', self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)]));

        $r = $this->studio('PATCH', "rules/{$s001->uuid}", $this->ana, [
            'baseRevision' => 1,
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]),
        ]);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'El PATCH no esperó el cerrojo de las escrituras de reglas: '.$hijo['salida']);
        $r->assertStatus(409)
            ->assertJsonPath('code', 'rule_contradiction')
            ->assertJsonPath('conflicts.0.code', 'S-900')
            ->assertJsonPath('conflicts.0.uuid', $otra['uuid']);
        $fila = StudioRule::query()->where('uuid', $s001->uuid)->sole();
        $this->assertSame(1, $fila->revision);
        $this->assertSame(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 400)]), $fila->conditions);
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'rule.updated')->count());
    }

    public function test_un_post_espera_el_cerrojo_y_ve_la_regla_que_otra_sesion_confirmo_mientras(): void
    {
        $otra = $this->otraSesionConfirmaCuandoEspere('S-900', self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]));

        $r = $this->newStudioRule($this->ana, ['conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)])]);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'El POST no esperó el cerrojo de las escrituras de reglas: '.$hijo['salida']);
        $r->assertStatus(409)
            ->assertJsonPath('code', 'rule_contradiction')
            ->assertJsonPath('conflicts.0.code', 'S-900')
            ->assertJsonPath('conflicts.0.uuid', $otra['uuid']);
        $this->assertSame(['S-900'], StudioRule::query()->where('source', 'studio')->pluck('code')->all());
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'rule.created')->count());
    }

    /**
     * Una regla del Studio viva escrita directamente en la tabla: crearla por
     * la API tomaría el cerrojo hasta el final de la prueba.
     *
     * @param  array<string, mixed>  $conditions
     */
    private function seedLiveRule(string $code, array $conditions): StudioRule
    {
        $campos = ['description' => "Sembrada {$code}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => $conditions];

        return StudioRule::query()->forceCreate($campos + [
            'code' => $code, 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft', 'revision' => 1,
            'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * Un proceso hijo con SU conexión: BEGIN, el cerrojo de las reglas, INSERT
     * de una regla del Studio viva y, en cuanto otra sesión espera ese
     * cerrojo (`pg_locks`, consultivo y sin conceder), COMMIT. Si nadie espera
     * en 10 s, confirma igualmente y lo dice (`WAITER=0`).
     *
     * @param  array<string, mixed>  $conditions
     * @return array{uuid:string}
     */
    private function otraSesionConfirmaCuandoEspere(string $code, array $conditions): array
    {
        $campos = ['description' => "Otra sesión {$code}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => $conditions];
        $uuid = (string) Str::uuid();
        $cfg = DB::connection()->getConfig();
        $orden = [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            'user' => $cfg['username'],
            'password' => $cfg['password'],
            'key' => crc32('ultron_studio_rules:code'),
            'row' => [
                $uuid, $code, $campos['description'], json_encode($campos['layers']), json_encode($campos['refs']),
                StudioContentHasher::ruleHash($campos), (string) Str::uuid(), json_encode($conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $propios = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");
        $this->assertSame(0, $propios, 'La sesión de la prueba ya tiene un cerrojo consultivo: el hijo no podría tomarlo.');

        $script = <<<'PHP'
$o = json_decode(getenv('ULTRON_STUDIO_TEST_CHILD'), true);
$pdo = new PDO($o['dsn'], $o['user'], $o['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET lock_timeout = '15s'");
$pdo->beginTransaction();
$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$o['key']]);
$pdo->prepare("INSERT INTO ultron_studio_rules (uuid, code, source, enforcement, status, description, type, priority, layers, refs, origin, revision, content_hash, client_request_id, conditions, created_at, updated_at)
    VALUES (?, ?, 'studio', 'none', 'draft', ?, 'style', 'normal', ?, ?, NULL, 1, ?, ?, ?, now(), now())")->execute($o['row']);
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
$espera = $pdo->prepare("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND pid <> pg_backend_pid() AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
$waiter = 0;
$hasta = microtime(true) + 10;
while (microtime(true) < $hasta) {
    $espera->execute();
    if ((int) $espera->fetchColumn() > 0) {
        $waiter = 1;
        break;
    }
    usleep(10000);
}
$pdo->commit();
fwrite(STDOUT, "WAITER={$waiter}\nCOMMITTED\n");
PHP;

        $this->hijo = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->tuberias, null, ['ULTRON_STUDIO_TEST_CHILD' => json_encode($orden)] + getenv());
        if (! is_resource($this->hijo)) {
            throw new RuntimeException('No se pudo lanzar la otra sesión.');
        }
        $this->confirmoFuera = true;

        $leer = [$this->tuberias[1]];
        $nada = null;
        $linea = stream_select($leer, $nada, $nada, 20) === 1 ? fgets($this->tuberias[1]) : false;
        if ($linea !== "LOCKED\n") {
            throw new RuntimeException('La otra sesión no tomó el cerrojo: '.$linea.stream_get_contents($this->tuberias[2]));
        }

        return ['uuid' => $uuid];
    }

    /** @return array{waiter:int,salida:string} */
    private function esperarHijo(): array
    {
        $salida = stream_get_contents($this->tuberias[1]).stream_get_contents($this->tuberias[2]);
        $this->cerrarHijo();
        if (! str_contains($salida, 'COMMITTED')) {
            throw new RuntimeException('La otra sesión no confirmó: '.$salida);
        }

        return ['waiter' => preg_match('/WAITER=1/', $salida) === 1 ? 1 : 0, 'salida' => trim($salida)];
    }

    private function cerrarHijo(): void
    {
        foreach ($this->tuberias as $t) {
            if (is_resource($t)) {
                fclose($t);
            }
        }
        $this->tuberias = [];
        if (is_resource($this->hijo)) {
            proc_close($this->hijo);
        }
        $this->hijo = null;
    }
}
