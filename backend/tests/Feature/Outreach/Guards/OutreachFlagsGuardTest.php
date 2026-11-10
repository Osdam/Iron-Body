<?php

namespace Tests\Feature\Outreach\Guards;

use Tests\TestCase;

/**
 * TODO NACE APAGADO (contrato H9, §3 protección 1, §9.1; SEC9-a). Los tres
 * ficheros de configuración del H9 se evalúan SIN sus variables en el entorno,
 * sea cual sea el entorno con el que corra la suite: ninguna bandera `true`,
 * ningún techo ni presupuesto distinto de 0, el modo `off`, la puerta de
 * simulación en `required`, la ventana del contrato. Y el programador no
 * programa ninguna orden `outreach:*` fuera del bloque bajo su bandera (hoy
 * no hay bloque: L9-46 lo deja comentado hasta que B9-run registre las órdenes).
 */
class OutreachFlagsGuardTest extends TestCase
{
    /** Las variables de cada fichero, por prefijo (todo lo que empiece así se quita del entorno). */
    private const PREFIXES = [
        'config/outreach.php' => ['COMMERCIAL_AUTOMATION_', 'OUTREACH_'],
        'config/ultron_studio.php' => ['ULTRON_STUDIO_STRATEGIES_'],
        'config/ultron_studio_stream.php' => ['ULTRON_STUDIO_STREAM_'],
    ];

    /** Las claves de §9.1 que tienen que existir con su defecto exacto (una clave que desaparezca o cambie de defecto cae aquí). */
    private const DEFAULTS = [
        'outreach' => [
            'enabled' => false, 'mode' => 'off', 'scheduler.enabled' => false, 'monitor.enabled' => false,
            'assisted.enabled' => false, 'assisted.daily_cap' => 0,
            'whatsapp.enabled' => false, 'whatsapp.daily_cap' => 0, 'whatsapp.daily_budget_usd' => '0',
            'voice.enabled' => false, 'classifier.enabled' => false, 'own_hours' => '',
        ],
        'ultron_studio.strategies' => [
            'enabled' => false, 'publishing_enabled' => false, 'window' => '07:00-19:00', 'canary_min_hours' => 24, 'canary_min_touches' => 3, 'simulation_gate' => 'required',
        ],
        'ultron_studio_stream' => ['enabled' => false],
    ];

    /** Lo que el bloque del programador puede programar cuando exista: una orden `outreach:*` con argumentos literales. */
    private const SCHEDULER_STATEMENT = "/^Schedule::command\\('outreach:[a-z-]+( --[a-z-]+(=[^' ]+)?)*'\\)(->[A-Za-z]+\\(('[^']*'|\\d+)?(,('[^']*'|\\d+))*\\))*$/";

    private const SCHEDULER_FLAG = "'outreach.scheduler.enabled'";

    public function test_las_tres_configuraciones_nacen_apagadas_y_en_cero_sin_variables_en_el_entorno(): void
    {
        $outreach = $this->configWithout('config/outreach.php', self::PREFIXES['config/outreach.php']);
        $studio = $this->configWithout('config/ultron_studio.php', self::PREFIXES['config/ultron_studio.php']);
        $stream = $this->configWithout('config/ultron_studio_stream.php', self::PREFIXES['config/ultron_studio_stream.php']);

        $this->assertAllOff($outreach, 'outreach');
        $this->assertAllOff($studio['strategies'], 'ultron_studio.strategies');
        $this->assertAllOff($stream, 'ultron_studio_stream');

        foreach (self::DEFAULTS['outreach'] as $clave => $defecto) {
            $this->assertSame($defecto, data_get($outreach, $clave), "outreach.{$clave}");
        }
        foreach (self::DEFAULTS['ultron_studio.strategies'] as $clave => $defecto) {
            $this->assertSame($defecto, data_get($studio['strategies'], $clave), "ultron_studio.strategies.{$clave}");
        }
        foreach (self::DEFAULTS['ultron_studio_stream'] as $clave => $defecto) {
            $this->assertSame($defecto, data_get($stream, $clave), "ultron_studio_stream.{$clave}");
        }
    }

    /** Solo `true`, `1`, `on` y `yes` encienden; `"false"`, `"off"`, `"0"`, `"sí"` y la basura no (protección 1; M-1 del H5). */
    public function test_solo_los_literales_verdaderos_encienden_y_el_modo_desconocido_es_off(): void
    {
        foreach (['COMMERCIAL_AUTOMATION_ENABLED', 'COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED', 'COMMERCIAL_AUTOMATION_VOICE_ENABLED'] as $variable) {
            foreach (['false', 'off', '0', 'no', 'sí', 'enabled', 'verdadero', 'si', ''] as $valor) {
                $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], [$variable => $valor]);
                $this->assertAllOff($config, "outreach con {$variable}={$valor}");
            }
        }
        // Los cuatro literales del contrato (el mismo `filter_var` que config/voice.php: sin distinguir mayúsculas y recortado).
        foreach (['on', 'yes', 'true', '1', 'TRUE', ' yes '] as $valor) {
            $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], ['COMMERCIAL_AUTOMATION_ENABLED' => $valor]);
            $this->assertTrue($config['enabled'], "«{$valor}» enciende la maestra");
        }
        foreach (['canary', 'on', 'true', '1', 'Shadow mode', 'live,shadow', 'sombra'] as $modo) {
            $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], ['COMMERCIAL_AUTOMATION_MODE' => $modo]);
            $this->assertSame('off', $config['mode'], "el modo «{$modo}» es off");
        }
        // El modo se normaliza (minúsculas, recortado): `LIVE ` es `live`; lo que no sea off/shadow/live es off.
        $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], ['COMMERCIAL_AUTOMATION_MODE' => ' LIVE ']);
        $this->assertSame('live', $config['mode'], 'live, recortado y en minúsculas, es live');
        $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], ['COMMERCIAL_AUTOMATION_MODE' => 'shadow']);
        $this->assertSame('shadow', $config['mode']);

        // Un cupo que no es un entero no deja «cualquier cosa»: el defecto (0); un importe ilegible, '0'.
        $config = $this->configWith('config/outreach.php', self::PREFIXES['config/outreach.php'], [
            'COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP' => 'mucho', 'COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP' => '-5', 'COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD' => '1e9',
        ]);
        $this->assertSame(0, $config['assisted']['daily_cap']);
        $this->assertSame(0, $config['whatsapp']['daily_cap']);
        $this->assertSame('0', $config['whatsapp']['daily_budget_usd']);

        // La puerta de simulación: cualquier cosa que no sea `waived` es `required`.
        $studio = $this->configWith('config/ultron_studio.php', self::PREFIXES['config/ultron_studio.php'], ['ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE' => 'off']);
        $this->assertSame('required', $studio['strategies']['simulation_gate']);
    }

    /**
     * El programador (L9-46): ninguna orden `outreach:*` programada fuera de
     * un `if (config('outreach.scheduler.enabled'))` con su bloque, sin `else`,
     * y dentro solo órdenes `outreach:*` con argumentos literales. Hoy el
     * bloque no existe (ni la bandera ni las órdenes aparecen): también vale.
     */
    public function test_ninguna_orden_outreach_se_programa_fuera_del_bloque_bajo_bandera(): void
    {
        $tokens = $this->significantTokens(base_path('routes/console.php'));
        $ordenes = [];
        $bandera = null;
        foreach ($tokens as $i => $t) {
            if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (str_starts_with($t[1], "'outreach:")) {
                $ordenes[] = $i;
            }
            if ($t[1] === self::SCHEDULER_FLAG) {
                $this->assertNull($bandera, 'La bandera del programador del H9 aparece dos veces.');
                $bandera = $i;
            }
        }

        if ($ordenes === [] && $bandera === null) {
            fwrite(STDERR, "\n[SEC9-a] routes/console.php todavía no programa nada del H9 (L9-46: el bloque llega con B9-run)\n");
            $this->assertStringNotContainsString('outreach', $this->codeOnly(base_path('routes/console.php')), 'El programador nombra outreach sin bandera ni orden.');

            return;
        }
        $this->assertNotNull($bandera, 'Hay órdenes outreach:* programadas sin la bandera outreach.scheduler.enabled.');

        // Es la condición de un `if`, con un bloque entre llaves y sin `else`.
        for ($i = $bandera - 1; $i >= 0 && ! (is_array($tokens[$i]) && $tokens[$i][0] === T_IF); $i--) {
            $this->assertNotContains($tokens[$i], [';', '{', '}'], 'La bandera del programador no es la condición de un if.');
        }
        $this->assertGreaterThanOrEqual(0, $i);
        for ($abre = $bandera + 1; isset($tokens[$abre]) && $tokens[$abre] !== '{'; $abre++) {
            $this->assertNotSame(';', $tokens[$abre], 'El if de la bandera no lleva un bloque entre llaves.');
        }
        $cierra = null;
        for ($j = $abre, $nivel = 0; isset($tokens[$j]); $j++) {
            $abreOtra = $tokens[$j] === '{' || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            $nivel += $abreOtra ? 1 : ($tokens[$j] === '}' ? -1 : 0);
            if ($nivel === 0) {
                $cierra = $j;
                break;
            }
        }
        $this->assertNotNull($cierra, 'El bloque de la bandera no se cierra.');
        $siguiente = $tokens[$cierra + 1] ?? null;
        $this->assertFalse(is_array($siguiente) && in_array($siguiente[0], [T_ELSE, T_ELSEIF], true), 'El bloque de la bandera lleva un else.');

        foreach ($ordenes as $indice) {
            $this->assertTrue($indice > $abre && $indice < $cierra, 'Una orden outreach:* está programada fuera del bloque de la bandera.');
        }
        $sentencias = [''];
        for ($j = $abre + 1; $j < $cierra; $j++) {
            if ($tokens[$j] === ';') {
                $sentencias[] = '';

                continue;
            }
            $sentencias[array_key_last($sentencias)] .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $sentencias = array_values(array_filter($sentencias, static fn (string $s): bool => $s !== ''));
        $ajenas = array_values(array_filter($sentencias, static fn (string $s): bool => preg_match(self::SCHEDULER_STATEMENT, $s) !== 1));
        $this->assertSame([], $ajenas, "El bloque de la bandera del H9 programa algo que no es una orden outreach:* con argumentos literales:\n".implode("\n", $ajenas));
        $this->assertGreaterThanOrEqual(1, count($sentencias));
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $arbol */
    private function assertAllOff(array $arbol, string $donde): void
    {
        array_walk_recursive($arbol, function (mixed $hoja, string|int $clave) use ($donde): void {
            if (is_bool($hoja)) {
                $this->assertFalse($hoja, "{$donde}.{$clave} nace encendida (protección 1)");
            }
            if ((is_int($hoja) || is_float($hoja) || (is_string($hoja) && is_numeric(trim($hoja)))) && preg_match('/daily|budget|cap(_|$)/', (string) $clave) === 1) {
                $this->assertEquals(0.0, (float) $hoja, "{$donde}.{$clave} nace con techo o presupuesto (protección 1)");
            }
        });
    }

    /**
     * @param  list<string>  $prefijos
     * @return array<string, mixed>
     */
    private function configWithout(string $fichero, array $prefijos): array
    {
        return $this->configWith($fichero, $prefijos, []);
    }

    /**
     * El fichero evaluado sin las variables de los prefijos y con las que se
     * indiquen puestas a propósito.
     *
     * @param  list<string>  $prefijos
     * @param  array<string, string>  $puestas
     * @return array<string, mixed>
     */
    private function configWith(string $fichero, array $prefijos, array $puestas): array
    {
        $nombres = [];
        foreach (array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys((array) getenv()), array_keys($puestas)) as $nombre) {
            foreach ($prefijos as $prefijo) {
                if (is_string($nombre) && str_starts_with($nombre, $prefijo)) {
                    $nombres[$nombre] = true;
                }
            }
        }
        $antes = [];
        foreach (array_keys($nombres) as $nombre) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
        }
        foreach ($puestas as $nombre => $valor) {
            $_SERVER[$nombre] = $valor;
            $_ENV[$nombre] = $valor;
            putenv("{$nombre}={$valor}");
        }

        try {
            return require base_path($fichero);
        } finally {
            foreach ($puestas as $nombre => $valor) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
            }
            foreach ($antes as $nombre => [$servidor, $entorno, $proceso]) {
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

    /** @return list<array{0:int,1:string,2:int}|string> */
    private function significantTokens(string $ruta): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    private function codeOnly(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
