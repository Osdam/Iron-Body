<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\VoiceCallPolicy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La frontera del paquete de voz, por tokens (contrato H6 §I9.5, §I6.1 y §I1).
 *
 * LO QUE SE PROTEGE.
 *  - `VoiceCallPolicy::CALLS_ENABLED_IN_CODE === false`.
 *  - En `app/Services/Voice/` la única llamada `Http::` es el GET de
 *    `ElevenLabsVoiceProvider::verifyCredentials()`: ningún otro verbo,
 *    ningún otro cliente de red y ninguna otra clase que use la red.
 *  - No aparecen `outbound-call` ni `register-call` en el código, la
 *    configuración ni las rutas (ni en un comentario).
 *  - Ninguna ruta de `routes/` apunta a `App\Services\Voice`, a un
 *    controlador de voz ni a ElevenLabs.
 *  - Solo `ElevenLabsVoiceProvider` lee `voice.elevenlabs.api_key`,
 *    `webhook_secret` y `tool_secret` (en todo `app/` y `routes/`); el bloque
 *    entero (`voice` o `voice.elevenlabs`) solo lo lee `ElevenLabsConfig`, que
 *    reduce los secretos a «hay o no hay»; y nadie fuera de `config/voice.php`
 *    lee del entorno las variables de voz.
 *  - El espacio es NEUTRO: no nombra al motor ni a quien administra el
 *    agente, no usa modelos, base, caché, colas ni registro, y el puerto no
 *    tiene ningún método que inicie, registre ni transfiera una llamada.
 *  - Los ficheros del paquete son exactamente estos: uno nuevo tiene que
 *    pasar por aquí.
 */
class VoiceIsolationTest extends TestCase
{
    private const PAQUETE = [
        'ElevenLabs/ElevenLabsConfig.php',
        'ElevenLabs/ElevenLabsVoiceProvider.php',
        'ElevenLabs/ElevenLabsWebhookEnvelope.php',
        'ElevenLabs/ElevenLabsWebhookSignature.php',
        'NullVoiceProvider.php',
        'VoiceAgentProvider.php',
        'VoiceCallContext.php',
        'VoiceCallDecision.php',
        'VoiceCallPolicy.php',
        'VoiceCheckResult.php',
        'VoiceProviderStatus.php',
        'VoiceProviders.php',
    ];

    private const COMANDOS = ['VoiceDoctorCommand.php'];

    private const PROVEEDOR = 'app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php';

    private const CONFIGURACION = 'app/Services/Voice/ElevenLabs/ElevenLabsConfig.php';

    private const SECRETOS = ['voice.elevenlabs.api_key', 'voice.elevenlabs.webhook_secret', 'voice.elevenlabs.tool_secret'];

    /** Métodos de un cliente HTTP que no son un GET (o que mandan un cuerpo). */
    private const VERBOS = ['post', 'put', 'patch', 'delete', 'head', 'send', 'sendrequest', 'asform', 'asmultipart', 'attach', 'withbody', 'pool', 'async'];

    /** Primitivas de red que no pasan por `Http::`. */
    private const RED = ['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'file_get_contents', 'fopen', 'get_headers', 'socket_create', 'mail'];

    /** Lo que un espacio neutro y sin estado no usa. */
    private const IMPURO = ['DB', 'Schema', 'Log', 'Cache', 'Queue', 'Bus', 'Storage', 'Mail', 'Notification', 'Event', 'Session', 'Redis',
        'dispatch', 'event', 'cache', 'logger', 'report', 'info', 'now', 'today', 'Carbon', 'CarbonImmutable', 'env', 'session'];

    private static function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> ruta relativa al paquete → ruta absoluta */
    private static function paquete(): array
    {
        $base = self::raiz().'/app/Services/Voice';
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $out[substr($f->getPathname(), strlen($base) + 1)] = $f->getPathname();
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,string> ruta relativa al backend → ruta absoluta, de todo el PHP bajo estos directorios */
    private static function php(string ...$directorios): array
    {
        $out = [];
        foreach ($directorios as $dir) {
            $abs = self::raiz().'/'.$dir;
            if (is_file($abs)) {
                $out[$dir] = $abs;

                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $out[substr($f->getPathname(), strlen(self::raiz()) + 1)] = $f->getPathname();
                }
            }
        }
        ksort($out);

        return $out;
    }

    public function test_estan_todos_los_ficheros_del_paquete(): void
    {
        $this->assertSame(self::PAQUETE, array_keys(self::paquete()));
        $comandos = array_map('basename', array_keys(self::php('app/Console/Commands/Voice')));
        $this->assertSame(self::COMANDOS, $comandos);
    }

    public function test_las_llamadas_estan_cerradas_en_el_codigo(): void
    {
        $this->assertFalse(VoiceCallPolicy::CALLS_ENABLED_IN_CODE);
        $codigo = (string) file_get_contents(self::raiz().'/app/Services/Voice/VoiceCallPolicy.php');
        $this->assertSame(1, preg_match_all('/public const CALLS_ENABLED_IN_CODE = false;/', $codigo), 'la constante, literal y una sola vez');
    }

    public function test_la_unica_llamada_http_es_el_get_de_verify_credentials(): void
    {
        $usosHttp = [];
        foreach (self::paquete() as $relativa => $ruta) {
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && self::corto($t[1]) === 'Http' && (self::siguiente($tokens, $i)[0] ?? null) === T_DOUBLE_COLON) {
                    $usosHttp[] = [$relativa, $i];
                }
                // Ningún verbo que no sea GET, en ningún fichero del paquete.
                if ($t[0] === T_STRING && in_array(strtolower($t[1]), self::VERBOS, true) && in_array(self::anterior($tokens, $i)[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $this->fail("{$relativa} llama a ->{$t[1]}(): la voz solo hace un GET");
                }
                if ($t[0] === T_STRING && in_array(strtolower($t[1]), self::RED, true) && (self::siguiente($tokens, $i)[1] ?? null) === '(') {
                    $this->fail("{$relativa} usa {$t[1]}(): la red solo sale por Http:: en el proveedor");
                }
                if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/GuzzleHttp|HttpClient|Psr\\\\Http/', $t[1]) === 1) {
                    $this->fail("{$relativa} nombra {$t[1]}: la red solo sale por Http:: en el proveedor");
                }
            }
        }

        $this->assertCount(1, $usosHttp, 'una sola llamada Http:: en todo el paquete');
        [$fichero, $indice] = $usosHttp[0];
        $this->assertSame('ElevenLabs/ElevenLabsVoiceProvider.php', $fichero);

        $tokens = self::tokens(self::raiz().'/'.self::PROVEEDOR);
        [$desde, $hasta] = self::cuerpoDe($tokens, 'verifyCredentials');
        $this->assertTrue($indice > $desde && $indice < $hasta, 'la llamada Http:: está dentro de verifyCredentials()');

        // La cadena que empieza en Http:: termina en ->get(…) y no pasa por ningún otro verbo.
        $metodos = [];
        for ($j = $indice; $j < $hasta && ($tokens[$j][1] ?? null) !== ';'; $j++) {
            if ($tokens[$j][0] === T_STRING && in_array(self::anterior($tokens, $j)[0] ?? null, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && (self::siguiente($tokens, $j)[1] ?? null) === '(') {
                $metodos[] = $tokens[$j][1];
            }
        }
        $this->assertSame(['withHeaders', 'acceptJson', 'withoutRedirecting', 'connectTimeout', 'timeout', 'get'], $metodos);

        foreach (self::COMANDOS as $comando) {
            $this->assertNotContains('Http', self::usos(self::raiz().'/app/Console/Commands/Voice/'.$comando), "{$comando} usa la red");
        }
    }

    public function test_no_aparecen_outbound_call_ni_register_call(): void
    {
        foreach (self::php('app', 'routes', 'config') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            foreach (['outbound-call', 'register-call'] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase($prohibido, $todo, "{$relativa} nombra {$prohibido}");
            }
        }
    }

    public function test_ninguna_ruta_apunta_a_la_voz(): void
    {
        foreach (self::php('routes') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            $this->assertStringNotContainsString('Services\\Voice', $todo, "{$relativa} apunta a App\\Services\\Voice");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]*Voice[A-Za-z]*Controller/', $todo, "{$relativa} usa un controlador de voz");
            $this->assertDoesNotMatchRegularExpression('/elevenlabs/i', $todo, "{$relativa} nombra ElevenLabs");
            $this->assertStringNotContainsString('Commands\\Voice', $todo, "{$relativa} programa o registra el comando de voz");
        }
    }

    public function test_solo_el_proveedor_lee_los_secretos_y_solo_la_configuracion_el_bloque(): void
    {
        $secretos = [];
        $bloques = [];
        foreach (self::php('app', 'routes') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            if (stripos($todo, 'voice') === false) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $valor = self::literal($t[1]);
                if (in_array($valor, self::SECRETOS, true)) {
                    $secretos[$relativa] = true;
                }
                if ($valor === 'voice.elevenlabs' || ($valor === 'voice' && self::esLecturaDeConfiguracion($tokens, $i))) {
                    $bloques[$relativa] = true;
                }
            }
        }

        $this->assertSame([self::PROVEEDOR], array_keys($secretos), 'solo ElevenLabsVoiceProvider toma los secretos de voz de la configuración');
        $this->assertSame([self::CONFIGURACION], array_keys($bloques), 'solo ElevenLabsConfig lee el bloque entero (y lo reduce a «hay o no hay»)');

        // Dentro del paquete, cada fichero lee exactamente lo suyo.
        foreach (self::paquete() as $relativa => $ruta) {
            $esperadas = match ($relativa) {
                'ElevenLabs/ElevenLabsConfig.php' => ['voice.elevenlabs'],
                'ElevenLabs/ElevenLabsVoiceProvider.php' => ['voice.elevenlabs.api_key', 'voice.elevenlabs.tool_secret', 'voice.elevenlabs.webhook_secret'],
                default => [],
            };
            $this->assertSame($esperadas, self::lecturasDeConfiguracion($ruta), "{$relativa} lee la configuración");
        }
        foreach (self::COMANDOS as $comando) {
            $this->assertSame([], self::lecturasDeConfiguracion(self::raiz().'/app/Console/Commands/Voice/'.$comando), "{$comando} lee la configuración");
        }
    }

    public function test_nadie_fuera_de_config_voice_lee_las_variables_de_voz_del_entorno(): void
    {
        $leen = [];
        foreach (self::php('app', 'routes', 'config') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            if (! str_contains($todo, 'ELEVENLABS_') && ! str_contains($todo, 'TWILIO_VOICE_')) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] !== T_CONSTANT_ENCAPSED_STRING || preg_match('/^(ELEVENLABS_|TWILIO_VOICE_)/', self::literal($t[1])) !== 1) {
                    continue;
                }
                $antes = self::anterior($tokens, $i);
                $antesDe = $tokens[$i - 2] ?? null;
                $esLectura = ($antes[1] ?? null) === '(' && $antesDe !== null && $antesDe[0] === T_STRING && in_array(strtolower($antesDe[1]), ['env', 'getenv', 'get'], true)
                    || ($antes[1] ?? null) === '[' && $antesDe !== null && in_array($antesDe[1], ['$_ENV', '$_SERVER'], true);
                if ($esLectura) {
                    $leen[$relativa] = true;
                }
            }
        }

        $this->assertSame(['config/voice.php'], array_keys($leen));
    }

    public function test_el_espacio_es_neutro_y_sin_estado(): void
    {
        foreach (self::paquete() as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            foreach (['UltronStudio', 'ultron_studio', 'ultron-studio', 'PhoneFingerprint', 'App\\Services\\Marketing', 'App\\Models\\'] as $ajeno) {
                $this->assertStringNotContainsString($ajeno, $todo, "{$relativa} nombra {$ajeno}");
            }
            foreach (self::tokens($ruta) as $t) {
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertDoesNotMatchRegularExpression('/Studio|Ultron|Marketing/', $t[1], "{$relativa} nombra {$t[1]}");
                }
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $this->assertDoesNotMatchRegularExpression('/^(ultron|marketing\.|meta\.|otp\.)/i', self::literal($t[1]), "{$relativa} nombra {$t[1]}");
                }
            }
            $usos = self::usos($ruta);
            foreach (self::IMPURO as $impuro) {
                $this->assertNotContains($impuro, $usos, "{$relativa} usa {$impuro}");
            }
            if ($relativa !== 'ElevenLabs/ElevenLabsVoiceProvider.php') {
                $this->assertNotContains('Http', $usos, "{$relativa} usa la red");
            }
        }

        foreach (self::COMANDOS as $comando) {
            $usos = self::usos(self::raiz().'/app/Console/Commands/Voice/'.$comando);
            foreach (['DB', 'Schema', 'Storage', 'Cache', 'Queue', 'Bus', 'Log', 'Model', 'dispatch', 'logger', 'report', 'env'] as $impuro) {
                $this->assertNotContains($impuro, $usos, "{$comando} usa {$impuro}: no guarda ni registra nada");
            }
        }
    }

    public function test_el_puerto_no_sabe_llamar_a_nadie(): void
    {
        $paquete = self::paquete();

        $puerto = array_keys(self::metodosDeclarados($paquete['VoiceAgentProvider.php']));
        sort($puerto);
        $this->assertSame(['id', 'status', 'verifyCredentials'], $puerto, 'el puerto solo describe y verifica');

        // Ningún método público del paquete se llama como una acción de llamada.
        foreach ($paquete as $relativa => $ruta) {
            foreach (self::metodosDeclarados($ruta) as $nombre => $publico) {
                if ($publico) {
                    $this->assertDoesNotMatchRegularExpression('/^(call|dial|place|start|initiate|register|transfer|outbound|originate|make|connect|ring|hangup|send)/i', $nombre, "{$relativa}::{$nombre}");
                }
            }
        }
    }

    /**
     * Los métodos que declara un fichero (sin closures) → ¿es público? Sin
     * modificador de visibilidad, público (como en una interfaz).
     *
     * @return array<string,bool>
     */
    private static function metodosDeclarados(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] !== T_FUNCTION || ($tokens[$i + 1][0] ?? null) !== T_STRING) {
                continue;
            }
            $publico = true;
            for ($j = $i - 1; $j >= 0 && in_array($tokens[$j][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true); $j--) {
                if (in_array($tokens[$j][0], [T_PROTECTED, T_PRIVATE], true)) {
                    $publico = false;
                }
            }
            $out[$tokens[$i + 1][1]] = $publico;
        }

        return $out;
    }

    /** ¿Es este literal `'voice'` el argumento de una lectura de la configuración? (`config(…)`, `Config::x(…)` o `config()->x(…)`) */
    private static function esLecturaDeConfiguracion(array $tokens, int $i): bool
    {
        if ((self::anterior($tokens, $i)[1] ?? null) !== '(') {
            return false;
        }
        $nombre = $tokens[$i - 2] ?? null;
        if ($nombre === null || $nombre[0] !== T_STRING) {
            return false;
        }
        if (strtolower($nombre[1]) === 'config' && ! in_array($tokens[$i - 3][0] ?? null, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            return true;
        }
        $operador = $tokens[$i - 3] ?? null;
        if ($operador !== null && $operador[0] === T_DOUBLE_COLON) {
            return in_array(self::corto($tokens[$i - 4][1] ?? ''), ['Config'], true);
        }
        if ($operador !== null && $operador[0] === T_OBJECT_OPERATOR) {
            return ($tokens[$i - 4][1] ?? null) === ')' && ($tokens[$i - 5][1] ?? null) === '(' && strtolower($tokens[$i - 6][1] ?? '') === 'config';
        }

        return false;
    }

    /** @return list<string> las claves literales que se leen con `config(...)`, ordenadas */
    private static function lecturasDeConfiguracion(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && strtolower($t[1]) === 'config' && (self::siguiente($tokens, $i)[1] ?? null) === '(') {
                $antes = self::anterior($tokens, $i);
                if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }
                $arg = $tokens[$i + 2] ?? null;
                $out[] = $arg !== null && $arg[0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($arg[1]) : '(no literal)';
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Los nombres usados como función o como clase con `::`/`new` (sin
     * métodos, constantes de clase ni declaraciones), por su nombre corto.
     *
     * @return list<string>
     */
    private static function usos(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (! in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $antes = self::anterior($tokens, $i);
            $despues = self::siguiente($tokens, $i);
            if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }
            if (($despues !== null && in_array($despues[1], ['(', '::'], true)) || ($antes !== null && $antes[0] === T_NEW)) {
                $out[] = self::corto($t[1]);
            }
            if ($antes !== null && $antes[0] === T_USE) {
                $out[] = self::corto($t[1]);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array{0:int,1:int} los índices de las llaves que abren y cierran el cuerpo del método */
    private static function cuerpoDe(array $tokens, string $metodo): array
    {
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_FUNCTION && ($tokens[$i + 1][1] ?? null) === $metodo) {
                $j = $i;
                while (isset($tokens[$j]) && $tokens[$j][1] !== '{') {
                    $j++;
                }
                $profundidad = 0;
                for ($k = $j; isset($tokens[$k]); $k++) {
                    if (in_array($tokens[$k][1], ['{'], true) || in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $profundidad++;
                    } elseif ($tokens[$k][1] === '}') {
                        $profundidad--;
                        if ($profundidad === 0) {
                            return [$j, $k];
                        }
                    }
                }
            }
        }
        self::fail("No se encontró el método {$metodo}.");
    }

    private static function corto(string $nombre): string
    {
        return substr((string) strrchr('\\'.$nombre, '\\'), 1);
    }

    private static function literal(string $token): string
    {
        return stripcslashes(substr($token, 1, -1));
    }

    /** @return list<array{0:int,1:string}> sin comentarios ni espacios */
    private static function tokens(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            $t = is_array($t) ? $t : [0, $t];
            if (! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @param  list<array{0:int,1:string}>  $tokens */
    private static function anterior(array $tokens, int $i): ?array
    {
        return $tokens[$i - 1] ?? null;
    }

    /** @param  list<array{0:int,1:string}>  $tokens */
    private static function siguiente(array $tokens, int $i): ?array
    {
        return $tokens[$i + 1] ?? null;
    }
}
