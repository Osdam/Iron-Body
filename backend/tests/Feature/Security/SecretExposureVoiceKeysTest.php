<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Auditoría B-T17: `security:check-secret-exposure` no vigilaba ninguna clave
 * de la voz (ElevenLabs y Twilio Voice) ni las 13 que H-4 de SEC7 echó en
 * falta, y no miraba los logs. Un fichero PARCIAL legible por «otros» (sin
 * `APP_KEY`), o un log de voz con un volcado del entorno, pasaba como limpio.
 *
 * Cada prueba trabaja en una base de la aplicación PROPIA y desechable (sus
 * ficheros de entorno, `storage/logs` y `public`): nada se escribe en el árbol
 * real, y ninguna otra prueba ve los «secretos» de esta. Los valores son
 * centinelas que nunca pueden salir por la orden: solo los NOMBRES.
 */
class SecretExposureVoiceKeysTest extends TestCase
{
    /** Las claves secretas de la voz de hoy (las familias ELEVENLABS_*, TWILIO_VOICE_* y VOICE_WEBHOOK_*). */
    private const VOICE_SECRETS = ['ELEVENLABS_API_KEY', 'ELEVENLABS_WEBHOOK_SECRET', 'ELEVENLABS_TOOL_SECRET', 'ELEVENLABS_INITIATION_SECRET',
        'TWILIO_VOICE_API_KEY_SECRET', 'TWILIO_VOICE_SIGNING_TOKEN'];

    /** Las 13 de H-4 de SEC7 (ElevenLabs entra por su familia, arriba). */
    private const SEC7_H4 = ['ADMIN_API_TOKEN', 'AUTOMATION_INTERNAL_SECRET', 'DB_OWNER_PASSWORD', 'MAIL_PASSWORD', 'META_EMBEDDED_SIGNUP_APP_SECRET',
        'N8N_WEBHOOK_SECRET', 'REDIS_PASSWORD', 'ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET', 'FACTUS_WEBHOOK_SECRET', 'AWS_SECRET_ACCESS_KEY', 'LABSMOBILE_TOKEN',
        'MEMBER_REGISTRATION_TOKEN'];

    private string $base;

    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->base = sys_get_temp_dir().'/h8e-secretos-'.bin2hex(random_bytes(6));
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

    private function plant(string $relative, string $contents, int $mode): string
    {
        $path = $this->base.'/'.$relative;
        File::put($path, $contents);
        chmod($path, $mode);
        clearstatcache(true, $path);

        return $path;
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} el código, el JSON y la salida de texto */
    private function exposure(): array
    {
        $codigo = Artisan::call('security:check-secret-exposure', ['--json' => true]);
        $json = (string) Artisan::output();
        Artisan::call('security:check-secret-exposure');

        return [$codigo, json_decode($json, true, 64, JSON_THROW_ON_ERROR), $json.Artisan::output()];
    }

    public function test_un_fichero_parcial_legible_con_una_sola_clave_de_voz_o_de_sec7_se_detecta(): void
    {
        foreach ([...self::VOICE_SECRETS, ...self::SEC7_H4, 'VOICE_WEBHOOK_SIGNING_SECRET'] as $i => $clave) {
            $valor = 'centinela-'.$i.'-'.bin2hex(random_bytes(8));
            $fichero = $this->plant('.env.voz', "# respaldo parcial, sin APP_KEY\n{$clave}={$valor}\n", 0644);

            [$codigo, $r, $salida] = $this->exposure();
            $this->assertSame(1, $codigo, "{$clave}: un fichero parcial legible por cualquiera");
            $this->assertSame([['issue' => 'legible por cualquier usuario', 'permissions' => '0644', 'path' => $fichero, 'keys' => [$clave]]], $r['findings'], $clave);
            $this->assertStringContainsString($clave, $salida, "{$clave}: el nombre sale, para saber qué rotar");
            $this->assertStringNotContainsString($valor, $salida, "{$clave}: el VALOR no sale nunca");

            chmod($fichero, 0600);
            clearstatcache(true, $fichero);
            $this->assertSame(0, $this->exposure()[0], "{$clave}: en 600 no lo lee nadie más");
        }
    }

    public function test_con_export_entre_comillas_y_repetida_tras_un_ejemplo_tambien_cuenta(): void
    {
        $this->plant('.env.bak', "export ELEVENLABS_TOOL_SECRET=\"tool-centinela-1\"\nTWILIO_VOICE_SIGNING_TOKEN=null\nTWILIO_VOICE_SIGNING_TOKEN='tok-centinela-2'\n", 0664);

        [$codigo, $r, $salida] = $this->exposure();
        $this->assertSame(1, $codigo);
        $this->assertSame(['ELEVENLABS_TOOL_SECRET', 'TWILIO_VOICE_SIGNING_TOKEN'], $r['findings'][0]['keys']);
        foreach (['tool-centinela-1', 'tok-centinela-2'] as $valor) {
            $this->assertStringNotContainsString($valor, $salida);
        }
    }

    public function test_los_ids_la_region_la_base_y_las_banderas_de_la_voz_no_son_secretos(): void
    {
        // Lo que lleva un fichero de ejemplo con valores que NO son secretos, y los secretos vacíos o de ejemplo.
        $this->plant('.env.ejemplo-voz', implode("\n", [
            'ELEVENLABS_ENABLED=true', 'ELEVENLABS_REGION=default', 'ELEVENLABS_TIMEOUT_SECONDS=10', 'ELEVENLABS_AGENT_ID=agent_verify_0001',
            'ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_0001', 'ELEVENLABS_TOOL_SECRET_REF=sec_tool_0001', 'ELEVENLABS_INITIATION_SECRET_REF=sec_init_0001',
            'ELEVENLABS_POST_CALL_WEBHOOK_REF=wh_0001', 'TWILIO_VOICE_ENABLED=false', 'TWILIO_VOICE_ACCOUNT_SID=AC'.str_repeat('0', 32),
            'TWILIO_VOICE_API_KEY_SID=SK'.str_repeat('0', 32), 'TWILIO_VOICE_DAILY_CALL_CAP=0', 'VOICE_WEBHOOK_BASE_URL=https://api.ejemplo.test',
            'ELEVENLABS_API_KEY=', 'ELEVENLABS_WEBHOOK_SECRET=', 'TWILIO_VOICE_API_KEY_SECRET=changeme', 'TWILIO_VOICE_SIGNING_TOKEN=null',
            'MAIL_PASSWORD=null', 'REDIS_PASSWORD=null', 'AWS_SECRET_ACCESS_KEY=', 'ADMIN_API_TOKEN=your_token',
        ])."\n", 0644);
        // Y los ficheros de ejemplo DEL REPOSITORIO, legibles por todos como en el servidor: siguen limpios.
        foreach (['.env.example', '.env.production.example'] as $ejemplo) {
            if (is_file($this->originalBase.'/'.$ejemplo)) {
                $this->plant($ejemplo, (string) file_get_contents($this->originalBase.'/'.$ejemplo), 0644);
            }
        }

        [$codigo, $r] = $this->exposure();
        $this->assertSame([0, []], [$codigo, $r['findings']], 'un ejemplo sin valores de verdad no es un hallazgo');
    }

    public function test_una_clave_de_voz_filtrada_en_un_log_legible_se_detecta_sin_ensenar_su_valor(): void
    {
        $lineas = [
            '[2026-11-10 10:00:00] production.INFO: voice.doctor faltan ELEVENLABS_API_KEY y TWILIO_VOICE_SIGNING_TOKEN',
            '{"message":"voice.reconcile","context":{"api_key":"[redacted]"},"level":200}',
            '[2026-11-10 10:01:00] production.ERROR: Dotenv dump (una traza que volcó el entorno):',
            'APP_NAME=IronBody',
            'ELEVENLABS_REGION=default',
            'ELEVENLABS_API_KEY=sk_centinela_voz_9f3a',
            'export TWILIO_VOICE_SIGNING_TOKEN="tok_centinela_voz_77c1"',
            'TWILIO_VOICE_API_KEY_SECRET=',
            '[2026-11-10 10:01:01] production.INFO: sigue',
        ];
        $log = $this->plant('storage/logs/voice-2026-11-10.log', implode("\n", $lineas)."\n", 0664);
        // Un log legible SIN credenciales no es un hallazgo (los canales channel y voice nacen en 0664 a propósito).
        $this->plant('storage/logs/channel-2026-11-10.log', $lineas[0]."\n".$lineas[1]."\n", 0664);

        [$codigo, $r, $salida] = $this->exposure();
        $this->assertSame(1, $codigo);
        $this->assertSame([['issue' => 'log legible por cualquier usuario con credenciales', 'permissions' => '0664', 'path' => $log,
            'keys' => ['ELEVENLABS_API_KEY', 'TWILIO_VOICE_SIGNING_TOKEN']]], $r['findings']);
        foreach (['sk_centinela_voz_9f3a', 'tok_centinela_voz_77c1', 'centinela'] as $valor) {
            $this->assertStringNotContainsString($valor, $salida, 'el valor filtrado no se repite en la salida');
        }

        // El mismo log solo para su dueño y su grupo: ya no lo lee cualquiera.
        chmod($log, 0640);
        clearstatcache(true, $log);
        $this->assertSame([0, []], [$this->exposure()[0], $this->exposure()[1]['findings']]);
    }
}
