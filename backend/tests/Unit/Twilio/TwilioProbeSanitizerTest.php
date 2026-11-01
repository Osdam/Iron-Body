<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioProbeSanitizer as Saneador;
use App\Services\Twilio\TwilioReadOnlyClient as Cliente;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * El saneador de la comprobación de Twilio (contrato H6 §I5.4 e §I13), contra
 * el fixture escrito a mano con la forma pública de la API y contenido hostil.
 *
 * LO QUE SE PROTEGE.
 *  - Los casos de `fixtures/sanitize-cases.json`: `outcome` y `facts` EXACTOS,
 *    con el orden de claves del contrato (401, 403, 429, tiempo agotado,
 *    cuerpos inesperados, pasos saltados, valores fuera de los conjuntos).
 *  - Lista blanca: ningún centinela (teléfonos, URLs con credenciales, nombres,
 *    el auth_token, SIDs, nombres de campos crudos) sobrevive en ningún caso.
 *  - La forma es cerrada en todo resultado: tipos, conjuntos, sumas, topes de
 *    página, bloque null ⇔ entrada en `errors`, y solo null, booleanos,
 *    enteros, cadenas y arrays (se puede huellar tal cual).
 *  - Página llena ⇒ `truncated`, y nunca más filas que la página.
 *  - Las reglas del `outcome`.
 *  - Lo que pasa mal quien llama lanza; lo que diga Twilio, nunca.
 *  - Es pura y solo expone `sanitize()`.
 */
class TwilioProbeSanitizerTest extends TestCase
{
    private const FICHERO = 'app/Services/Twilio/TwilioProbeSanitizer.php';

    /** @return array<string,mixed> */
    private static function fixture(string $nombre): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$nombre), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Las respuestas de un caso, con los cuerpos `{"$ref": …}` tomados del
     * fixture de la API tal cual (con su auth_token: aquí no pasan por el cliente).
     *
     * @param  array<string,mixed>  $caso
     * @return array<string,mixed>
     */
    private static function respuestas(array $caso): array
    {
        $api = self::fixture('twilio-api-responses.json');
        $out = [];
        foreach ($caso['responses'] as $paso => $respuesta) {
            if (is_array($respuesta['body'] ?? null) && array_key_exists('$ref', $respuesta['body'])) {
                $ref = $respuesta['body']['$ref'];
                $respuesta['body'] = $api[$ref] ?? throw new LogicException("El fixture de la API no tiene «{$ref}».");
            }
            $out[$paso] = $respuesta;
        }

        return $out;
    }

    /** @return array{ok: true, status: 200, body: array<mixed>, error: null} */
    private static function bien(array $cuerpo): array
    {
        return ['ok' => true, 'status' => 200, 'body' => $cuerpo, 'error' => null];
    }

    private static function plano(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function test_los_casos_del_fixture_dan_exactamente_lo_esperado(): void
    {
        $casos = self::fixture('sanitize-cases.json')['cases'];
        $this->assertGreaterThanOrEqual(17, count($casos));

        foreach ($casos as $caso) {
            $r = Saneador::sanitize(self::respuestas($caso), $caso['credential']);
            // assertSame en arrays exige también el ORDEN de las claves (el del contrato).
            $this->assertSame($caso['expected'], $r, $caso['name']);
            $this->assertForma($r, $caso['name']);
        }
    }

    public function test_el_contenido_hostil_no_sobrevive_en_ningun_caso(): void
    {
        $fixture = self::fixture('sanitize-cases.json');
        $centinelas = $fixture['sentinels'];
        $this->assertGreaterThanOrEqual(50, count($centinelas));

        // Cada centinela está de verdad en la entrada: si no, esto no probaría nada.
        $api = self::fixture('twilio-api-responses.json');
        unset($api['_meta']);
        $entrada = self::plano($api);
        foreach ($centinelas as $centinela) {
            $this->assertStringContainsString($centinela, $entrada, "El centinela «{$centinela}» no está en los cuerpos de la API.");
        }

        foreach ($fixture['cases'] as $caso) {
            $salida = self::plano(Saneador::sanitize(self::respuestas($caso), $caso['credential']));
            foreach ($centinelas as $centinela) {
                $this->assertStringNotContainsString($centinela, $salida, "{$caso['name']}: sobrevive «{$centinela}»");
            }
        }
    }

    public function test_los_numeros_de_la_cuenta_dejan_solo_sus_4_digitos_y_los_caller_id_ni_eso(): void
    {
        $api = self::fixture('twilio-api-responses.json');
        $r = Saneador::sanitize([
            'account' => self::bien($api['account_full_active']),
            'numbers' => self::bien($api['numbers_two']),
            'caller_ids' => self::bien($api['caller_ids_one']),
            'calls' => self::bien($api['calls_week']),
            'alerts' => self::bien($api['alerts_hostile']),
        ], 'otp');

        $this->assertSame(['0178', '0126'], array_column($r['facts']['numbers']['items'], 'last4'));
        // Del caller ID (+1 202-555-0143, el móvil de una persona) solo el recuento.
        $this->assertSame(['count' => 1, 'truncated' => false], $r['facts']['callerIds']);
        // En toda la salida, ninguna serie de 5 o más dígitos salvo los códigos de alerta.
        preg_match_all('/\d{5,}/', self::plano($r), $series);
        $this->assertSame(['11200', '60200'], array_values(array_unique($series[0])));
    }

    public function test_las_paginas_llenas_se_marcan_truncadas_y_nunca_pasan_de_una_pagina(): void
    {
        $api = self::fixture('twilio-api-responses.json');
        $numero = $api['numbers_two']['incoming_phone_numbers'][0];
        $callerId = $api['caller_ids_one']['outgoing_caller_ids'][0];
        $llamada = $api['calls_week']['calls'][0];
        $alerta = $api['alerts_hostile']['alerts'][1];

        $r = Saneador::sanitize([
            'account' => self::bien($api['account_full_active']),
            // Más filas de las pedidas (20): se marca truncada y se recorta a la página.
            'numbers' => self::bien(['incoming_phone_numbers' => array_fill(0, 25, $numero)]),
            'caller_ids' => self::bien(['outgoing_caller_ids' => array_fill(0, 20, $callerId)]),
            'calls' => self::bien(['calls' => array_fill(0, 50, $llamada)]),
            // Una menos que la página: no está llena.
            'alerts' => self::bien(['alerts' => array_fill(0, 49, $alerta)]),
        ], 'otp');

        $this->assertSame('ok', $r['outcome']);
        $this->assertSame(
            ['count' => 20, 'truncated' => true, 'voiceCapable' => 20, 'smsCapable' => 20, 'items' => array_fill(0, 20, ['last4' => '0178', 'voice' => true, 'sms' => true])],
            $r['facts']['numbers'],
        );
        $this->assertSame(['count' => 20, 'truncated' => true], $r['facts']['callerIds']);
        $this->assertSame(['total' => 50, 'truncated' => true, 'byStatus' => ['completed' => 50]], $r['facts']['calls7d']);
        $this->assertSame(['total' => 49, 'truncated' => false, 'byCode' => ['60200' => 49], 'byLevel' => ['error' => 49]], $r['facts']['alerts24h']);
        $this->assertForma($r, 'páginas llenas');

        $r = Saneador::sanitize([
            'account' => self::bien($api['account_full_active']),
            'numbers' => self::bien(['incoming_phone_numbers' => array_fill(0, 19, $numero)]),
            'caller_ids' => self::bien(['outgoing_caller_ids' => array_fill(0, 19, $callerId)]),
            'calls' => self::bien(['calls' => array_fill(0, 49, $llamada)]),
            'alerts' => self::bien(['alerts' => array_fill(0, 51, $alerta)]),
        ], 'otp');
        $this->assertFalse($r['facts']['numbers']['truncated']);
        $this->assertSame(19, $r['facts']['numbers']['count']);
        $this->assertFalse($r['facts']['callerIds']['truncated']);
        $this->assertFalse($r['facts']['calls7d']['truncated']);
        $this->assertSame(['total' => 50, 'truncated' => true, 'byCode' => ['60200' => 50], 'byLevel' => ['error' => 50]], $r['facts']['alerts24h']);
    }

    public function test_las_reglas_del_outcome(): void
    {
        $api = self::fixture('twilio-api-responses.json');
        $todoBien = [
            'account' => self::bien($api['account_full_active']),
            'numbers' => self::bien($api['numbers_two']),
            'caller_ids' => self::bien($api['caller_ids_one']),
            'calls' => self::bien($api['calls_week']),
            'alerts' => self::bien($api['alerts_hostile']),
        ];
        $fallos = [
            'auth_failed' => ['ok' => false, 'status' => 401, 'body' => null, 'error' => 'auth_failed'],
            'unreachable' => ['ok' => false, 'status' => null, 'body' => null, 'error' => 'unreachable'],
            'error' => ['ok' => false, 'status' => 500, 'body' => null, 'error' => 'error'],
            'cuerpo inesperado' => self::bien(['inesperado' => true]),
            'skipped' => ['skipped' => true],
        ];

        $this->assertSame('ok', Saneador::sanitize($todoBien, 'otp')['outcome']);

        // La cuenta manda: auth_failed y unreachable se dicen tal cual; cualquier otro fallo es error.
        $esperadoSiFallaLaCuenta = ['auth_failed' => 'auth_failed', 'unreachable' => 'unreachable', 'error' => 'error', 'cuerpo inesperado' => 'error', 'skipped' => 'error'];
        foreach ($fallos as $nombre => $fallo) {
            $r = Saneador::sanitize(['account' => $fallo] + $todoBien, 'otp');
            $this->assertSame($esperadoSiFallaLaCuenta[$nombre], $r['outcome'], "la cuenta: {$nombre}");
            $this->assertForma($r, "la cuenta: {$nombre}");
        }

        // Con la cuenta bien, cualquier otro paso que falle o se salte degrada.
        foreach (['numbers', 'caller_ids', 'calls', 'alerts'] as $paso) {
            foreach ($fallos as $nombre => $fallo) {
                $r = Saneador::sanitize([$paso => $fallo] + $todoBien, 'otp');
                $this->assertSame('degraded', $r['outcome'], "{$paso}: {$nombre}");
                $this->assertNull($r['facts'][Saneador::FACT_KEYS[$paso]], "{$paso}: {$nombre}");
                $this->assertForma($r, "{$paso}: {$nombre}");
            }
        }

        // Sin ninguna respuesta: los cinco saltados y error.
        $r = Saneador::sanitize([], 'otp');
        $this->assertSame('error', $r['outcome']);
        $this->assertSame(Cliente::STEPS, array_column($r['facts']['errors'], 'step'));
        $this->assertSame(array_fill(0, 5, 'skipped'), array_column($r['facts']['errors'], 'outcome'));
    }

    public function test_lo_que_pasa_mal_quien_llama_lanza_sin_eco_del_valor(): void
    {
        $casos = [
            'paso desconocido' => [['messages' => ['skipped' => true]], 'otp'],
            'paso con mayúsculas' => [['Account' => ['skipped' => true]], 'otp'],
            'lista en lugar de pasos' => [[['skipped' => true]], 'otp'],
            'credencial desconocida' => [[], 'admin'],
            'credencial vacía' => [[], ''],
            'respuesta que no es un array' => [['account' => 'ok'], 'otp'],
            'respuesta nula' => [['account' => null], 'otp'],
        ];
        foreach ($casos as $nombre => [$respuestas, $credencial]) {
            try {
                Saneador::sanitize($respuestas, $credencial);
                $this->fail("{$nombre}: tenía que lanzar");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('messages', $e->getMessage(), $nombre);
                $this->assertStringNotContainsString('admin', $e->getMessage(), $nombre);
            }
        }
    }

    public function test_es_pura_y_solo_expone_sanitize(): void
    {
        $publicos = array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(Saneador::class))->getMethods(ReflectionMethod::IS_PUBLIC));
        $this->assertSame(['sanitize'], $publicos);
        $this->assertTrue((new ReflectionClass(Saneador::class))->isFinal());

        // Sin red, reloj, base, log, caché, configuración ni ficheros.
        $impuro = ['Http', 'Log', 'DB', 'Cache', 'Storage', 'File', 'Queue', 'Bus', 'Event', 'Carbon', 'CarbonImmutable', 'DateTime', 'DateTimeImmutable',
            'config', 'env', 'app', 'now', 'today', 'time', 'microtime', 'hrtime', 'date', 'gmdate', 'logger', 'report', 'info', 'cache', 'event', 'dispatch',
            'file_get_contents', 'file_put_contents', 'fopen', 'curl_init', 'random_int', 'mt_rand', 'rand', 'uniqid', 'getenv'];
        $usados = self::nombresUsados(dirname(__DIR__, 3).'/'.self::FICHERO);
        $this->assertSame([], array_values(array_intersect($impuro, $usados)), 'el saneador usa algo impuro');
    }

    /**
     * La forma cerrada de un resultado, sea cual sea la entrada.
     *
     * @param  array<string,mixed>  $r
     */
    private function assertForma(array $r, string $caso): void
    {
        $this->assertSame(['outcome', 'facts'], array_keys($r), $caso);
        $this->assertContains($r['outcome'], Saneador::OUTCOMES, $caso);
        $f = $r['facts'];
        $this->assertSame(['credential', 'account', 'numbers', 'callerIds', 'calls7d', 'alerts24h', 'errors'], array_keys($f), $caso);
        $this->assertContains($f['credential'], Saneador::CREDENTIALS, $caso);
        $this->assertSoloEscalaresYArrays($f, $caso);

        if ($f['account'] !== null) {
            $this->assertSame(['status', 'type'], array_keys($f['account']), $caso);
            $this->assertContains($f['account']['status'], [...Saneador::ACCOUNT_STATUSES, null], $caso);
            $this->assertContains($f['account']['type'], [...Saneador::ACCOUNT_TYPES, null], $caso);
        }
        if ($f['numbers'] !== null) {
            $n = $f['numbers'];
            $this->assertSame(['count', 'truncated', 'voiceCapable', 'smsCapable', 'items'], array_keys($n), $caso);
            $this->assertIsBool($n['truncated'], $caso);
            $this->assertTrue(array_is_list($n['items']), $caso);
            $this->assertSame(count($n['items']), $n['count'], $caso);
            $this->assertLessThanOrEqual(Cliente::PAGE_SIZES['numbers'], $n['count'], $caso);
            $this->assertSame(count(array_filter(array_column($n['items'], 'voice'))), $n['voiceCapable'], $caso);
            $this->assertSame(count(array_filter(array_column($n['items'], 'sms'))), $n['smsCapable'], $caso);
            foreach ($n['items'] as $item) {
                $this->assertSame(['last4', 'voice', 'sms'], array_keys($item), $caso);
                $this->assertTrue($item['last4'] === null || preg_match('/^\d{4}$/D', $item['last4']) === 1, $caso);
                $this->assertIsBool($item['voice'], $caso);
                $this->assertIsBool($item['sms'], $caso);
            }
        }
        if ($f['callerIds'] !== null) {
            $this->assertSame(['count', 'truncated'], array_keys($f['callerIds']), $caso);
            $this->assertIsInt($f['callerIds']['count'], $caso);
            $this->assertLessThanOrEqual(Cliente::PAGE_SIZES['caller_ids'], $f['callerIds']['count'], $caso);
        }
        if ($f['calls7d'] !== null) {
            $c = $f['calls7d'];
            $this->assertSame(['total', 'truncated', 'byStatus'], array_keys($c), $caso);
            $this->assertLessThanOrEqual(Cliente::PAGE_SIZES['calls'], $c['total'], $caso);
            $this->assertRecuento($c['byStatus'], $c['total'], static fn (string $k): bool => in_array($k, [...Saneador::CALL_STATUSES, Saneador::OTHER], true), $caso);
        }
        if ($f['alerts24h'] !== null) {
            $a = $f['alerts24h'];
            $this->assertSame(['total', 'truncated', 'byCode', 'byLevel'], array_keys($a), $caso);
            $this->assertLessThanOrEqual(Cliente::PAGE_SIZES['alerts'], $a['total'], $caso);
            $this->assertRecuento($a['byCode'], $a['total'], static fn (string $k): bool => $k === Saneador::OTHER || preg_match('/^[1-9]\d{0,5}$/D', $k) === 1, $caso);
            $this->assertRecuento($a['byLevel'], $a['total'], static fn (string $k): bool => in_array($k, [...Saneador::ALERT_LEVELS, Saneador::OTHER], true), $caso);
        }

        $this->assertTrue(array_is_list($f['errors']), $caso);
        $pasos = [];
        foreach ($f['errors'] as $error) {
            $this->assertSame(['step', 'outcome', 'httpStatus'], array_keys($error), $caso);
            $this->assertContains($error['step'], Cliente::STEPS, $caso);
            $this->assertContains($error['outcome'], Saneador::STEP_OUTCOMES, $caso);
            $this->assertTrue($error['httpStatus'] === null || (is_int($error['httpStatus']) && $error['httpStatus'] >= 100 && $error['httpStatus'] <= 599), $caso);
            $pasos[] = $error['step'];
        }
        // En el orden de los pasos, sin repetir, y bloque null ⇔ el paso está en `errors`.
        $this->assertSame(array_values(array_intersect(Cliente::STEPS, $pasos)), $pasos, $caso);
        foreach (Saneador::FACT_KEYS as $paso => $clave) {
            $this->assertSame(in_array($paso, $pasos, true), $f[$clave] === null, "{$caso}: {$paso}");
        }
    }

    /**
     * @param  array<string|int,int>  $recuento
     * @param  callable(string): bool  $claveValida
     */
    private function assertRecuento(array $recuento, int $total, callable $claveValida, string $caso): void
    {
        $this->assertSame($total, array_sum($recuento), "{$caso}: el recuento no suma el total");
        $claves = array_map('strval', array_keys($recuento));
        $ordenadas = $claves;
        sort($ordenadas, SORT_STRING);
        $this->assertSame($ordenadas, $claves, "{$caso}: el recuento no está ordenado");
        foreach ($recuento as $clave => $n) {
            $this->assertTrue($claveValida((string) $clave), "{$caso}: clave «{$clave}» fuera de su conjunto");
            $this->assertIsInt($n, $caso);
            $this->assertGreaterThan(0, $n, $caso);
        }
    }

    /** Solo null, booleanos, enteros, cadenas y arrays: ni decimales ni objetos. */
    private function assertSoloEscalaresYArrays(mixed $valor, string $caso): void
    {
        if (is_array($valor)) {
            foreach ($valor as $v) {
                $this->assertSoloEscalaresYArrays($v, $caso);
            }

            return;
        }
        $this->assertTrue($valor === null || is_bool($valor) || is_int($valor) || is_string($valor), "{$caso}: valor de tipo ".get_debug_type($valor));
    }

    /**
     * Los nombres usados como función o como clase (`x(`, `X::`, `new X`), sin
     * métodos ni constantes de clase ni declaraciones.
     *
     * @return list<string>
     */
    private static function nombresUsados(string $ruta): array
    {
        $tokens = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            $t = is_array($t) ? $t : [0, $t];
            if (! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                $tokens[] = $t;
            }
        }
        $out = [];
        foreach ($tokens as $i => $t) {
            if (! in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $antes = $tokens[$i - 1] ?? null;
            $despues = $tokens[$i + 1] ?? null;
            if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }
            if (($despues !== null && in_array($despues[1], ['(', '::'], true)) || ($antes !== null && $antes[0] === T_NEW) || ($antes !== null && $antes[0] === T_USE)) {
                $out[] = substr((string) strrchr('\\'.$t[1], '\\'), 1);
            }
        }

        return array_values(array_unique($out));
    }
}
