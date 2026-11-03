<?php

namespace Tests\Feature\Voice;

use App\Http\Middleware\LimitRequestBodySize;
use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Services\Voice\NullVoiceProvider;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La frontera de la voz con la aplicación arrancada (contrato H6 §I9.5, §I1
 * P2 y §I6.4; REESCRITA por SEC8 con el contrato del Hito 8: D8-2, D8-16,
 * D8-20 y las protecciones 1 a 3): lo que la prueba estática no puede ver.
 * Quien implementa no la toca; si cae, avisa al lead.
 *
 * LO QUE SE PROTEGE.
 *  - En la tabla de rutas REAL la voz vive en sus prefijos: lo que cuelga de
 *    `api/webhooks/voice/`, `api/voice/` y `api/admin/voice/` lo atienden los
 *    controladores de la telefonía, y esos controladores no atienden nada más.
 *    Ninguna ruta la atiende una clase de `App\Services\Voice`, de la
 *    telefonía o de Twilio. Las públicas son, como mucho, las cuatro de D8-16,
 *    solo POST, con su tope de cuerpo y su limitador.
 *  - SIN CREDENCIAL NO PASA NADA: cada entrada pública de voz que exista,
 *    llamada sin credencial, con una credencial basura o con una VACÍA (con
 *    los secretos sin configurar, el `hash_equals('', '')` que abriría la
 *    puerta, y con ellos configurados), responde 401 o 403, no escribe en la
 *    base y no sale a la red.
 *  - Los comandos `voice:*` son los del contrato y, por defecto, ninguno está
 *    programado.
 *  - TODO APAGADO POR DEFECTO (protección 1): `config/voice.php` evaluado sin
 *    ninguna variable de voz deja toda bandera en false y todo techo diario y
 *    presupuesto en 0; lo mismo el bloque de voz de `config/ultron_studio.php`
 *    (con el laboratorio hablado en `fake`).
 *  - Con la configuración por defecto, ninguna entrada pública del paquete sale
 *    a la red, y `marketing_calls` nunca es un origen permitido.
 *  - CON ELEVENLABS CONFIGURADO, COMO PRODUCCIÓN DESDE EL 03-11 (Enmienda 1,
 *    E1-12: clave, agente, secretos y `ELEVENLABS_ENABLED=true`, estado
 *    `ELEVENLABS_UNVERIFIED`/`VERIFIED`): la política sigue cerrada solo por
 *    `calls_disabled_in_code`, el iniciador de salientes no marca, y ni el
 *    doctor sin `--verify` ni las entradas públicas salen a la red. Ninguna
 *    guarda supone `ELEVENLABS_NOT_CONFIGURED` en producción: ese estado es el
 *    de los valores del código, sin variables.
 *  - En `production`, la base de ElevenLabs es la oficial aunque haya una
 *    configurada (protección 3).
 */
class VoiceBoundaryTest extends TestCase
{
    /** Las rutas públicas de D8-16 y su tope de cuerpo (bytes). La del aviso de gasto de la subcuenta no lo fija el contrato: el del Studio. */
    private const PUBLICAS = [
        'api/webhooks/voice/elevenlabs/post-call' => 1048576,
        'api/webhooks/voice/elevenlabs/initiation' => 8192,
        'api/voice/tools/{tool}' => 8192,
        'api/webhooks/voice/twilio/usage-trigger' => 65536,
    ];

    private const PREFIJOS_DE_VOZ = ['api/webhooks/voice/', 'api/voice/', 'api/admin/voice/'];

    private const PREFIJO_CRM = 'api/admin/voice/';

    private const CONTROLADORES_PUBLICOS = 'App\\Http\\Controllers\\Api\\Voice\\';

    private const CONTROLADORES_CRM = 'App\\Http\\Controllers\\Crm\\Voice\\';

    /** Los comandos de voz del contrato: el doctor (H6) y los de D8-20 y D8-21. */
    private const COMANDOS = ['voice:doctor', 'voice:hangup', 'voice:reconcile'];

    /** Cabeceras basura y vacías para las entradas públicas (la del secreto de iniciación la fija B8; se prueban las formas habituales). */
    private const CABECERAS_DE_CREDENCIAL = ['HTTP_AUTHORIZATION', 'HTTP_ELEVENLABS_SIGNATURE', 'HTTP_X_TWILIO_SIGNATURE', 'HTTP_X_ELEVENLABS_SECRET',
        'HTTP_X_INITIATION_SECRET', 'HTTP_X_VOICE_SECRET', 'HTTP_X_WEBHOOK_SECRET', 'HTTP_X_API_KEY'];

    public function test_en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos(): void
    {
        $rutas = Route::getRoutes()->getRoutes();
        $this->assertGreaterThan(100, count($rutas), 'la tabla de rutas está cargada (si no, la prueba no prueba nada)');

        $fallos = [];
        foreach ($rutas as $ruta) {
            $accion = ltrim($ruta->getActionName(), '\\');
            $clase = str_contains($accion, '@') ? (string) strstr($accion, '@', true) : $accion;
            $uri = $ruta->uri();
            $descripcion = implode('|', $ruta->methods()).' '.$uri.' → '.$accion;

            if (preg_match('/^App\\\\Services\\\\(Voice|Telephony|Twilio)\\\\/', $clase) === 1) {
                $fallos[] = "{$descripcion}: una ruta la atiende un servicio";
            }
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/Services\\\\(Voice|Telephony)|ElevenLabs/', $middleware) === 1) {
                    $fallos[] = "{$uri} (middleware {$middleware})";
                }
            }

            $deTelefonia = str_starts_with($clase, self::CONTROLADORES_PUBLICOS) || str_starts_with($clase, self::CONTROLADORES_CRM);
            $enPrefijo = false;
            foreach (self::PREFIJOS_DE_VOZ as $prefijo) {
                $enPrefijo = $enPrefijo || str_starts_with($uri, $prefijo);
            }
            if ($enPrefijo !== $deTelefonia) {
                $fallos[] = "{$descripcion}: los prefijos de voz y los controladores de la telefonía van juntos";
            }
            if (stripos($uri, 'elevenlabs') !== false && ! str_starts_with($uri, 'api/webhooks/voice/elevenlabs/')) {
                $fallos[] = "{$descripcion}: ElevenLabs solo en sus webhooks";
            }
            if (str_starts_with($clase, self::CONTROLADORES_CRM) && ! str_starts_with($uri, self::PREFIJO_CRM)) {
                $fallos[] = "{$descripcion}: el CRM de voz cuelga de api/admin/voice/";
            }
            if (! str_starts_with($clase, self::CONTROLADORES_PUBLICOS)) {
                continue;
            }

            // Una entrada pública: de D8-16, solo POST, con su tope y su limitador.
            if (! array_key_exists($uri, self::PUBLICAS)) {
                $fallos[] = "{$descripcion}: no es una de las cuatro rutas públicas de D8-16";

                continue;
            }
            if (array_values(array_diff($ruta->methods(), ['POST'])) !== []) {
                $fallos[] = "{$descripcion}: solo POST";
            }
            $tope = null;
            $limitador = false;
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, LimitRequestBodySize::class.':')) {
                    $tope = (int) substr($middleware, strlen(LimitRequestBodySize::class) + 1);
                }
                $limitador = $limitador || (is_string($middleware) && (str_starts_with($middleware, 'throttle:') || str_contains($middleware, 'ThrottleRequests')));
            }
            if ($tope === null || $tope < 1 || $tope > self::PUBLICAS[$uri]) {
                $fallos[] = "{$descripcion}: sin LimitRequestBodySize o con un tope mayor que ".self::PUBLICAS[$uri].' bytes (D8-16)';
            }
            if (! $limitador) {
                $fallos[] = "{$descripcion}: sin limitador (D8-16)";
            }
        }

        $this->assertSame([], $fallos, "Rutas de voz fuera de su sitio:\n".implode("\n", $fallos));
    }

    public function test_sin_credencial_ninguna_entrada_publica_de_voz_pasa_ni_escribe_ni_sale_a_la_red(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);
        $escrituras = [];
        DB::listen(static function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|merge)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });

        $publicas = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (str_starts_with(ltrim($ruta->getActionName(), '\\'), self::CONTROLADORES_PUBLICOS) && in_array('POST', $ruta->methods(), true)) {
                $publicas[] = $ruta->uri();
            }
        }

        foreach (['sin configurar' => null, 'configurados' => 'secreto-de-prueba-sec8-'.str_repeat('a', 24)] as $caso => $secreto) {
            $this->secretosDeVoz($secreto);
            foreach ($publicas as $uri) {
                $destino = '/'.str_replace('{tool}', 'consultar_planes', $uri);
                foreach (['sin credencial' => null, 'basura' => 'x', 'vacía' => ''] as $credencial => $valor) {
                    $servidor = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
                    if ($valor !== null) {
                        foreach (self::CABECERAS_DE_CREDENCIAL as $cabecera) {
                            $servidor[$cabecera] = $cabecera === 'HTTP_AUTHORIZATION' ? 'Bearer '.$valor : $valor;
                        }
                    }
                    $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => time(), 'data' => ['agent_id' => 'agent_sec8',
                        'conversation_id' => 'conv_sec8', 'caller_id' => '+15005550006', 'called_number' => '+15005550001', 'call_sid' => 'CA'.str_repeat('0', 32)]]);
                    $respuesta = $this->call('POST', $destino, [], [], [], $servidor, $cuerpo);
                    $this->assertContains($respuesta->status(), [401, 403], "{$destino} con los secretos {$caso} y una credencial {$credencial} responde {$respuesta->status()}: sin credencial válida, 401 o 403 (D8-16)");
                }
            }
        }

        $this->assertSame([], $escrituras, 'una entrada pública de voz escribió sin credencial válida');
        Http::assertNothingSent();
    }

    public function test_los_comandos_de_voz_son_los_del_contrato_y_por_defecto_ninguno_esta_programado(): void
    {
        $comandos = array_values(array_filter(array_keys(Artisan::all()), static fn (string $c): bool => str_starts_with($c, 'voice:')));
        sort($comandos);
        $this->assertContains('voice:doctor', $comandos);
        $this->assertSame([], array_values(array_diff($comandos, self::COMANDOS)), 'un comando de voz que no está en el contrato');

        // Con la configuración por defecto (todo apagado), el programador no lanza ninguno.
        foreach (app(Schedule::class)->events() as $evento) {
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z])voice:/', (string) $evento->command, 'un comando de voz programado con las banderas por defecto');
        }
    }

    public function test_todo_apagado_por_defecto(): void
    {
        $voz = $this->configuracionSinVariables('config/voice.php', ['ELEVENLABS_', 'TWILIO_VOICE_', 'VOICE_']);
        $this->assertEncendidosApagados($voz, 'voice');

        // Sin variables (los valores del código), el estado es NOT_CONFIGURED.
        // Producción ya no lo está (E1-12): eso lo cubre la prueba de abajo.
        config(['voice' => $voz]);
        $this->assertSame('ELEVENLABS_NOT_CONFIGURED', VoiceProviders::status()->state);
        $this->assertFalse($voz['elevenlabs']['enabled']);
        $this->assertSame('default', $voz['elevenlabs']['region']);
        $this->assertFalse($voz['twilio']['enabled']);
        $this->assertSame(0, $voz['twilio']['daily_call_cap']);

        // El bloque de voz del Studio (el laboratorio hablado), si existe: apagado y en `fake`.
        $studio = $this->configuracionSinVariables('config/ultron_studio.php', ['ULTRON_STUDIO_VOICE_']);
        foreach ($studio as $clave => $valor) {
            if (is_array($valor) && str_contains((string) $clave, 'voice')) {
                $this->assertEncendidosApagados($valor, "ultron_studio.{$clave}");
                array_walk_recursive($valor, function (mixed $hoja, string|int $nombre) use ($clave): void {
                    if ($nombre === 'driver') {
                        $this->assertSame('fake', $hoja, "ultron_studio.{$clave}: el laboratorio hablado nace en el simulador (D8-23)");
                    }
                });
            }
        }

        // La configuración cargada en la suite: la voz apagada (la suite no corre con la voz encendida).
        $this->assertFalse(config('voice.elevenlabs.enabled'));
        $this->assertFalse(config('voice.twilio.enabled'));
        $this->assertSame(0, config('voice.twilio.daily_call_cap'));
    }

    public function test_por_defecto_ninguna_entrada_publica_sale_a_la_red(): void
    {
        // Graba y, sin ningún stub, cualquier petición lanza StrayRequestException.
        Http::preventStrayRequests();
        Http::fake([]);

        $this->assertSame('none', VoiceProviders::current()->id());
        $this->assertSame('not_configured', VoiceProviders::current()->verifyCredentials()->outcome);
        $this->assertSame('not_configured', (new ElevenLabsVoiceProvider)->verifyCredentials()->outcome, 'ni instanciando el adaptador a mano');
        $this->assertSame('not_configured', (new NullVoiceProvider)->verifyCredentials()->outcome);
        $huellas = ElevenLabsVoiceProvider::secretFingerprints('clave-de-huellas');
        $this->assertArrayHasKey('api_key', $huellas);
        $this->assertSame([], array_filter($huellas, static fn (?string $h): bool => $h !== null), 'sin secretos, ninguna huella');
        $this->assertFalse(VoiceCallPolicy::decide(new VoiceCallContext(true, true, 'ELEVENLABS_VERIFIED', 'active_voice', 'canary_registry', 20))->allowed);
        // `marketing_calls` nunca es un origen permitido (T-7 del H6; el H8 no lo cambia).
        $this->assertContains('source_not_allowed', VoiceCallPolicy::decide(new VoiceCallContext(true, true, 'ELEVENLABS_VERIFIED', 'active_voice', 'marketing_calls', 20))->reasons);
        $this->assertSame('missing_signature', ElevenLabsWebhookSignature::verify('{}', null, 'secreto', 1761955200));
        $this->assertNull(ElevenLabsWebhookEnvelope::type('{}'));
        foreach ([[], ['--json' => true], ['--verify' => true], ['--verify' => true, '--json' => true]] as $opciones) {
            Artisan::call('voice:doctor', $opciones);
        }

        Http::assertNothingSent();
    }

    /**
     * Enmienda 1, E1-12: producción tiene ElevenLabs configurado y encendido
     * (la clave, el agente de verificación y los dos secretos del servidor).
     * Con eso, la fábrica ya no devuelve los nulos: lo que cierra las llamadas
     * es la constante, no la falta de credenciales.
     */
    public function test_con_elevenlabs_configurado_como_en_produccion_ninguna_llamada_sale(): void
    {
        config([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'clave-de-prueba-e1-12-'.str_repeat('k', 24),
            'voice.elevenlabs.agent_id' => 'agent_e112',
            'voice.elevenlabs.webhook_secret' => 'webhook-de-prueba-e1-12-'.str_repeat('w', 24),
            'voice.elevenlabs.tool_secret' => 'herramientas-de-prueba-e1-12-'.str_repeat('t', 24),
            'voice.elevenlabs.region' => 'default',
            'voice.elevenlabs.base_url' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake([]);

        $this->assertSame('ELEVENLABS_UNVERIFIED', VoiceProviders::status()->state, 'la configuración de E1-12');
        $this->assertSame('elevenlabs', VoiceProviders::current()->id());

        // La política, aun con el proveedor VERIFIED: cerrada solo por la constante (protección 2).
        $decision = VoiceCallPolicy::decide(new VoiceCallContext(true, true, 'ELEVENLABS_VERIFIED', 'active_voice', 'canary_registry', 20));
        $this->assertFalse($decision->allowed);
        $this->assertSame(['calls_disabled_in_code'], $decision->reasons);

        // El iniciador de salientes de D8-3, con todo configurado, no marca.
        $iniciador = 'App\\Services\\Voice\\ElevenLabs\\ElevenLabsCallStarter';
        $peticion = 'App\\Services\\Voice\\Dto\\OutboundCallRequest';
        $spec = 'App\\Services\\Voice\\Dto\\VoiceAgentSpec';
        if (class_exists($iniciador) && class_exists($peticion) && class_exists($spec)) {
            $ref = '00000000-0000-4000-8000-0000000e1012';
            $variables = array_fill_keys($spec::VARIABLES, 'banco');
            $variables[$spec::VAR_CALLER_AUTHORIZED] = $spec::CALLER_AUTHORIZED;
            $variables[$spec::VAR_CALL_DIRECTION] = $spec::DIRECTION_OUTBOUND;
            $variables[$spec::VAR_CALL_REF] = $ref;
            $resultado = (new $iniciador)->startOutbound(new $peticion('agent_e112', 'phnum_e112', '+15005550006', $ref, $variables, 30, $spec::VOICEMAIL_POLICIES[0]));
            $this->assertSame(['not_attempted', 'calls_disabled_in_code'], [$resultado->outcome, $resultado->reason], 'con ElevenLabs configurado, el iniciador no marca mientras CALLS_ENABLED_IN_CODE sea false');
        }

        // El doctor sin --verify no sale a la red.
        foreach ([[], ['--json' => true]] as $opciones) {
            Artisan::call('voice:doctor', $opciones);
        }

        Http::assertNothingSent();
    }

    public function test_en_produccion_la_base_de_elevenlabs_es_la_oficial(): void
    {
        $antes = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->assertTrue(app()->isProduction());
            foreach (ElevenLabsConfig::REGIONS as $region => $oficial) {
                config(['voice.elevenlabs.region' => $region, 'voice.elevenlabs.base_url' => 'http://127.0.0.1:8096']);
                $this->assertSame($oficial, ElevenLabsConfig::fromConfig()->baseUrl(), "en production la base de {$region} es la oficial (protección 3)");
            }
        } finally {
            $this->app['env'] = $antes;
        }
        $this->assertFalse(app()->isProduction());
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Pone todos los secretos de `voice.*` (los que se llamen como un secreto) a un valor, o a null. */
    private function secretosDeVoz(?string $valor): void
    {
        $voz = (array) config('voice');
        $recorrer = function (array $nodo, string $prefijo) use (&$recorrer, $valor): void {
            foreach ($nodo as $clave => $hoja) {
                $ruta = $prefijo.'.'.$clave;
                if (is_array($hoja)) {
                    $recorrer($hoja, $ruta);
                } elseif (preg_match('/^(api_key|secret|token|password)$|_(secret|token|password)$/', (string) $clave) === 1) {
                    config([$ruta => $valor]);
                }
            }
        };
        $recorrer($voz, 'voice');
    }

    /**
     * Cada bandera en false y cada techo diario o presupuesto en 0.
     *
     * @param  array<mixed>  $arbol
     */
    private function assertEncendidosApagados(array $arbol, string $donde): void
    {
        array_walk_recursive($arbol, function (mixed $hoja, string|int $clave) use ($donde): void {
            if (is_bool($hoja)) {
                $this->assertFalse($hoja, "{$donde}.{$clave} nace encendida (protección 1)");
            }
            // Un techo o un presupuesto: número, o texto decimal (como `VOICE_DAILY_BUDGET_USD`).
            if ((is_int($hoja) || is_float($hoja) || (is_string($hoja) && is_numeric(trim($hoja)))) && preg_match('/daily|budget|cap(_|$)/', (string) $clave) === 1) {
                $this->assertEquals(0.0, (float) $hoja, "{$donde}.{$clave} nace con techo o presupuesto (protección 1)");
            }
        });
    }

    /**
     * Un fichero de configuración evaluado SIN las variables de entorno con
     * esos prefijos, sea cual sea el entorno con el que corra la suite.
     *
     * @param  list<string>  $prefijos
     * @return array<string, mixed>
     */
    private function configuracionSinVariables(string $fichero, array $prefijos): array
    {
        $nombres = [];
        foreach (array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys((array) getenv())) as $nombre) {
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

        try {
            return require base_path($fichero);
        } finally {
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
}
