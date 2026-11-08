<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use Tests\TestCase;

/**
 * La configuración de ElevenLabs y `config/voice.php` (contrato H6 §I6.1–§I6.3),
 * con casos escritos a mano desde el contrato (sección `voiceDoctor` del
 * fixture: configuración → `state`, `missing` y `region`).
 *
 * LO QUE SE PROTEGE. Que con la configuración por defecto el estado es
 * `ELEVENLABS_NOT_CONFIGURED` con `missing = [api_key, agent_id,
 * webhook_secret]` y región `default` (el de producción en el H6); las
 * reglas de forma de cada pieza; la lista CERRADA de regiones (solo
 * `default` y `eu`); que la base del simulador se ignora en producción; los
 * recortes; las banderas con `filter_var`; y que ningún secreto queda dentro
 * del objeto ni de su estado.
 */
class ElevenLabsConfigTest extends TestCase
{
    private const CLAVE = 'xi-centinela-API-0123456789abcdef';

    private const SECRETO = 'whsec-centinela-WEBHOOK';

    private const HERRAMIENTAS = 'tool-centinela-TOOL';

    /** Un bloque completo y válido, apagado. */
    private static function completo(array $cambios = []): array
    {
        return $cambios + [
            'enabled' => false,
            'api_key' => self::CLAVE,
            'agent_id' => 'agent_8a7f3c',
            'webhook_secret' => self::SECRETO,
            'region' => 'default',
            'agent_phone_number_id' => null,
            'tool_secret' => self::HERRAMIENTAS,
            'base_url' => null,
            'timeout_seconds' => 10,
        ];
    }

    private static function con(array $bloque): ElevenLabsConfig
    {
        config(['voice.elevenlabs' => $bloque]);

        return ElevenLabsConfig::fromConfig();
    }

    public function test_por_defecto_es_el_estado_de_produccion_del_h6(): void
    {
        $s = ElevenLabsConfig::fromConfig()->status();

        $this->assertSame(
            ['provider' => 'none', 'state' => 'ELEVENLABS_NOT_CONFIGURED', 'enabled' => false, 'missing' => ['api_key', 'agent_id', 'webhook_secret'], 'region' => 'default'],
            $s->toArray(),
        );
    }

    public function test_las_regiones_son_una_lista_cerrada_de_dos(): void
    {
        $this->assertSame(['default' => 'https://api.elevenlabs.io', 'eu' => 'https://api.eu.residency.elevenlabs.io'], ElevenLabsConfig::REGIONS);
        $this->assertSame('/^[A-Za-z0-9_-]{1,64}$/D', ElevenLabsConfig::AGENT_ID_PATTERN);
    }

    /** @return array<string, array{0: array<string,mixed>, 1: string, 2: list<string>, 3: ?string}> nombre → [bloque, estado, missing, región] */
    public static function casos(): array
    {
        return [
            'completo y apagado' => [self::completo(), 'ELEVENLABS_DISABLED', [], 'default'],
            'completo y encendido' => [self::completo(['enabled' => true]), 'ELEVENLABS_UNVERIFIED', [], 'default'],
            'encendido sin clave' => [self::completo(['enabled' => true, 'api_key' => null]), 'ELEVENLABS_NOT_CONFIGURED', ['api_key'], 'default'],
            'clave de espacios' => [self::completo(['api_key' => "  \t"]), 'ELEVENLABS_NOT_CONFIGURED', ['api_key'], 'default'],
            'clave que no es texto' => [self::completo(['api_key' => true]), 'ELEVENLABS_NOT_CONFIGURED', ['api_key'], 'default'],
            'sin secreto del webhook' => [self::completo(['webhook_secret' => '']), 'ELEVENLABS_NOT_CONFIGURED', ['webhook_secret'], 'default'],
            'el secreto de las server tools no se exige' => [self::completo(['tool_secret' => null]), 'ELEVENLABS_DISABLED', [], 'default'],
            'el id del número no se exige' => [self::completo(['agent_phone_number_id' => null]), 'ELEVENLABS_DISABLED', [], 'default'],
            'agente con espacios dentro' => [self::completo(['agent_id' => 'agente de prueba']), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente de 65 caracteres' => [self::completo(['agent_id' => str_repeat('a', 65)]), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente de 64 caracteres' => [self::completo(['agent_id' => str_repeat('a', 64)]), 'ELEVENLABS_DISABLED', [], 'default'],
            'agente con barra (ruta)' => [self::completo(['agent_id' => '../agents']), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente con tilde' => [self::completo(['agent_id' => 'agénte']), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente con espacios alrededor: no se recorta, es inválido (N-5, Enmienda 4)' => [self::completo(['agent_id' => ' agent_8a7f3c ']), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente con salto de línea final: inválido (patrón con D, sin recorte)' => [self::completo(['agent_id' => "agent_8a7f3c\n"]), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'agente vacío' => [self::completo(['agent_id' => '']), 'ELEVENLABS_NOT_CONFIGURED', ['agent_id'], 'default'],
            'región eu' => [self::completo(['region' => 'eu']), 'ELEVENLABS_DISABLED', [], 'eu'],
            'región EU: se pasa a minúsculas' => [self::completo(['region' => 'EU']), 'ELEVENLABS_DISABLED', [], 'eu'],
            'región con espacios alrededor' => [self::completo(['region' => ' eu ']), 'ELEVENLABS_DISABLED', [], 'eu'],
            'región us: fuera de la lista cerrada' => [self::completo(['region' => 'us']), 'ELEVENLABS_NOT_CONFIGURED', ['region'], null],
            'región in: fuera de la lista cerrada' => [self::completo(['region' => 'in']), 'ELEVENLABS_NOT_CONFIGURED', ['region'], null],
            'región vacía' => [self::completo(['region' => '']), 'ELEVENLABS_NOT_CONFIGURED', ['region'], null],
            'región ausente: la de por defecto' => [array_diff_key(self::completo(), ['region' => true]), 'ELEVENLABS_DISABLED', [], 'default'],
            'región que no es texto' => [self::completo(['region' => 1]), 'ELEVENLABS_NOT_CONFIGURED', ['region'], null],
            'nada de nada' => [['region' => 'mars'], 'ELEVENLABS_NOT_CONFIGURED', ['api_key', 'agent_id', 'webhook_secret', 'region'], null],
            'encendida como texto: no cuenta (la configuración ya aplicó filter_var)' => [self::completo(['enabled' => 'true']), 'ELEVENLABS_DISABLED', [], 'default'],
            'encendida como 1 entero: no cuenta' => [self::completo(['enabled' => 1]), 'ELEVENLABS_DISABLED', [], 'default'],
        ];
    }

    public function test_los_casos_del_contrato(): void
    {
        foreach (self::casos() as $nombre => [$bloque, $estado, $faltan, $region]) {
            $c = self::con($bloque);
            $s = $c->status();
            $this->assertSame($estado, $s->state, $nombre);
            $this->assertSame($faltan, $s->missing, $nombre);
            $this->assertSame($faltan, $c->missing(), $nombre);
            $this->assertSame($region, $s->region, $nombre);
            $this->assertSame($region, $c->region(), $nombre);
            $this->assertSame($estado === 'ELEVENLABS_UNVERIFIED' ? 'elevenlabs' : 'none', $s->provider, $nombre);
        }
        $this->assertGreaterThanOrEqual(12, count(self::casos()), 'el fixture pide al menos 12');
    }

    public function test_sin_el_bloque_de_configuracion_todo_falta_salvo_la_region(): void
    {
        config(['voice' => null]);

        $this->assertSame(['api_key', 'agent_id', 'webhook_secret'], ElevenLabsConfig::fromConfig()->missing());
        $this->assertSame('ELEVENLABS_NOT_CONFIGURED', ElevenLabsConfig::fromConfig()->status()->state);
    }

    public function test_la_url_base_en_produccion_es_siempre_la_de_la_region(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame('https://api.elevenlabs.io', self::con(self::completo(['base_url' => 'http://127.0.0.1:8096']))->baseUrl());
        $this->assertSame('https://api.eu.residency.elevenlabs.io', self::con(self::completo(['region' => 'eu', 'base_url' => 'http://127.0.0.1:8096']))->baseUrl());
        $this->assertNull(self::con(self::completo(['region' => 'us', 'base_url' => 'http://127.0.0.1:8096']))->baseUrl());
    }

    public function test_fuera_de_produccion_se_admite_la_base_del_simulador_si_es_limpia(): void
    {
        $this->assertSame('http://127.0.0.1:8096', self::con(self::completo(['base_url' => 'http://127.0.0.1:8096/']))->baseUrl());
        $this->assertSame('https://simulador.local/elevenlabs', self::con(self::completo(['base_url' => ' https://simulador.local/elevenlabs ']))->baseUrl());
        $this->assertSame('https://api.elevenlabs.io', self::con(self::completo(['base_url' => null]))->baseUrl());

        foreach (['ftp://127.0.0.1/', 'http://usuario:clave@127.0.0.1', 'http://127.0.0.1/?x=1', 'http://127.0.0.1/#f', 'no es una url', '', 'javascript:alert(1)'] as $mala) {
            $this->assertSame('https://api.eu.residency.elevenlabs.io', self::con(self::completo(['region' => 'eu', 'base_url' => $mala]))->baseUrl(), $mala);
        }

        // Sin región válida no hay URL, ni siquiera la del simulador.
        $this->assertNull(self::con(self::completo(['region' => '', 'base_url' => 'http://127.0.0.1:8096']))->baseUrl());
    }

    public function test_el_tiempo_de_espera_se_recorta_a_3_30(): void
    {
        $casos = [[null, 10], [10, 10], ['5', 5], [2, 3], [-4, 3], ['-4', 3], [31, 30], [100, 30], ['abc', 10], ['', 10], [7.5, 10]];
        foreach ($casos as [$valor, $esperado]) {
            $this->assertSame($esperado, self::con(self::completo(['timeout_seconds' => $valor]))->timeoutSeconds(), var_export($valor, true));
        }
    }

    public function test_el_id_del_agente_no_se_recorta_y_solo_sale_si_es_valido(): void
    {
        $this->assertNull(self::con(self::completo(['agent_id' => ' agent_8a7f3c ']))->agentId());   // sin recorte (N-5, Enmienda 4): un espacio lo invalida, como el fixture voiceDoctor
        $this->assertSame('agent_8a7f3c', self::con(self::completo(['agent_id' => 'agent_8a7f3c']))->agentId());
        $this->assertNull(self::con(self::completo(['agent_id' => 'agent/8a7f3c']))->agentId());
    }

    public function test_ningun_secreto_queda_en_el_objeto_ni_en_su_estado(): void
    {
        $c = self::con(self::completo(['enabled' => true]));

        $volcado = var_export($c, true).print_r($c, true).serialize($c).json_encode($c->status()->toArray()).var_export($c->status(), true);

        foreach ([self::CLAVE, self::SECRETO, self::HERRAMIENTAS] as $secreto) {
            $this->assertStringNotContainsString($secreto, $volcado);
        }
        $tipos = [];
        foreach ((new \ReflectionClass(ElevenLabsConfig::class))->getProperties() as $propiedad) {
            $tipos[$propiedad->getName()] = (string) $propiedad->getType();
        }
        $this->assertSame([
            'enabled' => 'bool', 'hasApiKey' => 'bool', 'agentId' => '?string', 'hasWebhookSecret' => 'bool',
            'region' => '?string', 'nonProductionBaseUrl' => '?string', 'timeoutSeconds' => 'int',
        ], $tipos, 'los secretos se guardan como «hay o no hay», nunca como texto');
    }

    /**
     * `config/voice.php` evaluado de verdad con un entorno de prueba: las
     * banderas con `filter_var`, la región en minúsculas y recortada, y los
     * recortes de los enteros.
     */
    public function test_config_voice_aplica_las_reglas_al_leer_el_entorno(): void
    {
        $vacio = self::evaluar([]);
        $this->assertSame([
            'elevenlabs' => [
                'enabled' => false, 'api_key' => null, 'agent_id' => null, 'webhook_secret' => null, 'region' => 'default',
                'agent_phone_number_id' => null, 'tool_secret' => null,
                // H8 (D8-5 y D8-16): el secreto de iniciación y las tres referencias, vacíos.
                'initiation_secret' => null, 'tool_secret_ref' => null, 'initiation_secret_ref' => null, 'post_call_webhook_ref' => null,
                'base_url' => null, 'timeout_seconds' => 10,
            ],
            'twilio' => [
                'enabled' => false, 'from' => null, 'daily_call_cap' => 0,
                // H8 (D8-4 y D8-5): la subcuenta, la key de Laravel, el token de firma y el simulador, vacíos.
                'account_sid' => null, 'api_key_sid' => null, 'api_key_secret' => null, 'signing_token' => null, 'base_url' => null,
            ],
            // H8 (D8-11, D8-12 y D8-15): TODO APAGADO; los límites son techos, que no encienden nada.
            'webhook_base_url' => null,
            'inbound' => ['enabled' => false, 'scope' => 'canary', 'daily_cap' => 0],
            'outbound' => [
                'enabled' => false, 'scope' => 'canary',
                // H8, B8-b (P-10, P-11 y P-13): el timbre, el horario propio vacío (solo el legal) y la frecuencia más estricta.
                'ringing_timeout_seconds' => 30,
                'own_hours' => ['weekdays' => '', 'saturday' => ''],
                'frequency' => ['per_week' => 2, 'week' => 'rolling'],
            ],
            // H8, B8-b: E2-4 (publicar y el laboratorio hablado, cada uno con su bandera), el monitor, el horario del personal,
            // los hechos de `consultar_horarios`, la vigencia del RNE (0 = ninguna) y los precios oficiales con su fecha.
            // E4-1: la bandera de publicar es del Studio (`ultron_studio.voice.publishing_enabled`); aquí no está.
            'lab' => ['enabled' => false, 'driver' => 'fake', 'daily_budget_usd' => '0', 'session_budget_usd' => '0', 'max_session_seconds' => 300, 'max_sessions_per_day' => 5],
            // B8-c (Enmienda 7, E7-2): el techo global por minuto de lo autenticado de cada ruta pública.
            'public_limits' => ['global_per_minute' => ['initiation' => 120, 'post_call' => 240, 'tools' => 600, 'usage_trigger' => 60]],
            'monitor' => ['enabled' => false],
            'transfer' => ['staff_hours' => ['weekdays' => '', 'saturday' => '']],
            'facts' => ['site' => '', 'hours' => ''],
            'contacts' => ['rne_validity_days' => 0],
            'prices' => ['as_of' => '2026-11-03', 'elevenlabs' => 80000, 'twilio_outbound_mobile_co' => 37700, 'twilio_outbound_landline_co' => 70000,
                'twilio_inbound_local_co' => 94500, 'twilio_media_streams' => 4400],
            'limits' => ['max_concurrent_calls' => 1, 'per_call_max_seconds' => 300, 'daily_budget_usd' => '0'],
        ], $vacio, 'los defectos de §I6.2 y del H8, en su orden');

        // H8, B8-b: las banderas de E2-4 y del monitor, con el mismo filter_var; los recortes y la semana.
        foreach (['true' => true, 'on' => true, 'false' => false, '' => false, 'si' => false] as $valor => $esperado) {
            foreach (['VOICE_LAB_ENABLED' => 'lab', 'VOICE_MONITOR_ENABLED' => 'monitor'] as $variable => $bloque) {
                $this->assertSame($esperado, self::evaluar([$variable => (string) $valor])[$bloque]['enabled'], "{$variable}={$valor}");
            }
        }
        // E4-1: VOICE_PUBLISHING_ENABLED ya no se lee aquí (una sola bandera de publicar, la del Studio).
        $this->assertArrayNotHasKey('publishing', self::evaluar(['VOICE_PUBLISHING_ENABLED' => 'true']));
        foreach (['5' => 10, '45' => 45, '90' => 60, 'x' => 30] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_OUTBOUND_RINGING_SECONDS' => (string) $valor])['outbound']['ringing_timeout_seconds'], "VOICE_OUTBOUND_RINGING_SECONDS={$valor}");
        }
        // LAB8 (informe-lab §2.3): el controlador en minúsculas y sin espacios; los recortes de la sesión y del cupo.
        $this->assertSame('elevenlabs', self::evaluar(['VOICE_LAB_DRIVER' => ' ElevenLabs '])['lab']['driver']);
        $this->assertSame('otro', self::evaluar(['VOICE_LAB_DRIVER' => 'otro'])['lab']['driver'], 'un valor desconocido llega tal cual: el laboratorio lo niega (invalid_driver)');
        foreach (['30' => 60, '120' => 120, '900' => 300, 'x' => 300] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_LAB_MAX_SESSION_SECONDS' => (string) $valor])['lab']['max_session_seconds'], "VOICE_LAB_MAX_SESSION_SECONDS={$valor}");
        }
        foreach (['-1' => 0, '0' => 0, '7' => 7, '99' => 50] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_LAB_MAX_SESSIONS_PER_DAY' => (string) $valor])['lab']['max_sessions_per_day'], "VOICE_LAB_MAX_SESSIONS_PER_DAY={$valor}");
        }
        $this->assertSame(['12.5', '1.25'], [self::evaluar(['VOICE_LAB_DAILY_BUDGET_USD' => '12.5'])['lab']['daily_budget_usd'], self::evaluar(['VOICE_LAB_SESSION_BUDGET_USD' => '1.25'])['lab']['session_budget_usd']]);
        foreach (['1' => 1, '0' => 1, '7' => 2] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_FREQUENCY_PER_WEEK' => (string) $valor])['outbound']['frequency']['per_week'], "VOICE_FREQUENCY_PER_WEEK={$valor}");
        }
        foreach (['calendar' => 'calendar', ' Calendar ' => 'calendar', 'rolling' => 'rolling', 'semana' => 'rolling', '' => 'rolling'] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_FREQUENCY_WEEK' => (string) $valor])['outbound']['frequency']['week'], "VOICE_FREQUENCY_WEEK={$valor}");
        }
        foreach (['30' => 30, '-1' => 0, '999' => 365, 'x' => 0] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_RNE_VALIDITY_DAYS' => (string) $valor])['contacts']['rne_validity_days'], "VOICE_RNE_VALIDITY_DAYS={$valor}");
        }
        // B8-c (E7-2): cada techo global, entre el cupo por llamada de su ruta y diez veces su defecto; vacío o sin forma, el defecto.
        foreach (['VOICE_PUBLIC_INITIATION_PER_MINUTE' => ['initiation', ['300' => 300, '1' => 10, '99999' => 1200, 'x' => 120, '' => 120]],
            'VOICE_PUBLIC_POST_CALL_PER_MINUTE' => ['post_call', ['500' => 500, '0' => 10, '99999' => 2400, 'x' => 240]],
            'VOICE_PUBLIC_TOOLS_PER_MINUTE' => ['tools', ['900' => 900, '59' => 60, '99999' => 6000, '-5' => 60, 'x' => 600]],
            'VOICE_PUBLIC_USAGE_TRIGGER_PER_MINUTE' => ['usage_trigger', ['30' => 30, '9' => 10, '99999' => 600, 'x' => 60]]] as $variable => [$clave, $casos]) {
            foreach ($casos as $valor => $esperado) {
                $this->assertSame($esperado, self::evaluar([$variable => (string) $valor])['public_limits']['global_per_minute'][$clave], "{$variable}={$valor}");
            }
        }

        // H8: las banderas nuevas con el mismo filter_var; el alcance de salientes,
        // `contacts` o el más estrecho; los techos, recortados.
        foreach (['true' => true, 'on' => true, 'false' => false, '' => false, 'si' => false] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_INBOUND_ENABLED' => (string) $valor])['inbound']['enabled'], "VOICE_INBOUND_ENABLED={$valor}");
            $this->assertSame($esperado, self::evaluar(['VOICE_OUTBOUND_ENABLED' => (string) $valor])['outbound']['enabled'], "VOICE_OUTBOUND_ENABLED={$valor}");
        }
        foreach (['contacts' => 'contacts', ' Contacts ' => 'contacts', 'canary' => 'canary', 'all' => 'canary', '' => 'canary'] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['VOICE_OUTBOUND_SCOPE' => (string) $valor])['outbound']['scope'], "VOICE_OUTBOUND_SCOPE={$valor}");
        }
        foreach (['VOICE_INBOUND_DAILY_CAP' => ['inbound', 'daily_cap', ['5' => 5, '-1' => 0, '99' => 20, 'x' => 0]],
            'VOICE_MAX_CONCURRENT_CALLS' => ['limits', 'max_concurrent_calls', ['2' => 2, '0' => 1, '9' => 4, 'x' => 1]],
            'VOICE_PER_CALL_MAX_SECONDS' => ['limits', 'per_call_max_seconds', ['120' => 120, '10' => 60, '7200' => 600, '' => 300]]] as $variable => [$bloque, $clave, $casos]) {
            foreach ($casos as $valor => $esperado) {
                $this->assertSame($esperado, self::evaluar([$variable => (string) $valor])[$bloque][$clave], "{$variable}={$valor}");
            }
        }
        $this->assertSame('2.50', self::evaluar(['VOICE_DAILY_BUDGET_USD' => '2.50'])['limits']['daily_budget_usd'], 'el presupuesto va como texto decimal');

        foreach (['true' => true, '1' => true, 'on' => true, 'yes' => true, 'TRUE' => true, 'Yes' => true, 'false' => false, '0' => false, 'off' => false, 'no' => false, '' => false, 'si' => false, 'enabled' => false] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['ELEVENLABS_ENABLED' => (string) $valor])['elevenlabs']['enabled'], "ELEVENLABS_ENABLED={$valor}");
            $this->assertSame($esperado, self::evaluar(['TWILIO_VOICE_ENABLED' => (string) $valor])['twilio']['enabled'], "TWILIO_VOICE_ENABLED={$valor}");
        }

        foreach (['eu' => 'eu', 'EU' => 'eu', ' Eu ' => 'eu', 'default' => 'default', 'us' => 'us', '' => ''] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['ELEVENLABS_REGION' => (string) $valor])['elevenlabs']['region'], "ELEVENLABS_REGION={$valor}");
        }

        foreach (['15' => 15, '1' => 3, '99' => 30, 'x' => 10, '' => 10, '-2' => 3] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['ELEVENLABS_TIMEOUT_SECONDS' => (string) $valor])['elevenlabs']['timeout_seconds'], "ELEVENLABS_TIMEOUT_SECONDS={$valor}");
        }

        foreach (['7' => 7, '0' => 0, '-5' => 0, '50' => 20, 'x' => 0, '' => 0] as $valor => $esperado) {
            $this->assertSame($esperado, self::evaluar(['TWILIO_VOICE_DAILY_CALL_CAP' => (string) $valor])['twilio']['daily_call_cap'], "TWILIO_VOICE_DAILY_CALL_CAP={$valor}");
        }

        $todo = self::evaluar([
            'ELEVENLABS_API_KEY' => self::CLAVE, 'ELEVENLABS_AGENT_ID' => 'agent_8a7f3c', 'ELEVENLABS_WEBHOOK_SECRET' => self::SECRETO,
            'ELEVENLABS_TOOL_SECRET' => self::HERRAMIENTAS, 'ELEVENLABS_AGENT_PHONE_NUMBER_ID' => 'phnum_1', 'ELEVENLABS_BASE_URL' => 'http://127.0.0.1:8096',
            'TWILIO_VOICE_FROM' => '+576015550100',
        ]);
        $this->assertSame(self::CLAVE, $todo['elevenlabs']['api_key'], 'la configuración lleva el valor; quien lo lee es solo el proveedor');
        $this->assertSame('phnum_1', $todo['elevenlabs']['agent_phone_number_id']);
        $this->assertSame('+576015550100', $todo['twilio']['from']);
    }

    /**
     * Evalúa `config/voice.php` con estas variables puestas (y las demás de voz
     * quitadas), y deja el entorno como estaba.
     *
     * @param  array<string,string>  $variables
     * @return array<string,mixed>
     */
    private static function evaluar(array $variables): array
    {
        $todas = ['ELEVENLABS_ENABLED', 'ELEVENLABS_API_KEY', 'ELEVENLABS_AGENT_ID', 'ELEVENLABS_WEBHOOK_SECRET', 'ELEVENLABS_REGION',
            'ELEVENLABS_AGENT_PHONE_NUMBER_ID', 'ELEVENLABS_TOOL_SECRET', 'ELEVENLABS_BASE_URL', 'ELEVENLABS_TIMEOUT_SECONDS',
            'TWILIO_VOICE_ENABLED', 'TWILIO_VOICE_FROM', 'TWILIO_VOICE_DAILY_CALL_CAP',
            // H8
            'ELEVENLABS_INITIATION_SECRET', 'ELEVENLABS_TOOL_SECRET_REF', 'ELEVENLABS_INITIATION_SECRET_REF', 'ELEVENLABS_POST_CALL_WEBHOOK_REF',
            'TWILIO_VOICE_ACCOUNT_SID', 'TWILIO_VOICE_API_KEY_SID', 'TWILIO_VOICE_API_KEY_SECRET', 'TWILIO_VOICE_SIGNING_TOKEN', 'TWILIO_VOICE_BASE_URL',
            'VOICE_WEBHOOK_BASE_URL', 'VOICE_INBOUND_ENABLED', 'VOICE_INBOUND_DAILY_CAP', 'VOICE_OUTBOUND_ENABLED', 'VOICE_OUTBOUND_SCOPE',
            'VOICE_MAX_CONCURRENT_CALLS', 'VOICE_PER_CALL_MAX_SECONDS', 'VOICE_DAILY_BUDGET_USD',
            // H8, B8-b
            'VOICE_OUTBOUND_RINGING_SECONDS', 'VOICE_OUTBOUND_HOURS_WEEKDAYS', 'VOICE_OUTBOUND_HOURS_SATURDAY', 'VOICE_FREQUENCY_PER_WEEK',
            'VOICE_FREQUENCY_WEEK', 'VOICE_PUBLISHING_ENABLED', 'VOICE_LAB_ENABLED', 'VOICE_MONITOR_ENABLED', 'VOICE_TRANSFER_HOURS_WEEKDAYS',
            'VOICE_TRANSFER_HOURS_SATURDAY', 'VOICE_FACTS_SITE', 'VOICE_FACTS_HOURS', 'VOICE_RNE_VALIDITY_DAYS',
            // LAB8 (informe-lab §2.3)
            'VOICE_LAB_DRIVER', 'VOICE_LAB_DAILY_BUDGET_USD', 'VOICE_LAB_SESSION_BUDGET_USD', 'VOICE_LAB_MAX_SESSION_SECONDS', 'VOICE_LAB_MAX_SESSIONS_PER_DAY'];
        $antes = [];
        foreach ($todas as $nombre) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
        }
        try {
            foreach ($variables as $nombre => $valor) {
                $_SERVER[$nombre] = $valor;
                $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }

            return require dirname(__DIR__, 3).'/config/voice.php';
        } finally {
            foreach ($antes as $nombre => [$servidor, $entorno, $proceso]) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
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

    /** N-5 de security (Enmienda 3): sin el modificador D, PHP aceptaría un agent_id con un salto de línea final. */
    public function test_el_agent_id_no_admite_un_salto_de_linea_final(): void
    {
        $this->assertSame(1, preg_match(ElevenLabsConfig::AGENT_ID_PATTERN, 'agent_abc-123'));
        $this->assertSame(0, preg_match(ElevenLabsConfig::AGENT_ID_PATTERN, "agent_abc-123\n"));
    }
}
