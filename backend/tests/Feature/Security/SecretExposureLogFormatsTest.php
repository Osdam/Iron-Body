<?php

namespace Tests\Feature\Security;

use App\Console\Commands\CheckSecretExposure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SC-12 del cierre (B-T17): el rastreo de LOGS de
 * `security:check-secret-exposure` solo veía `CLAVE=valor` al principio de una
 * línea. Ahora ve también `CLAVE=valor` en mitad de una línea, `"CLAVE":"valor"`
 * (JSON, también escapado dentro de otro JSON) y los volcados de PHP
 * (`'CLAVE' => 'valor'`, `[CLAVE] => valor`), sin falsos positivos con valores
 * vacíos o de ejemplo, ni con claves que solo se parecen; una línea que no se
 * puede revisar no pasa por limpia; y su salida dice qué formatos NO mira.
 *
 * Como SecretExposureVoiceKeysTest: cada prueba en una base de la aplicación
 * PROPIA y desechable (sus `storage/logs` y `public`); nada en el árbol real.
 * Los valores son centinelas que nunca pueden salir por la orden.
 */
class SecretExposureLogFormatsTest extends TestCase
{
    private const FORMATOS = [
        'CLAVE=valor al principio de la línea (también con espacios, comillas o export)',
        'CLAVE=valor en mitad de la línea (pegado al =, o entre comillas)',
        '"CLAVE":"valor" (JSON, también escapado dentro de otro JSON)',
        "'CLAVE' => 'valor' y [CLAVE] => valor (volcados de PHP)",
    ];

    private const NO_MIRA = [
        'en mitad de la línea, CLAVE = valor sin comillas y separado del = (se confundiría con el texto que sigue)',
        'un valor partido en varias líneas o codificado (base64, URL, \\u…)',
        'CLAVE: valor (YAML, cabeceras) ni Authorization: Bearer …',
        'una clave en minúsculas, con otro nombre o con un prefijo propio (PUSHER_APP_KEY no es APP_KEY)',
        'otros ficheros: rotados (*.log.1), comprimidos (*.gz) o fuera de storage/logs',
    ];

    private string $base;

    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->base = sys_get_temp_dir().'/sc12-logs-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->base.'/storage/logs', 0700);
        File::ensureDirectoryExists($this->base.'/public', 0700);
        $this->app->setBasePath($this->base);
        $this->assertSame($this->base.'/storage/logs', storage_path('logs'), 'la orden mira la base desechable, no el árbol real');
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** @param  list<string>  $lineas */
    private function log(string $nombre, array $lineas, int $modo = 0664): string
    {
        $ruta = $this->base.'/storage/logs/'.$nombre;
        File::put($ruta, implode("\n", $lineas)."\n");
        chmod($ruta, $modo);
        clearstatcache(true, $ruta);

        return $ruta;
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string, 3: string} el código, el JSON, la salida JSON y la de texto */
    private function exposure(): array
    {
        $codigo = Artisan::call('security:check-secret-exposure', ['--json' => true]);
        $json = (string) Artisan::output();
        Artisan::call('security:check-secret-exposure');

        return [$codigo, json_decode($json, true, 64, JSON_THROW_ON_ERROR), $json, (string) Artisan::output()];
    }

    public function test_en_un_log_ve_la_clave_en_mitad_de_linea_en_json_y_en_los_volcados_de_php(): void
    {
        $centinelas = [
            'ELEVENLABS_API_KEY' => ['sk_centinela_json_1a', '[2026-11-10 10:00:00] production.ERROR: fallo {"exception":"[object] (RuntimeException)","env":{"ELEVENLABS_API_KEY":"sk_centinela_json_1a","APP_ENV":"production"}} []'],
            'TWILIO_VOICE_SIGNING_TOKEN' => ['tok_centinela_medio_2b', '[2026-11-10 10:00:01] production.INFO: arranque con TWILIO_VOICE_SIGNING_TOKEN=tok_centinela_medio_2b colas=3'],
            'DB_PASSWORD' => ['pw_centinela_escapado_3c', '{"message":"payload","context":{"raw":"{\"DB_PASSWORD\":\"pw_centinela_escapado_3c\"}"}}'],
            'META_APP_SECRET' => ['ms_centinela_export_4d', "    'META_APP_SECRET' => 'ms_centinela_export_4d',"],
            'N8N_WEBHOOK_SECRET' => ['n8n_centinela_printr_5e', '    [N8N_WEBHOOK_SECRET] => n8n_centinela_printr_5e'],
            'APP_KEY' => ['base64:centinelaDocker6f==', '[2026-11-10 10:00:02] local.DEBUG: inspect {"Env":["PATH=/usr/bin","APP_KEY=base64:centinelaDocker6f=="]}'],
            'OPENAI_API_KEY' => ['sk-centinela-bonito-7g', '    "OPENAI_API_KEY": "sk-centinela-bonito-7g",'],
            'MAIL_PASSWORD' => ['mail_centinela_8h', '+ env -i REDIS_PASSWORD= MAIL_PASSWORD=mail_centinela_8h php artisan queue:work'],
            'LIVEKIT_API_SECRET' => ['lk centinela con espacios 9i', 'config cargada: LIVEKIT_API_SECRET="lk centinela con espacios 9i" listo'],
            'ADMIN_API_TOKEN' => ['adm_centinela_igual_10j', 'ajuste del panel: ADMIN_API_TOKEN = "adm_centinela_igual_10j" (con espacios)'],
            'AWS_SECRET_ACCESS_KEY' => ['aws_centinela_inicio_11k', 'AWS_SECRET_ACCESS_KEY = aws_centinela_inicio_11k'],
        ];
        $ruta = $this->log('laravel.log', array_merge(['[2026-11-10 09:59:59] production.INFO: sin nada'], array_column($centinelas, 1)));

        [$codigo, $r, $json, $texto] = $this->exposure();
        $esperadas = array_keys($centinelas);
        sort($esperadas);
        $this->assertSame(1, $codigo);
        $this->assertSame([['issue' => 'log legible por cualquier usuario con credenciales', 'permissions' => '0664', 'path' => $ruta, 'keys' => $esperadas]], $r['findings']);
        foreach ($centinelas as $clave => [$valor]) {
            $this->assertStringContainsString($clave, $texto, "{$clave}: el nombre sale, para saber qué rotar");
            $this->assertStringNotContainsString($valor, $json.$texto, "{$clave}: el VALOR no sale nunca");
        }
        $this->assertStringNotContainsString('centinela', $json.$texto);

        // Solo para su dueño y su grupo: ya no lo lee cualquiera.
        chmod($ruta, 0640);
        clearstatcache(true, $ruta);
        $this->assertSame([0, []], [$this->exposure()[0], $this->exposure()[1]['findings']]);
    }

    public function test_sin_falsos_positivos_con_valores_vacios_de_ejemplo_o_claves_que_solo_se_parecen(): void
    {
        $this->log('voice-2026-11-10.log', [
            // Vacíos y de ejemplo, en cada forma.
            '{"APP_KEY":"","DB_PASSWORD":"null","ELEVENLABS_API_KEY":null,"META_APP_SECRET":"changeme","OPENAI_API_KEY":"your_key","WOMPI_PRIVATE_KEY":"xxx"}',
            '{"message":"{\"APP_KEY\":\"\",\"TWILIO_AUTH_TOKEN\":\"placeholder\"}"}',
            'run APP_KEY= DB_PASSWORD="" META_APP_SECRET=\'\' TWILIO_AUTH_TOKEN=null REDIS_PASSWORD=xxx ADMIN_API_TOKEN=tu_token LABSMOBILE_TOKEN=',
            // Una clave vacía seguida de texto: «y» no es su valor.
            '[2026-11-10 10:00:03] production.INFO: vacías ELEVENLABS_API_KEY= y DB_PASSWORD= , FACTUS_PASSWORD= (sin valor)',
            "    'APP_KEY' => '',",
            "    'DB_PASSWORD' => NULL,",
            '    [MAIL_PASSWORD] => ',
            '    [REDIS_PASSWORD] => null',
            // Solo NOMBRES, sin valor.
            '[2026-11-10 10:00:00] production.INFO: voice.doctor faltan ELEVENLABS_API_KEY y TWILIO_VOICE_SIGNING_TOKEN',
            '{"missing":["APP_KEY","DB_PASSWORD"],"keys":["META_APP_SECRET"],"check":"ELEVENLABS_WEBHOOK_SECRET"}',
            // Claves que no son las vigiladas: prefijo propio, minúsculas, una variable, ids y banderas.
            'PUSHER_APP_KEY=pk_live_parecida VITE_PUSHER_APP_KEY=otra X_DB_PASSWORD=nada MY_OPENAI_API_KEY=nada',
            '{"app_key":"base64:minusculas","api_key":"x","db_password":"y","PUSHER_APP_KEY":"z"}',
            '$APP_KEY=una_variable_php; $DB_PASSWORD = "otra"',
            'ELEVENLABS_REGION=default ELEVENLABS_AGENT_ID=agent_x TWILIO_VOICE_ACCOUNT_SID=AC0000 VOICE_WEBHOOK_BASE_URL=https://api.ejemplo.test',
            '{"ELEVENLABS_TOOL_SECRET_REF":"sec_ref_x","ELEVENLABS_POST_CALL_WEBHOOK_REF":"wh_x"}',
            // Comparaciones y flechas sin comillas: no son asignaciones.
            'if APP_KEY == esperado || DB_PASSWORD==otro then APP_KEY => nada',
        ]);

        [$codigo, $r] = $this->exposure();
        $this->assertSame([0, []], [$codigo, $r['findings']], 'ni vacíos, ni ejemplos, ni nombres sueltos, ni claves parecidas');
    }

    /**
     * Lo que la salida dice que NO mira, de verdad no lo mira: la lista y la
     * orden van juntas (si un día se cubre algo, cambian las dos y esta prueba).
     */
    public function test_lo_que_dice_que_no_mira_de_verdad_no_lo_mira(): void
    {
        $this->log('laravel.log', [
            '"APP_KEY":',
            '    "base64:centinela_partida_en_dos_lineas"',
            'APP_KEY: base64:centinela_en_yaml',
            'Authorization: Bearer centinela_cabecera',
            'app_key=centinela_minusculas',
            'APP_KEY%3Dcentinela_url',
            'texto con FACTUS_PASSWORD = centinela_separado_del_igual',
        ]);
        $this->log('laravel.log.1', ['APP_KEY=centinela_rotado'], 0664);
        File::put($this->base.'/storage/logs/laravel-2026-11-09.log.gz', 'APP_KEY=centinela_comprimido');
        chmod($this->base.'/storage/logs/laravel-2026-11-09.log.gz', 0664);
        File::ensureDirectoryExists($this->base.'/storage/logs/viejos', 0755);
        File::put($this->base.'/storage/logs/viejos/laravel.log', 'APP_KEY=centinela_subdirectorio');
        chmod($this->base.'/storage/logs/viejos/laravel.log', 0664);

        [$codigo, $r] = $this->exposure();
        $this->assertSame([0, []], [$codigo, $r['findings']], 'lo documentado como fuera de alcance');
    }

    public function test_la_salida_dice_que_formatos_mira_y_cuales_no_tambien_en_verde(): void
    {
        [$codigo, $r, , $texto] = $this->exposure();
        $this->assertSame(0, $codigo);
        $this->assertSame(['files' => 'storage/logs/*.log', 'formats' => self::FORMATOS, 'notCovered' => self::NO_MIRA], $r['logScan']);
        $this->assertStringContainsString('Ningún fichero con credenciales está expuesto.', $texto);
        $this->assertStringContainsString('Logs: solo storage/logs/*.log legibles por cualquiera. Reconoce: '.implode('; ', self::FORMATOS).'.', $texto);
        $this->assertStringContainsString('NO mira: '.implode('; ', self::NO_MIRA).'.', $texto);

        // Con un hallazgo, igual.
        $this->log('channel-2026-11-10.log', ['{"DB_PASSWORD":"centinela_con_hallazgo"}']);
        [$codigo, $r, , $texto] = $this->exposure();
        $this->assertSame([1, ['DB_PASSWORD']], [$codigo, $r['findings'][0]['keys']]);
        $this->assertSame(self::NO_MIRA, $r['logScan']['notCovered']);
        $this->assertStringContainsString('NO mira: '.implode('; ', self::NO_MIRA).'.', $texto);
        $this->assertStringNotContainsString('centinela', $texto);
    }

    public function test_una_linea_que_no_se_puede_revisar_no_pasa_por_limpia(): void
    {
        $ruta = $this->log('voice-2026-11-11.log', ['[2026-11-11 00:00:00] production.INFO: arranque', '[2026-11-11 00:00:01] production.INFO: vacías ELEVENLABS_API_KEY= y DB_PASSWORD=']);
        $mirar = new ReflectionMethod(CheckSecretExposure::class, 'worldReadableLogsWithSecrets');
        $orden = app(CheckSecretExposure::class);

        // PCRE sin JIT y con el límite de retroceso en 1: la línea con claves vigiladas (vacías: limpia) falla al revisarse.
        // (Sin un `=` ni comillas, PCRE la descarta antes de emparejar y no habría fallo que contar.)
        $jit = ini_get('pcre.jit');
        $limite = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $hallazgos = $mirar->invoke($orden);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limite);
        }
        $this->assertSame([['issue' => 'log legible por cualquier usuario que no se pudo revisar entero', 'permissions' => '0664', 'path' => $ruta, 'keys' => [], 'unscannedLines' => 1]], $hallazgos,
            'la línea que no se pudo revisar sale en el hallazgo; la que no lleva ninguna palabra vigilada ni se mira');

        // Con PCRE normal, la misma línea se revisa y está limpia.
        $this->assertSame([], $mirar->invoke($orden));
    }
}
