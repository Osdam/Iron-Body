<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * EL LECTOR DEL MOTOR SOBRE EL STUDIO (contrato H5 §A4).
 *
 * Una clase, una tabla, una sentencia, y nunca una excepción. Lo que se fija:
 *
 *  0. EL ORÁCULO DEL CONTRATO: las secciones `signature` y `scope` de
 *     `tests/fixtures/ultron-studio/publication-cases.json` (paquete T), que el
 *     tester escribió a mano desde el contrato con las huellas y las firmas
 *     calculadas fuera de PHP. Si el lector discrepa, se corrige el lector.
 *  1. LA FIRMA (§A4.1), además con vectores propios calculados FUERA de PHP
 *     (Python, módulo `hmac`; el guion va encima de las constantes). Otra
 *     subclave, la subclave al revés, sin el prefijo del contrato o la clave
 *     del `decide_token` no cuadran.
 *  2. LA LLAVE (§A13): solo `canary` y `all` leen; cualquier otra cosa es `off`,
 *     y con `off` no hay ni una consulta.
 *  3. LA MATRIZ DE ALCANCE (§A4.3, pasos 5 y 6): llave × partes × conversación
 *     (la canaria, otra, y sin canario configurado), con lo esperado escrito a
 *     mano y no recalculado con el algoritmo.
 *  4. LA ESCALERA DE VERIFICACIÓN (§A4.3, paso 4): contrato, huella, firma y
 *     decodificación estricta (claves y tipos), en ese orden; los bytes se
 *     verifican tal cual, sin recanonicalizar.
 *  5. NUNCA LANZA (paso 7): tabla inexistente, error de consulta, sin APP_KEY;
 *     dentro de una transacción (PostgreSQL no se queda abortado) y fuera.
 *  6. `describe()` (§A4.9): sin el id del canario y sin tragarse los fallos.
 *
 * Las filas van a la tabla REAL (`2026_10_25_100001` y `_100002`, del
 * especialista de datos): son de solo inserción, así que cada caso inserta las
 * suyas dentro de la transacción de la prueba. La única tabla propia es la de
 * la prueba «fuera de una transacción», que usa una conexión SQLite aparte con
 * un esquema mínimo (las seis columnas que lee el motor) para no tocar la base
 * de la suite.
 */
class UltronPublishedConfigTest extends TestCase
{
    use RefreshDatabase;

    /** El fixture del contrato (paquete T, paso 1b del plan). */
    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/publication-cases.json';

    /*
     * Vectores de firma propios, calculados con Python 3 (hmac + hashlib), no con PHP:
     *
     *   raw = base64.b64decode(K[7:]) if K.startswith("base64:") else K.encode()
     *   k   = hmac.new(raw, b"ultron-studio-publication", sha256).digest()      # clave = APP_KEY
     *   sig = hmac.new(k, b"ultron-engine-config/1\n" + J, sha256).hexdigest()
     */
    private const K1 = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';   // bytes 0x00…0x1f

    private const K2 = 'clave-de-prueba-sin-prefijo';

    private const J1 = '{"canary":{"approvedBy":12,"catalogVersion":"2026-10-19.1","contentHash":"abababababababababababababababababababababababababababababababab","declared":{"governance.canaryOnly":true,"governance.enabled":true,"intent.driver":"entorno","rules.paymentLinksEnabled":false},"publishedBy":7,"version":"6f1c2a9e-1d2b-4c3d-8e4f-5a6b7c8d9e0f"},"contract":"ultron-engine-config/1","entry":"0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b","issuedAt":"2026-11-03T15:04:05Z","rest":null,"seq":1}';

    private const J2 = '{"canary":null,"contract":"ultron-engine-config/1","entry":"0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6c","issuedAt":"2026-11-03T15:05:00Z","rest":null,"seq":2}';

    private const J3 = '{"nota":"ñandú"}';

    private const SIG_K1_J1 = '1ef3a49eb99dba5d0ceb376937b3c449156ae040f9bcf29eeeea3e604e3e7b18';

    private const SIG_K1_J2 = 'ee5c303e12ed5ff755d9642e72326c9953ec65421bae94e73c1614339fd409bc';

    private const SIG_K2_J1 = 'f2820b29a40bcd32aa84b9a24528a0349cf0600527c869109c46ab66af221178';

    private const SIG_K1_J3 = '1747600ef9e86d2130f4174d2f1633edbb88eaa249401dc5e43c800c080db58f';

    private const SIG_K1_VACIO = 'd1cfd2d0214ff4a2bff1174f5350d7fbd8bf64210ecb45ab564a2373c90b9797';

    /**
     * Lo que la firma NO es (mismos J1 y K1): la clave del decide_token, la
     * subclave al revés (etiqueta como clave), sin el prefijo del contrato y la
     * subclave en hexadecimal en vez de sus 32 bytes.
     */
    private const NO_DECIDE_TOKEN = 'e4993e04c87a860f57a0e6f938dc1775d5643ec9a908bc2689941293a4055465';

    private const NO_SUBCLAVE_AL_REVES = '2c40c4cb11310239b51ed278de151299b72463483a6816ee45e57dd5d894187d';

    private const NO_SIN_PREFIJO = 'edc753bb89614006229af8a78edae626b302430f0b40b3a83cad672e6ae4a4aa';

    private const NO_SUBCLAVE_HEX = 'e4237ed474e691f1dd668d2369ab1f5ecd6ef8221012daf323fe1db796c89951';

    /** Las dos versiones de la matriz: A rige «el resto», B está en canario. */
    private const VERSION_A = 'a0000000-0000-4000-8000-00000000000a';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    /** Largo a propósito: así el «no sale el id» no puede chocar por azar con un uuid. */
    private const CANARIO = 987654321;

    private const OTRA = 77;

    private const TABLA = 'ultron_studio_publications';

    private int $ultimoSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', self::K1);
        config()->set('marketing.ultron.canary_conversation_id', self::CANARIO);
        config()->set('marketing.ultron.studio_config', Lector::ALL);
    }

    // ── 0 · El fixture del contrato ───────────────────────────────────────────

    /** @param  array<string,mixed>  $caso */
    #[DataProvider('firmasDelContrato')]
    public function test_la_firma_cumple_el_fixture_del_contrato(array $caso): void
    {
        config()->set('app.key', $caso['appKey']);

        if ($caso['kind'] === 'sign') {
            $this->assertSame($caso['expected']['signature'], Lector::signatureFor($caso['effectiveJson']), $caso['name']);

            return;
        }
        $this->assertSame($caso['expected']['valid'], hash_equals(Lector::signatureFor($caso['effectiveJson']), $caso['signature']), $caso['name']);
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function firmasDelContrato(): array
    {
        return self::casosDelContrato('signature');
    }

    /**
     * Cada caso de `scope`: la cabeza que describe (o ninguna), la llave, el
     * canario y la conversación; el sello esperado y las SELECT sobre la tabla.
     * Las dos filas con `engineContract` desconocido no entran en PostgreSQL por
     * su CHECK de dominio: se quita dentro de la transacción de la prueba.
     *
     * @param  array<string,mixed>  $caso
     */
    #[DataProvider('alcanceDelContrato')]
    public function test_el_lector_cumple_el_fixture_del_contrato(array $caso): void
    {
        config()->set('app.key', $caso['appKey']);
        config()->set('marketing.ultron.studio_config', $caso['env']);
        config()->set('marketing.ultron.canary_conversation_id', $caso['canaryConversationId']);

        if (($h = $caso['head']) !== null) {
            if ($h['engineContract'] !== Lector::CONTRACT) {
                $this->sinElCheckDelContrato();
            }
            $this->inserta($h['effectiveJson'], [
                'uuid' => $h['uuid'], 'seq' => $h['seq'], 'engine_contract' => $h['engineContract'],
                'effective_hash' => $h['effectiveHash'], 'signature' => $h['signature'],
            ]);
        }
        match ($caso['failure']) {
            null => null,
            'table_missing' => Schema::drop(self::TABLA),
            'read_failed' => Schema::table(self::TABLA, fn (Blueprint $t) => $t->renameColumn('effective_json', 'effective_text')),
        };

        $consultas = $this->escuchaConsultas();
        $sello = (new Lector)->forConversation($caso['conversationId']);

        // Igualdad ESTRICTA con el fixture: valores, tipos y el orden de A4.5.
        $this->assertSame($caso['expected']['stamp'], $sello, $caso['name']);
        if ($caso['expected']['queries'] !== null) {
            $this->assertCount($caso['expected']['queries'], $consultas(), $caso['name'].': sentencias sobre la cadena');
        }
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function alcanceDelContrato(): array
    {
        return self::casosDelContrato('scope');
    }

    // ── 1 · La firma ──────────────────────────────────────────────────────────

    public function test_la_firma_es_la_del_contrato_con_vectores_calculados_fuera_de_php(): void
    {
        $this->assertSame(self::SIG_K1_J1, Lector::signatureFor(self::J1));
        $this->assertSame(self::SIG_K1_J2, Lector::signatureFor(self::J2));
        $this->assertSame(self::SIG_K1_J3, Lector::signatureFor(self::J3), 'se firman los BYTES (UTF-8), no los caracteres');
        $this->assertSame(self::SIG_K1_VACIO, Lector::signatureFor(''));

        // La APP_KEY sin prefijo se usa tal cual, como hace Laravel.
        config()->set('app.key', self::K2);
        $this->assertSame(self::SIG_K2_J1, Lector::signatureFor(self::J1));
    }

    public function test_la_firma_no_es_la_del_decide_token_ni_otra_forma_parecida(): void
    {
        $firma = Lector::signatureFor(self::J1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $firma, '64 hexadecimales en minúscula');
        $this->assertNotSame(self::NO_DECIDE_TOKEN, $firma, 'la subclave está separada de la clave del decide_token');
        $this->assertNotSame(self::NO_SUBCLAVE_AL_REVES, $firma, 'la subclave es HMAC(clave = APP_KEY, mensaje = etiqueta)');
        $this->assertNotSame(self::NO_SIN_PREFIJO, $firma, 'el contrato va dentro del texto firmado');
        $this->assertNotSame(self::NO_SUBCLAVE_HEX, $firma, 'la subclave son sus 32 bytes crudos');
        $this->assertNotSame($firma, Lector::signatureFor(substr_replace(self::J1, '2', -2, 1)), 'un byte cambia la firma');
    }

    #[DataProvider('clavesInservibles')]
    public function test_sin_app_key_no_se_firma_con_una_clave_vacia(mixed $clave): void
    {
        config()->set('app.key', $clave);

        $this->expectException(RuntimeException::class);
        Lector::signatureFor(self::J1);
    }

    /** @return array<string, array{mixed}> */
    public static function clavesInservibles(): array
    {
        return ['vacía' => [''], 'nula' => [null], 'base64 vacía' => ['base64:']];
    }

    // ── 2 · La llave ──────────────────────────────────────────────────────────

    #[DataProvider('llaves')]
    public function test_solo_canary_o_all_leen_y_lo_demas_es_off_sin_ninguna_consulta(mixed $valor, ?string $env): void
    {
        $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        config()->set('marketing.ultron.studio_config', $valor);

        $consultas = $this->escuchaConsultas();
        $sello = (new Lector)->forConversation(self::CANARIO);

        if ($env === null) {
            $this->assertNull($sello, 'con la llave en off no hay sello');
            $this->assertSame([], $consultas(), 'con la llave en off no hay ni una consulta');

            return;
        }
        $this->assertSame($env, $sello['env']);
        $this->assertSame(Lector::APPLIED, $sello['state']);
        $this->assertCount(1, $consultas(), 'una sola sentencia por lectura');
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function llaves(): array
    {
        return [
            'off' => ['off', null],
            'canary' => ['canary', 'canary'],
            'all' => ['all', 'all'],
            'mayúsculas y espacios' => [' ALL ', 'all'],
            'Canary' => ['Canary', 'canary'],
            'vacía' => ['', null],
            'on' => ['on', null],
            'true (cadena)' => ['true', null],
            'true (booleano)' => [true, null],
            'uno' => [1, null],
            'nula' => [null, null],
            'una lista' => [['all'], null],
            'dos valores' => ['canary,all', null],
        ];
    }

    /**
     * La interpretación de la variable de entorno vive en config/marketing.php
     * (contrato §A13): se evalúa el fichero con cada valor en el entorno.
     */
    #[DataProvider('variables')]
    public function test_la_variable_de_entorno_se_interpreta_con_off_por_defecto(?string $valor, string $esperado): void
    {
        $leido = $this->conLaVariable($valor, fn () => (require base_path('config/marketing.php'))['ultron']['studio_config']);

        $this->assertSame($esperado, $leido);
    }

    /** @return array<string, array{?string, string}> */
    public static function variables(): array
    {
        return [
            'sin definir' => [null, 'off'],
            'off' => ['off', 'off'],
            'canary' => ['canary', 'canary'],
            'all' => ['all', 'all'],
            'mayúsculas y espacios' => ['  All ', 'all'],
            'vacía' => ['', 'off'],
            'on' => ['on', 'off'],
            'true' => ['true', 'off'],
            'null' => ['null', 'off'],
            'dos valores' => ['canary,all', 'off'],
            'basura' => ['todos', 'off'],
        ];
    }

    // ── 3 · La matriz de alcance ──────────────────────────────────────────────

    /**
     * @param  string  $forma  qué partes trae la cabeza: B en canario y A en el resto
     * @param  array{0:string,1:?string,2:?string}|null  $esperado  [estado, parte, versión]; null = sin sello
     */
    #[DataProvider('alcance')]
    public function test_la_parte_que_rige_cada_conversacion(string $env, string $forma, string $conversacion, ?array $esperado): void
    {
        $partes = [
            'B·A' => [$this->parte(self::VERSION_B), $this->parte(self::VERSION_A)],
            'B·—' => [$this->parte(self::VERSION_B), null],
            '—·A' => [null, $this->parte(self::VERSION_A)],
            '—·—' => [null, null],
        ][$forma];
        $fila = $this->publica(...$partes);

        config()->set('marketing.ultron.studio_config', $env);
        if ($conversacion === 'sin canario') {
            config()->set('marketing.ultron.canary_conversation_id', null);
        }
        $id = $conversacion === 'la canaria' ? self::CANARIO : self::OTRA;

        $sello = (new Lector)->forConversation($id);

        if ($esperado === null) {
            $this->assertNull($sello);

            return;
        }
        [$estado, $parte, $version] = $esperado;
        $this->assertSame([
            'v' => 1,
            'env' => $env,
            'state' => $estado,
            'entry' => $fila['uuid'],
            'seq' => $fila['seq'],
            'part' => $parte,
            'version' => $version,
            'contentHash' => $version === null ? null : hash('sha256', $version),
            'reason' => null,
        ], $sello);
    }

    /** @return array<string, array{string, string, string, ?array}> */
    public static function alcance(): array
    {
        $A = self::VERSION_A;
        $B = self::VERSION_B;
        $aplicada = fn (string $parte, string $version): array => [Lector::APPLIED, $parte, $version];
        $legacy = [Lector::LEGACY, null, null];
        $fuera = [Lector::OUT_OF_SCOPE, null, null];

        // [llave, forma, conversación, esperado], escrito a mano desde §A4.3.
        $tabla = [
            ['canary', 'B·A', 'la canaria', $aplicada('canary', $B)],
            ['canary', 'B·A', 'otra', $fuera],
            ['canary', 'B·A', 'sin canario', $fuera],
            ['canary', 'B·—', 'la canaria', $aplicada('canary', $B)],
            ['canary', 'B·—', 'otra', $fuera],
            ['canary', 'B·—', 'sin canario', $fuera],
            ['canary', '—·A', 'la canaria', $aplicada('rest', $A)],
            ['canary', '—·A', 'otra', $fuera],
            ['canary', '—·A', 'sin canario', $fuera],
            ['canary', '—·—', 'la canaria', $legacy],
            ['canary', '—·—', 'otra', $fuera],
            ['canary', '—·—', 'sin canario', $fuera],
            ['all', 'B·A', 'la canaria', $aplicada('canary', $B)],
            ['all', 'B·A', 'otra', $aplicada('rest', $A)],
            ['all', 'B·A', 'sin canario', $aplicada('rest', $A)],
            ['all', 'B·—', 'la canaria', $aplicada('canary', $B)],
            ['all', 'B·—', 'otra', $legacy],
            ['all', 'B·—', 'sin canario', $legacy],
            ['all', '—·A', 'la canaria', $aplicada('rest', $A)],
            ['all', '—·A', 'otra', $aplicada('rest', $A)],
            ['all', '—·A', 'sin canario', $aplicada('rest', $A)],
            ['all', '—·—', 'la canaria', $legacy],
            ['all', '—·—', 'otra', $legacy],
            ['all', '—·—', 'sin canario', $legacy],
        ];
        foreach (['B·A', 'B·—', '—·A', '—·—'] as $forma) {
            foreach (['la canaria', 'otra', 'sin canario'] as $conversacion) {
                $tabla[] = ['off', $forma, $conversacion, null];
            }
        }

        $casos = [];
        foreach ($tabla as [$env, $forma, $conversacion, $esperado]) {
            $casos["{$env} · {$forma} · {$conversacion}"] = [$env, $forma, $conversacion, $esperado];
        }

        return $casos;
    }

    public function test_sin_ninguna_entrada_el_sello_es_legacy_y_no_nombra_ninguna(): void
    {
        $this->assertSame(
            ['v' => 1, 'env' => 'all', 'state' => Lector::LEGACY, 'entry' => null, 'seq' => null, 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => null],
            (new Lector)->forConversation(self::CANARIO),
        );
    }

    public function test_la_cabeza_es_la_de_mayor_seq_aunque_no_sea_la_ultima_insertada(): void
    {
        $this->publica($this->parte(self::VERSION_A), null, ['seq' => 1]);
        $cabeza = $this->publica($this->parte(self::VERSION_B), null, ['seq' => 3]);
        $this->publica(null, null, ['seq' => 2]);

        $sello = (new Lector)->forConversation(self::CANARIO);

        $this->assertSame([3, $cabeza['uuid'], self::VERSION_B], [$sello['seq'], $sello['entry'], $sello['version']]);
    }

    /** Sin caché (§A4.8): revertir es insertar, y la lectura siguiente ya lo ve. */
    public function test_una_entrada_nueva_se_ve_en_la_lectura_siguiente(): void
    {
        $lector = new Lector;
        $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        $this->assertSame(self::VERSION_B, $lector->forConversation(self::CANARIO)['version']);

        $this->publica(null, $this->parte(self::VERSION_A));   // revertir el canario
        $this->assertSame([Lector::APPLIED, 'rest', self::VERSION_A], array_values(array_intersect_key($lector->forConversation(self::CANARIO), array_flip(['state', 'part', 'version']))));

        $this->publica(null, null);                             // Legacy
        $this->assertSame(Lector::LEGACY, $lector->forConversation(self::CANARIO)['state']);
    }

    // ── 4 · La escalera de verificación ───────────────────────────────────────

    public function test_un_contrato_desconocido_no_se_lee(): void
    {
        $this->sinElCheckDelContrato();
        $fila = $this->publica($this->parte(self::VERSION_B), null, ['engine_contract' => 'ultron-engine-config/2']);

        $this->assertFallback(Lector::CONTRACT_UNKNOWN, (new Lector)->forConversation(self::CANARIO), $fila);
    }

    public function test_una_huella_que_no_cuadra_con_los_bytes(): void
    {
        $fila = $this->publica($this->parte(self::VERSION_B), null, ['effective_hash' => hash('sha256', 'otros bytes')]);

        $this->assertFallback(Lector::HASH_MISMATCH, (new Lector)->forConversation(self::CANARIO), $fila);
    }

    #[DataProvider('firmasMalas')]
    public function test_una_firma_que_no_vale(string $como): void
    {
        // La firma mala se calcula sobre los MISMOS bytes que se guardan: si no,
        // el caso pasaría por no cuadrar con otra fila y no por lo que dice.
        $uuid = (string) Str::uuid();
        $seq = ++$this->ultimoSeq;
        $json = self::canonico($this->documento($uuid, $seq, $this->parte(self::VERSION_B), null));
        $buena = Lector::signatureFor($json);

        $mala = match ($como) {
            'otra clave' => (function () use ($json): string {
                config()->set('app.key', self::K2);
                $firma = Lector::signatureFor($json);
                config()->set('app.key', self::K1);

                return $firma;
            })(),
            'mayúsculas' => strtoupper($buena),
            'un carácter' => substr_replace($buena, $buena[0] === 'a' ? 'b' : 'a', 0, 1),
            'vacía' => '',
            'la del decide_token' => hash_hmac('sha256', Lector::CONTRACT."\n".$json, self::K1),
        };
        $this->assertNotSame($buena, $mala);

        $fila = $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq, 'signature' => $mala]);

        $this->assertFallback(Lector::SIGNATURE_INVALID, (new Lector)->forConversation(self::CANARIO), $fila);
    }

    /** @return array<string, array{string}> */
    public static function firmasMalas(): array
    {
        return array_combine($c = ['otra clave', 'mayúsculas', 'un carácter', 'vacía', 'la del decide_token'], array_map(fn ($x) => [$x], $c));
    }

    /** Las comprobaciones van en el orden del contrato: el primer fallo da el motivo. */
    public function test_el_orden_de_las_comprobaciones(): void
    {
        $this->sinElCheckDelContrato();

        $fila = $this->publica($this->parte(self::VERSION_B), null, ['engine_contract' => 'otro', 'effective_hash' => str_repeat('0', 64)]);
        $this->assertFallback(Lector::CONTRACT_UNKNOWN, (new Lector)->forConversation(self::CANARIO), $fila);

        $fila = $this->publica($this->parte(self::VERSION_B), null, ['effective_hash' => str_repeat('0', 64), 'signature' => str_repeat('0', 64)]);
        $this->assertFallback(Lector::HASH_MISMATCH, (new Lector)->forConversation(self::CANARIO), $fila);

        $roto = '{"no":"es una entrada"}';
        $fila = $this->inserta($roto, ['signature' => str_repeat('0', 64)]);
        $this->assertFallback(Lector::SIGNATURE_INVALID, (new Lector)->forConversation(self::CANARIO), $fila);

        $fila = $this->inserta($roto);
        $this->assertFallback(Lector::MALFORMED, (new Lector)->forConversation(self::CANARIO), $fila);
    }

    /**
     * FIRMADAS Y MAL FORMADAS. Cada caso lleva su huella y su firma correctas
     * (las calcula el ayudante sobre los bytes que se guardan), así que llega a
     * la decodificación: una clave de más o de menos, o un tipo distinto, es
     * `malformed`, y el sello dice qué fila está mal.
     *
     * @param  callable(array<string,mixed>, string, int): (array<string,mixed>|string)  $estropea
     */
    #[DataProvider('malformadas')]
    public function test_decodificacion_estricta(callable $estropea): void
    {
        $uuid = (string) Str::uuid();
        $seq = ++$this->ultimoSeq;
        $doc = $this->documento($uuid, $seq, $this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        $estropeado = $estropea($doc, $uuid, $seq);
        $json = is_string($estropeado) ? $estropeado : self::canonico($estropeado);

        $fila = $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq]);

        $this->assertFallback(Lector::MALFORMED, (new Lector)->forConversation(self::CANARIO), $fila);
    }

    /** @return array<string, array{callable}> */
    public static function malformadas(): array
    {
        $casos = [
            'no es JSON' => fn () => '{"canary":',
            'una lista' => fn () => '[]',
            'una cadena' => fn () => '"entrada"',
            'null' => fn () => 'null',
            'un número' => fn () => '1',
            'clave de más' => fn (array $d) => $d + ['extra' => true],
            'falta una clave' => fn (array $d) => array_diff_key($d, ['issuedAt' => 1]),
            'otro contrato dentro' => fn (array $d) => array_replace($d, ['contract' => 'ultron-engine-config/2']),
            'entry que no es la de la fila' => fn (array $d) => array_replace($d, ['entry' => 'no-es-un-uuid']),
            'entry de otra fila' => fn (array $d) => array_replace($d, ['entry' => (string) Str::uuid()]),
            'entry como número' => fn (array $d) => array_replace($d, ['entry' => 7]),
            'seq de otra fila' => fn (array $d) => array_replace($d, ['seq' => $d['seq'] + 1]),
            'seq como cadena' => fn (array $d) => array_replace($d, ['seq' => (string) $d['seq']]),
            'seq como booleano' => fn (array $d) => array_replace($d, ['seq' => true]),
            'seq decimal' => fn (array $d, string $u, int $s) => str_replace('"seq":'.$s.'}', '"seq":'.$s.'.0}', self::canonico($d)),
            'seq enorme' => fn (array $d, string $u, int $s) => str_replace('"seq":'.$s.'}', '"seq":99999999999999999999999}', self::canonico($d)),
            'issuedAt como número' => fn (array $d) => array_replace($d, ['issuedAt' => 20261103]),
            'canary como lista vacía' => fn (array $d) => array_replace($d, ['canary' => []]),
            'canary como lista con la parte' => fn (array $d) => array_replace($d, ['canary' => [$d['canary']]]),
            'canary como cadena' => fn (array $d) => array_replace($d, ['canary' => 'B']),
            'rest false' => fn (array $d) => array_replace($d, ['rest' => false]),
            'rest como objeto vacío' => fn (array $d) => array_replace($d, ['rest' => new \stdClass]),
            'declared como lista' => fn (array $d) => array_replace($d, ['rest' => array_replace($d['rest'], ['declared' => [true, true, 'entorno', false]])]),
            'parte con clave de más' => fn (array $d) => array_replace($d, ['canary' => $d['canary'] + ['label' => 'Nombre']]),
            'parte sin version' => fn (array $d) => array_replace($d, ['canary' => array_diff_key($d['canary'], ['version' => 1])]),
            'approvedBy como cadena' => fn (array $d) => array_replace_recursive($d, ['canary' => ['approvedBy' => '12']]),
            'approvedBy decimal' => fn (array $d) => array_replace_recursive($d, ['canary' => ['approvedBy' => 12.5]]),
            'publishedBy nulo' => fn (array $d) => array_replace_recursive($d, ['rest' => ['publishedBy' => null]]),
            'contentHash como número' => fn (array $d) => array_replace_recursive($d, ['canary' => ['contentHash' => 1]]),
            'version nula' => fn (array $d) => array_replace_recursive($d, ['rest' => ['version' => null]]),
            'catalogVersion como lista' => fn (array $d) => array_replace($d, ['canary' => array_replace($d['canary'], ['catalogVersion' => ['2026-10-19.1']])]),
            'declared con clave de más' => fn (array $d) => array_replace_recursive($d, ['canary' => ['declared' => ['governance.paused' => false]]]),
            'declared sin una clave' => fn (array $d) => array_replace($d, ['canary' => array_replace($d['canary'], ['declared' => array_diff_key($d['canary']['declared'], ['intent.driver' => 1])])]),
            'declared con un booleano en cadena' => fn (array $d) => array_replace_recursive($d, ['canary' => ['declared' => ['governance.enabled' => 'true']]]),
            'declared con un booleano en número' => fn (array $d) => array_replace_recursive($d, ['rest' => ['declared' => ['rules.paymentLinksEnabled' => 0]]]),
            'driver no es cadena' => fn (array $d) => array_replace_recursive($d, ['canary' => ['declared' => ['intent.driver' => true]]]),
            'demasiado anidado' => fn () => str_repeat('[', 20).str_repeat(']', 20),
        ];

        return array_map(fn (callable $c) => [$c], $casos);
    }

    /**
     * LOS VALORES NO SE VALIDAN (fixture del contrato, R-5): solo las claves y
     * los tipos. El formato del uuid de la versión, de la huella de contenido o
     * de `issuedAt`, o que un id sea positivo, lo garantiza quien firma: con la
     * firma buena, se aplica tal cual.
     *
     * @param  callable(array<string,mixed>): array<string,mixed>  $cambia
     */
    #[DataProvider('valoresQueNoSeValidan')]
    public function test_los_valores_no_se_validan_solo_las_claves_y_los_tipos(callable $cambia): void
    {
        $uuid = (string) Str::uuid();
        $seq = ++$this->ultimoSeq;
        $json = self::canonico($cambia($this->documento($uuid, $seq, $this->parte(self::VERSION_B), null)));
        $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq]);

        $this->assertSame(Lector::APPLIED, (new Lector)->forConversation(self::CANARIO)['state']);
    }

    /** @return array<string, array{callable}> */
    public static function valoresQueNoSeValidan(): array
    {
        $casos = [
            'issuedAt con zona' => fn (array $d) => array_replace($d, ['issuedAt' => '2026-11-03T15:04:05+00:00']),
            'issuedAt cualquiera' => fn (array $d) => array_replace($d, ['issuedAt' => 'ayer']),
            'approvedBy cero' => fn (array $d) => array_replace_recursive($d, ['canary' => ['approvedBy' => 0]]),
            'contentHash corto' => fn (array $d) => array_replace_recursive($d, ['canary' => ['contentHash' => 'abc']]),
            'version que no es uuid' => fn (array $d) => array_replace_recursive($d, ['canary' => ['version' => 'v1']]),
            'catalogVersion vacía' => fn (array $d) => array_replace_recursive($d, ['canary' => ['catalogVersion' => '']]),
            'driver de otro dominio' => fn (array $d) => array_replace_recursive($d, ['canary' => ['declared' => ['intent.driver' => 'otro']]]),
        ];

        return array_map(fn (callable $c) => [$c], $casos);
    }

    /**
     * EL MOTOR NO RECANONICALIZA (§A4.2). Unos bytes que no son la forma
     * canónica pero llevan su huella y su firma se aceptan; y una firma de la
     * forma canónica no vale para unos bytes guardados en otra forma. Un lector
     * que volviera a codificar antes de firmar fallaría los dos sentidos.
     */
    public function test_se_verifican_los_bytes_guardados_sin_recanonicalizar(): void
    {
        $uuid = (string) Str::uuid();
        $doc = $this->documento($uuid, ++$this->ultimoSeq, $this->parte(self::VERSION_B), null);
        // Otra forma del MISMO documento: con sangría, otro orden y escapes.
        $otraForma = json_encode(array_reverse($doc, true), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->assertNotSame(self::canonico($doc), $otraForma);

        $this->inserta($otraForma, ['uuid' => $uuid, 'seq' => $this->ultimoSeq]);
        $sello = (new Lector)->forConversation(self::CANARIO);
        $this->assertSame([Lector::APPLIED, self::VERSION_B], [$sello['state'], $sello['version']], 'los bytes firmados se aceptan tal cual');

        $uuid2 = (string) Str::uuid();
        $doc2 = $this->documento($uuid2, ++$this->ultimoSeq, $this->parte(self::VERSION_B), null);
        $guardado = json_encode($doc2, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $fila = $this->inserta($guardado, ['uuid' => $uuid2, 'seq' => $this->ultimoSeq, 'signature' => Lector::signatureFor(self::canonico($doc2))]);
        $this->assertFallback(Lector::SIGNATURE_INVALID, (new Lector)->forConversation(self::CANARIO), $fila, 'la firma de otra forma no vale para estos bytes');
    }

    /** Una fila enorme escrita a mano no se trae entera: la parte truncada no cuadra con su huella. */
    public function test_una_declaracion_enorme_no_se_trae_entera(): void
    {
        $uuid = (string) Str::uuid();
        $doc = $this->documento($uuid, ++$this->ultimoSeq, $this->parte(self::VERSION_B), null);
        $enorme = substr(self::canonico($doc), 0, -1).',"relleno":"'.str_repeat('x', Lector::MAX_EFFECTIVE_JSON * 2).'"}';

        $consultas = $this->escuchaConsultas();
        $fila = $this->inserta($enorme, ['uuid' => $uuid, 'seq' => $this->ultimoSeq]);
        $consultas(true);   // lo que cuenta es la lectura, no la inserción

        $this->assertFallback(Lector::HASH_MISMATCH, (new Lector)->forConversation(self::CANARIO), $fila);
        $this->assertStringContainsString('substr(effective_json, 1, '.(Lector::MAX_EFFECTIVE_JSON + 1).')', $consultas()[0]);
    }

    // ── 5 · Nunca lanza ───────────────────────────────────────────────────────

    public function test_con_la_tabla_inexistente_no_lanza_y_la_conexion_sigue_viva(): void
    {
        $log = $this->espiaElLog();
        Schema::drop(self::TABLA);

        $this->assertFallback(Lector::TABLE_MISSING, (new Lector)->forConversation(self::CANARIO));

        // Dentro de una transacción (la de la prueba), PostgreSQL no se queda
        // abortado: la lectura fue en un punto de guardado.
        $this->assertSame(1, (int) DB::selectOne('select 1 as uno')->uno);
        $this->assertLogDelFallback($log, Lector::TABLE_MISSING, 'QueryException');
    }

    /**
     * Se renombra `effective_json` porque el lector la nombra SIN comillas
     * (dentro de `substr`): en SQLite un identificador entre comillas dobles que
     * no existe se lee como una cadena literal (`select "signature"` devuelve
     * 'signature') y no daría error. Aun así no se aplicaría nada —esa «firma»
     * no cuadra y sale `signature_invalid`—, pero aquí se quiere el error.
     */
    public function test_cualquier_otro_error_de_consulta_es_read_failed(): void
    {
        $this->publica($this->parte(self::VERSION_B), null);
        $log = $this->espiaElLog();
        Schema::table(self::TABLA, fn (Blueprint $t) => $t->renameColumn('effective_json', 'effective_text'));

        $this->assertFallback(Lector::READ_FAILED, (new Lector)->forConversation(self::CANARIO));
        $this->assertSame(1, (int) DB::selectOne('select 1 as uno')->uno);
        $this->assertLogDelFallback($log, Lector::READ_FAILED, 'QueryException');
    }

    /** Sin APP_KEY no se puede verificar: `read_failed`, sin nombrar la fila (fixture, R-2). */
    public function test_sin_app_key_la_lectura_no_lanza(): void
    {
        $this->publica($this->parte(self::VERSION_B), null);
        $log = $this->espiaElLog();
        config()->set('app.key', '');

        $this->assertFallback(Lector::READ_FAILED, (new Lector)->forConversation(self::CANARIO));
        $this->assertLogDelFallback($log, Lector::READ_FAILED, 'RuntimeException');
    }

    /**
     * EL CAMINO DE PRODUCCIÓN: sin transacción abierta (el commit no abre
     * ninguna antes de persistir). La suite envuelve cada prueba en una, así
     * que esta usa una conexión SQLite aparte, en memoria, con un esquema
     * mínimo propio: las seis columnas que lee el motor.
     */
    public function test_fuera_de_una_transaccion_tampoco_lanza_y_lee_igual(): void
    {
        config()->set('database.connections.motor_sin_transaccion', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $anterior = DB::getDefaultConnection();
        DB::setDefaultConnection('motor_sin_transaccion');

        try {
            $this->assertSame(0, DB::transactionLevel(), 'esta prueba tiene que correr sin transacción');

            $this->assertFallback(Lector::TABLE_MISSING, (new Lector)->forConversation(self::CANARIO));

            Schema::create(self::TABLA, function (Blueprint $t): void {
                $t->id();
                $t->string('uuid');
                $t->unsignedInteger('seq')->unique();
                $t->string('engine_contract', 32);
                $t->text('effective_json');
                $t->char('effective_hash', 64);
                $t->char('signature', 64);
            });
            $this->assertSame(Lector::LEGACY, (new Lector)->forConversation(self::CANARIO)['state']);

            $uuid = (string) Str::uuid();
            $json = self::canonico($this->documento($uuid, 1, $this->parte(self::VERSION_B), null));
            DB::table(self::TABLA)->insert(['uuid' => $uuid, 'seq' => 1, 'engine_contract' => Lector::CONTRACT, 'effective_json' => $json, 'effective_hash' => hash('sha256', $json), 'signature' => Lector::signatureFor($json)]);

            $consultas = $this->escuchaConsultas();
            $sello = (new Lector)->forConversation(self::CANARIO);
            $this->assertSame([Lector::APPLIED, 'canary', self::VERSION_B, $uuid], [$sello['state'], $sello['part'], $sello['version'], $sello['entry']]);
            $this->assertCount(1, $consultas());
            $this->assertSame(0, DB::transactionLevel(), 'la lectura no deja ninguna transacción abierta');
        } finally {
            DB::setDefaultConnection($anterior);
            DB::purge('motor_sin_transaccion');
        }
    }

    // ── 6 · describe() ────────────────────────────────────────────────────────

    public function test_describe_sin_cabeza(): void
    {
        config()->set('marketing.ultron.studio_config', 'off');

        $this->assertSame(
            ['env' => 'off', 'head' => null, 'parts' => ['canary' => false, 'rest' => false], 'canaryConfigured' => true],
            (new Lector)->describe(),
        );
    }

    public function test_describe_con_la_cabeza_verificada_y_sin_el_id_del_canario(): void
    {
        $this->publica($this->parte(self::VERSION_B), null);
        $cabeza = $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        config()->set('marketing.ultron.studio_config', 'canary');

        $descripcion = (new Lector)->describe();

        $this->assertSame([
            'env' => 'canary',
            'head' => ['entry' => $cabeza['uuid'], 'seq' => $cabeza['seq'], 'contract' => Lector::CONTRACT, 'hashOk' => true, 'signatureOk' => true, 'parseOk' => true],
            'parts' => ['canary' => true, 'rest' => true],
            'canaryConfigured' => true,
        ], $descripcion);
        $this->assertStringNotContainsString((string) self::CANARIO, json_encode($descripcion), 'el id del canario no sale');

        config()->set('marketing.ultron.canary_conversation_id', null);
        $this->assertFalse((new Lector)->describe()['canaryConfigured']);
    }

    /** Cada comprobación por su lado: el Studio distingue una huella alterada de una firma que ya no vale. */
    public function test_describe_distingue_la_huella_de_la_firma(): void
    {
        $this->publica($this->parte(self::VERSION_B), null, ['effective_hash' => str_repeat('0', 64)]);
        $d = (new Lector)->describe();
        $this->assertSame([false, true, true], [$d['head']['hashOk'], $d['head']['signatureOk'], $d['head']['parseOk']]);
        $this->assertSame(['canary' => false, 'rest' => false], $d['parts'], 'una cabeza sin verificar no aplica nada');

        config()->set('app.key', self::K2);   // la APP_KEY rotó
        $d = (new Lector)->describe();
        $this->assertSame([false, false, true], [$d['head']['hashOk'], $d['head']['signatureOk'], $d['head']['parseOk']]);
    }

    /** A diferencia del sello, un fallo de lectura se propaga: el Studio lo enseña como no disponible. */
    public function test_describe_no_se_traga_un_fallo_de_lectura(): void
    {
        Schema::drop(self::TABLA);

        $this->expectException(QueryException::class);
        (new Lector)->describe();
    }

    // ── Ayudantes ─────────────────────────────────────────────────────────────

    /**
     * Una sección del fixture del contrato, un caso por nombre.
     *
     * @return array<string, array{array<string,mixed>}>
     */
    private static function casosDelContrato(string $seccion): array
    {
        $texto = is_file(self::FIXTURE) ? file_get_contents(self::FIXTURE) : false;
        if ($texto === false) {
            throw new RuntimeException('Falta el fixture del contrato (paquete T): '.self::FIXTURE);
        }
        $fixture = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
        if (($fixture['contract'] ?? null) !== Lector::CONTRACT) {
            throw new RuntimeException('El fixture es de otro contrato: '.var_export($fixture['contract'] ?? null, true));
        }

        $casos = [];
        foreach ($fixture[$seccion] as $caso) {
            $casos[$caso['name']] = [$caso];
        }

        return $casos;
    }

    /** @return array<string,mixed> */
    private function parte(string $version): array
    {
        return [
            'approvedBy' => 12,
            'catalogVersion' => '2026-10-19.1',
            'contentHash' => hash('sha256', $version),
            'declared' => [
                'governance.canaryOnly' => true,
                'governance.enabled' => true,
                'intent.driver' => 'entorno',
                'rules.paymentLinksEnabled' => false,
            ],
            'publishedBy' => 7,
            'version' => $version,
        ];
    }

    /** @return array<string,mixed> */
    private function documento(string $uuid, int $seq, ?array $canary, ?array $rest): array
    {
        return ['canary' => $canary, 'contract' => Lector::CONTRACT, 'entry' => $uuid, 'issuedAt' => '2026-11-03T15:04:05Z', 'rest' => $rest, 'seq' => $seq];
    }

    /** El JSON como lo emite el Studio: claves ordenadas, sin escapar barras ni unicode. */
    private static function canonico(array $valor): string
    {
        $ordena = function (mixed $v) use (&$ordena): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($ordena, $v);
        };

        return json_encode($ordena($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Una entrada correcta en la cadena real: huella y firma sobre los bytes.
     *
     * @param  array<string,mixed>  $sobre  columnas que se quieren distintas
     * @return array<string,mixed>
     */
    private function publica(?array $canary, ?array $rest, array $sobre = []): array
    {
        $uuid = $sobre['uuid'] ?? (string) Str::uuid();
        $seq = $sobre['seq'] ?? ++$this->ultimoSeq;
        $this->ultimoSeq = max($this->ultimoSeq, $seq);

        return $this->inserta(self::canonico($this->documento($uuid, $seq, $canary, $rest)), ['uuid' => $uuid, 'seq' => $seq] + $sobre);
    }

    /**
     * @param  array<string,mixed>  $sobre
     * @return array<string,mixed>
     */
    private function inserta(string $json, array $sobre = []): array
    {
        $seq = $sobre['seq'] ?? ++$this->ultimoSeq;
        $this->ultimoSeq = max($this->ultimoSeq, $seq);
        $legacy = ! str_contains($json, '"version"');

        $fila = array_merge([
            'uuid' => (string) Str::uuid(),
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => $legacy ? 'legacy' : 'publish',
            'version_id' => null,
            'restores_publication_id' => null,
            'previous_publication_id' => null,
            'engine_contract' => Lector::CONTRACT,
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => Lector::signatureFor($json),
            'catalog_version' => '2026-10-19.1',
            'lab_gate' => json_encode(['mode' => 'waived']),
            'acknowledged' => null,
            'evidence' => null,
            'reason' => $legacy ? 'Prueba del lector del motor' : null,
            'actor_admin_id' => 7,
            'actor_name' => 'Persona de pruebas',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $sobre);

        DB::table(self::TABLA)->insert($fila);

        return $fila;
    }

    /** En PostgreSQL la base ya rechaza otro contrato; se quita el CHECK dentro de la transacción de la prueba. */
    private function sinElCheckDelContrato(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLA.' DROP CONSTRAINT IF EXISTS uspub_engine_contract_chk');
        }
    }

    /**
     * Las sentencias que nombran la tabla, desde ahora. Llamar con `true` vacía la lista.
     *
     * @return callable(bool=): list<string>
     */
    private function escuchaConsultas(): callable
    {
        $vistas = [];
        DB::listen(function (QueryExecuted $q) use (&$vistas): void {
            if (str_contains($q->sql, self::TABLA)) {
                $vistas[] = $q->sql;
            }
        });

        return function (bool $vaciar = false) use (&$vistas): array {
            if ($vaciar) {
                $vistas = [];
            }

            return $vistas;
        };
    }

    private function espiaElLog(): MockInterface
    {
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);

        return $spy;
    }

    /** El motivo y la clase del error, nunca su mensaje (puede llevar SQL). */
    private function assertLogDelFallback(MockInterface $log, string $motivo, string $clase): void
    {
        $log->shouldHaveReceived('warning')->withArgs(function (string $evento, array $contexto) use ($motivo, $clase): bool {
            return $evento === 'ultron.published_config.fallback'
                && ($contexto['reason'] ?? null) === $motivo
                && ($contexto['error_class'] ?? null) === $clase
                && ! array_key_exists('error_message', $contexto)
                && ! str_contains(json_encode($contexto), self::TABLA)
                && ! str_contains(strtolower(json_encode($contexto)), 'select');
        })->once();
    }

    /**
     * Un `fallback`: con la fila leída, el sello la nombra (sus columnas `uuid`
     * y `seq`); sin fila, o sin haber podido leerla, no nombra ninguna.
     *
     * @param  array<string,mixed>|null  $fila
     */
    private function assertFallback(string $motivo, ?array $sello, ?array $fila = null, string $mensaje = ''): void
    {
        $this->assertSame(
            ['v' => 1, 'env' => 'all', 'state' => Lector::FALLBACK, 'entry' => $fila['uuid'] ?? null, 'seq' => $fila['seq'] ?? null, 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => $motivo],
            $sello,
            $mensaje ?: "se esperaba fallback/{$motivo}",
        );
    }

    /** Evalúa algo con la variable de entorno puesta (o quitada) y la deja como estaba. */
    private function conLaVariable(?string $valor, callable $fn): mixed
    {
        $nombre = 'MARKETING_ULTRON_STUDIO_CONFIG';
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
        $pon = function (?string $v) use ($nombre): void {
            if ($v === null) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);

                return;
            }
            $_SERVER[$nombre] = $_ENV[$nombre] = $v;
            putenv("{$nombre}={$v}");
        };

        try {
            $pon($valor);

            return $fn();
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
            $pon(null);
            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }
            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
            if ($proceso !== false) {
                putenv("{$nombre}={$proceso}");
            }
        }
    }
}
