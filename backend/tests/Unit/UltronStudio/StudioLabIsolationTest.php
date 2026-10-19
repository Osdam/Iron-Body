<?php

namespace Tests\Unit\UltronStudio;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La frontera del núcleo del laboratorio, por tokens (contrato H4 §A4, §A5 y
 * §A10).
 *
 * LO QUE SE PROTEGE.
 *  - Solo `StudioLabEngineChecks` nombra el motor.
 *  - Ningún fichero del laboratorio nombra la configuración ni la clave del
 *    motor o de IRON IA (`services.openai`, `marketing.ai`, `OPENAI_API_KEY`,
 *    `SalesAiConfig`), ni siquiera en un comentario: la huella de esa clave la
 *    calcula `config/ultron_studio.php`. Si alguien hace que el cliente lea
 *    `services.openai.api_key`, esto falla.
 *  - La red solo sale del cliente de OpenAI; la configuración solo se lee en
 *    `StudioLabConfig` (el bloque `lab`) y en el cliente (su clave y su URL).
 *  - Nada del núcleo usa el reloj, la base, el log, la caché ni las colas.
 *  - Todos los ficheros van PLANOS en `app/Services/UltronStudio/`: la prueba
 *    estática de lectores del motor no recorre subdirectorios.
 */
class StudioLabIsolationTest extends TestCase
{
    /** Lo que es nombrar el motor (el mismo patrón que UltronStudioEngineReadOnlyTest). */
    private const MOTOR = '/^\\\\?App\\\\(Services\\\\Marketing\\\\|Models\\\\Marketing|Models\\\\Plan$|Models\\\\UltronAbort)|SalesAiConfig|UltronAbort/';

    private const AJENO = ['services.openai', 'marketing.ai', 'OPENAI_API_KEY', 'SalesAiConfig'];

    private const IMPURO = ['now', 'today', 'Carbon', 'CarbonImmutable', 'DB', 'Log', 'Cache', 'Queue', 'Bus', 'Mail', 'Notification', 'Event', 'Storage', 'File', 'dispatch', 'event', 'cache', 'logger', 'info', 'report', 'env', 'StudioLabVerdict'];

    /** @return array<string,string> nombre → ruta */
    private static function ficheros(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3).'/app/Services/UltronStudio/StudioLab*.php') as $ruta) {
            $out[basename($ruta)] = $ruta;
        }
        ksort($out);

        return $out;
    }

    public function test_estan_todos_y_planos(): void
    {
        $this->assertSame([
            'StudioLabCatalog.php', 'StudioLabChecks.php', 'StudioLabConfig.php', 'StudioLabCost.php', 'StudioLabEngineChecks.php', 'StudioLabFakeClient.php',
            'StudioLabModelClient.php', 'StudioLabOpenAiClient.php', 'StudioLabOutcome.php', 'StudioLabPersonalData.php', 'StudioLabPrompts.php',
        ], array_keys(self::ficheros()));

        $raiz = dirname(__DIR__, 3).'/app/Services/UltronStudio';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if (str_starts_with($f->getFilename(), 'StudioLab')) {
                $this->assertSame($raiz, $f->getPath(), "{$f->getFilename()} no está plano en app/Services/UltronStudio/");
            }
        }
    }

    public function test_solo_el_adaptador_nombra_el_motor(): void
    {
        $nombran = [];
        foreach (self::ficheros() as $nombre => $ruta) {
            foreach (self::tokens($ruta) as $t) {
                if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match(self::MOTOR, $t[1]) === 1) {
                    $nombran[$nombre] = true;
                }
                if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match(self::MOTOR, str_replace('\\\\', '\\', trim($t[1], '\'"'))) === 1) {
                    $nombran[$nombre] = true;
                }
            }
        }
        $this->assertSame(['StudioLabEngineChecks.php'], array_keys($nombran));
    }

    public function test_ningun_fichero_nombra_la_configuracion_ni_la_clave_del_motor(): void
    {
        foreach (self::ficheros() as $nombre => $ruta) {
            $todo = (string) file_get_contents($ruta);
            foreach (self::AJENO as $ajeno) {
                $this->assertStringNotContainsStringIgnoringCase($ajeno, $todo, "{$nombre} nombra {$ajeno}");
            }
        }
    }

    public function test_la_red_y_la_configuracion_solo_donde_toca(): void
    {
        foreach (self::ficheros() as $nombre => $ruta) {
            $usos = self::uses($ruta);
            if ($nombre !== 'StudioLabOpenAiClient.php') {
                $this->assertNotContains('Http', $usos, "{$nombre} usa la red");
                $this->assertNotContains('Guzzle', $usos, "{$nombre} usa la red");
            }
            $lecturas = self::configReads($ruta);
            $esperadas = match ($nombre) {
                'StudioLabConfig.php' => ["'ultron_studio.lab'"],
                'StudioLabOpenAiClient.php' => ["'ultron_studio.lab.openai.api_key'", "'ultron_studio.lab.openai.base_url'"],
                default => [],
            };
            $this->assertSame($esperadas, $lecturas, "{$nombre} lee la configuración");
            foreach (self::IMPURO as $impuro) {
                $this->assertNotContains($impuro, $usos, "{$nombre} usa {$impuro}");
            }
        }
    }

    /**
     * Los nombres usados como función o como clase con `::`/`new` (sin
     * métodos, constantes de clase ni declaraciones).
     *
     * @return list<string>
     */
    private static function uses(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (! in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $antes = self::previous($tokens, $i);
            $despues = self::next($tokens, $i);
            if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }
            $corto = substr((string) strrchr('\\'.$t[1], '\\'), 1);
            if (($despues !== null && in_array($despues[1], ['(', '::'], true)) || ($antes !== null && $antes[0] === T_NEW)) {
                $out[] = $corto;
            }
            if ($antes !== null && $antes[0] === T_USE && (str_contains($t[1], 'GuzzleHttp') || str_contains($t[1], 'Facades\\Http'))) {
                $out[] = str_contains($t[1], 'GuzzleHttp') ? 'Guzzle' : 'Http';
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> las claves literales que se leen con `config(...)` */
    private static function configReads(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && strtolower($t[1]) === 'config' && (self::next($tokens, $i)[1] ?? null) === '(') {
                $antes = self::previous($tokens, $i);
                if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }
                $j = $i + 1;
                while (isset($tokens[$j]) && $tokens[$j][1] !== '(') {
                    $j++;
                }
                $arg = self::next($tokens, $j);
                $out[] = $arg !== null && $arg[0] === T_CONSTANT_ENCAPSED_STRING ? $arg[1] : '(no literal)';
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @return list<array{0:int,1:string}> */
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
    private static function previous(array $tokens, int $i): ?array
    {
        return $tokens[$i - 1] ?? null;
    }

    /** @param  list<array{0:int,1:string}>  $tokens */
    private static function next(array $tokens, int $i): ?array
    {
        return $tokens[$i + 1] ?? null;
    }
}
