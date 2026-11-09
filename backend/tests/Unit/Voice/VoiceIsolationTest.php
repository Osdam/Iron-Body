<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\VoiceCallPolicy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La frontera del paquete de voz, por tokens (contrato H6 §I9.5, §I6.1 y §I1;
 * REESCRITA por SEC8 con el contrato del Hito 8: D8-2, D8-3, D8-5 y las
 * protecciones 2 y 3). Quien implementa no la toca: si cae, avisa al lead.
 *
 * LO QUE SE PROTEGE.
 *  - `VoiceCallPolicy::CALLS_ENABLED_IN_CODE === false`, literal, una vez, y
 *    declarada solo ahí: nadie la sombrea con otra constante, nadie la lee de
 *    otra clase y nadie la nombra dentro de una cadena (`constant()`).
 *  - LISTA CERRADA. En `app/Services/Voice/` están los doce ficheros del H6 y,
 *    como mucho, los del contrato del H8 (interfaces, adaptadores de
 *    ElevenLabs, el traductor puro y un nulo por interfaz). `Dto/` admite
 *    cualquier nombre, pero solo objetos INERTES: sin red, configuración,
 *    reloj, base ni registro. Ningún otro subdirectorio.
 *  - LA RED, SOLO DONDE SE ENUMERA. `Http::` solo en cuatro adaptadores de
 *    ElevenLabs y en el transportista de Twilio, solo dentro de sus métodos
 *    enumerados, una petición por método, con su verbo, sin redirecciones ni
 *    reintentos (D8-3). La telefonía con estado, los trabajos, los comandos y
 *    el gobierno de la voz en el Studio no salen a la red: van por las
 *    interfaces. El transportista no manda TwiML ni una URL a una llamada viva.
 *  - `outbound-call` solo en `ElevenLabsCallStarter`; ni `register-call`, ni
 *    llamadas por lotes, ni troncales SIP en el código; ni WhatsApp en el
 *    paquete (protección 6).
 *  - HOSTS FIJOS (protección 3). Ninguna URL literal que no sea de un host
 *    oficial; quien sale a la red decide la base con `isProduction()` o con
 *    `ElevenLabsConfig::baseUrl()`.
 *  - SECRETOS, SOLO EN SUS LECTORES (D8-5). Cada secreto de `voice.*` lo leen
 *    solo sus lectores enumerados (y el punto único de las huellas); un
 *    secreto nuevo sin lector enumerado cae. El bloque entero solo lo lee
 *    `ElevenLabsConfig` (el dominio de permisos `telephony` de D8-22 no es un
 *    bloque: E4-4, en sus dos ficheros). Las variables de voz del entorno, solo
 *    su fichero de configuración.
 *  - NEUTRO Y SIN ESTADO (se conserva el H6): el paquete no nombra el Studio,
 *    el motor, los modelos ni la telefonía, y no usa base, caché, colas,
 *    registro ni reloj.
 *  - EL PUERTO NO SABE LLAMAR: las interfaces tienen sus métodos cerrados
 *    (ninguna actualiza ni borra un agente, D8-7) y un método público con
 *    nombre de llamada solo existe donde el contrato lo pone.
 *  - EL NÚMERO SE OBSERVA (E5-2): `VoiceNumberObserver::describeNumber`, un
 *    GET a `/v1/convai/phone-numbers/{id}` y solo desde `VoiceReconciler`.
 *  - RUTAS: ninguna ruta apunta a un servicio de voz; los controladores de
 *    voz solo en `routes/voice.php` (los del Studio, en el suyo).
 *  - LAS PRUEBAS DE VOZ CON RED cierran la red con
 *    `Http::preventStrayRequests()` (protección 3).
 */
class VoiceIsolationTest extends TestCase
{
    /** H6: los doce ficheros del paquete, que tienen que seguir estando. */
    private const PAQUETE_H6 = [
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

    /**
     * H8 (contrato D8-3): lo único que se puede añadir fuera de `Dto/`, además
     * de un nulo por interfaz. Las firmas son indicativas; un nombre que no
     * esté aquí pasa por SEC8.
     */
    private const PAQUETE_H8 = [
        // Las interfaces segregadas.
        'TelephonyCarrier.php',
        'VoiceAgentDeployer.php',
        'VoiceCallStarter.php',
        'VoiceEventVerifier.php',
        'VoiceLabSessionIssuer.php',
        // E5-2 (Enmienda 5; E4-8): la observación del número, interfaz propia de
        // solo lectura (su nulo entra por {@see NULO}; su DTO, por `Dto/`).
        'VoiceNumberObserver.php',
        // Los DTO neutros, si no van en `Dto/`.
        'CarrierCallsPage.php',
        'CarrierResult.php',
        'OutboundCallRequest.php',
        'OutboundCallResult.php',
        'VoiceAgentObservation.php',
        'VoiceAgentPayload.php',
        'VoiceAgentSpec.php',
        'VoiceDeployResult.php',
        'VoiceLabSession.php',
        'VoiceWebhookVerdict.php',
        // La fábrica de las interfaces (el real o el nulo de cada una, como
        // `VoiceProviders` en el H6). D8-3 pide los nulos; la fábrica la añade B8.
        'VoiceAdapters.php',
        // Las implementaciones de ElevenLabs y el traductor puro.
        'ElevenLabs/ElevenLabsAgentDeployer.php',
        'ElevenLabs/ElevenLabsAgentMapper.php',
        'ElevenLabs/ElevenLabsCallStarter.php',
        'ElevenLabs/ElevenLabsEventVerifier.php',
        'ElevenLabs/ElevenLabsLabSessionIssuer.php',
    ];

    /** Los objetos inertes del H8: cualquier nombre, ninguna capacidad. */
    private const DTO = 'Dto/';

    /** Un nulo por interfaz (D8-3): el nombre lo fija B8; la forma, no. */
    private const NULO = '/^Null[A-Z][A-Za-z0-9]*\.php$/D';

    /** Las interfaces del paquete y sus métodos, cerrados: ninguna actualiza ni borra un agente (D8-7). */
    private const INTERFACES = [
        'VoiceAgentProvider' => ['id', 'status', 'verifyCredentials'],
        'TelephonyCarrier' => ['hangup', 'listCalls'],
        'VoiceAgentDeployer' => ['assignNumber', 'createAgent', 'describeAgent'],
        'VoiceCallStarter' => ['startOutbound'],
        'VoiceEventVerifier' => ['verifyPostCall'],
        'VoiceLabSessionIssuer' => ['signedSessionUrl'],
        // E5-2: un solo método, de lectura.
        'VoiceNumberObserver' => ['describeNumber'],
    ];

    /** Los comandos de voz: el doctor del H6 y los del H8 (`voice:hangup`, `voice:reconcile`); los nombres de consola, en `VoiceBoundaryTest`. */
    private const COMANDO = '/^Voice[A-Z][A-Za-z]*Command\.php$/D';

    private const DOCTOR = 'VoiceDoctorCommand.php';

    private const PROVEEDOR = 'app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php';

    private const CONFIGURACION = 'app/Services/Voice/ElevenLabs/ElevenLabsConfig.php';

    private const DESPLEGADOR = 'app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php';

    private const INICIADOR = 'app/Services/Voice/ElevenLabs/ElevenLabsCallStarter.php';

    private const VERIFICADOR = 'app/Services/Voice/ElevenLabs/ElevenLabsEventVerifier.php';

    private const EMISOR = 'app/Services/Voice/ElevenLabs/ElevenLabsLabSessionIssuer.php';

    /** El transportista de Twilio de la voz (D8-3): nuevo, no amplía `TwilioReadOnlyClient`. */
    private const TRANSPORTISTA = 'app/Services/Twilio/TwilioVoiceCarrier.php';

    /** El validador de Twilio de la voz: el contrato lo pone en la telefonía (D8-4); el reparto del paso 1, en Twilio. Vale uno de los dos, no los dos. */
    private const FIRMA_TWILIO = ['app/Services/Telephony/TwilioRequestSignature.php', 'app/Services/Twilio/TwilioRequestSignature.php'];

    private const AUTH_HERRAMIENTAS = 'app/Services/Telephony/VoiceToolAuth.php';

    private const AUTH_INICIACION = 'app/Services/Telephony/VoiceInitiationAuth.php';

    /**
     * E4-4 (Enmienda 4; SEC8-b): donde el literal `telephony` es el DOMINIO de
     * permisos de D8-22 (`CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS` y su
     * ficha en `PermissionCatalog`), no el bloque de configuración. Solo en
     * estos dos ficheros y nunca como argumento de una lectura de configuración.
     */
    private const DOMINIO_TELEPHONY = ['app/Support/Access/CrmPermission.php', 'app/Support/Access/PermissionCatalog.php'];

    /**
     * La red, solo aquí: fichero → método → verbos (D8-3: una petición por
     * método, sin reintentos ni redirecciones). E5-2: `describeNumber`, un GET
     * a `/v1/convai/phone-numbers/{id}` ({@see RUTA_DEL_NUMERO}).
     */
    private const RED = [
        self::PROVEEDOR => ['verifyCredentials' => ['get']],
        self::DESPLEGADOR => ['createAgent' => ['post'], 'describeAgent' => ['get'], 'assignNumber' => ['patch', 'post'], 'describeNumber' => ['get']],
        self::INICIADOR => ['startOutbound' => ['post']],
        self::EMISOR => ['signedSessionUrl' => ['get']],
        self::TRANSPORTISTA => ['hangup' => ['post'], 'listCalls' => ['get']],
    ];

    /**
     * Una sola petición: la saliente (D8-3: «UNA petición, sin reintentos ni
     * redirecciones»), el GET del H6, la URL firmada, asignar el número, colgar
     * y la página de la conciliación. Crear y leer un agente pueden hacer más
     * de una (la API de ElevenLabs separa las herramientas del agente), siempre
     * con su verbo.
     */
    private const UNA_PETICION = [
        self::PROVEEDOR.'::verifyCredentials', self::INICIADOR.'::startOutbound', self::EMISOR.'::signedSessionUrl',
        self::DESPLEGADOR.'::assignNumber', self::TRANSPORTISTA.'::hangup', self::TRANSPORTISTA.'::listCalls',
        // E5-2: el GET del número, uno.
        self::DESPLEGADOR.'::describeNumber',
    ];

    /** E5-2: el único fichero que llama a `describeNumber` (`VoiceReconciler::numbers()`, desde `voice:reconcile --numbers`). */
    private const OBSERVA_EL_NUMERO = 'app/Services/Telephony/VoiceReconciler.php';

    /** E5-2: adónde va el GET de `describeNumber` (`ElevenLabsAgentDeployer::PHONE_NUMBERS_PATH` + el id codificado). */
    private const RUTA_DEL_NUMERO = '/v1/convai/phone-numbers/';

    /** La cadena del GET del H6, exacta. */
    private const CADENA_H6 = ['withHeaders', 'acceptJson', 'withoutRedirecting', 'connectTimeout', 'timeout', 'get'];

    /** Lo único que puede ir en una cadena `Http::` del H8, además del verbo final. */
    private const CADENA_PERMITIDA = ['withheaders', 'withbasicauth', 'withtoken', 'acceptjson', 'asjson', 'asform', 'withoutredirecting',
        'connecttimeout', 'timeout', 'baseurl', 'withqueryparameters'];

    /** Métodos de un cliente HTTP que mandan algo o abren otra vía: fuera de una cadena enumerada, nunca. */
    private const VERBOS = ['post', 'put', 'patch', 'delete', 'head', 'send', 'sendrequest', 'asform', 'asmultipart', 'attach', 'withbody', 'pool',
        'async', 'retry', 'withoptions', 'sink', 'withmiddleware', 'withrequestmiddleware', 'withresponsemiddleware'];

    /** Primitivas de red (o de proceso, que también la abren) que no pasan por `Http::`. */
    private const RED_PRIMITIVA = ['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'file_get_contents',
        'fopen', 'get_headers', 'socket_create', 'mail', 'stream_context_create', 'proc_open', 'exec', 'shell_exec', 'system', 'passthru', 'popen'];

    /** Clientes de red con nombre propio. */
    private const CLIENTE_DE_RED = '/GuzzleHttp|HttpClient|Psr\\\\Http|Illuminate\\\\Http\\\\Client\\\\(Factory|PendingRequest|Pool)\b/';

    /** Lo que un espacio neutro y sin estado no usa. */
    private const IMPURO = ['DB', 'Schema', 'Log', 'Cache', 'Queue', 'Bus', 'Storage', 'Mail', 'Notification', 'Event', 'Session', 'Redis',
        'dispatch', 'event', 'cache', 'logger', 'report', 'info', 'now', 'today', 'Carbon', 'CarbonImmutable', 'env', 'session'];

    /**
     * Lo que la voz llama FUERA de sus directorios y tampoco sale a la red (Enmienda 9, E9-2; SEC8-b): el podador
     * de la caché de `voice:prune-rate-limits`, que el bloque de voz programa a diario. Fichero a fichero y
     * existente (`php()` se salta en silencio una ruta que no existe); lo que la voz use de `App\Support\Cache`
     * tiene que estar aquí ({@see test_lo_que_la_voz_usa_de_support_cache_esta_enumerado_y_sin_red()}).
     */
    private const SIN_RED_FUERA_DE_LA_VOZ = ['app/Support/Cache/ExpiredCachePruner.php'];

    /** La telefonía con estado (D8-2) y el gobierno de la voz: orquestan, guardan y registran, pero no salen a la red. */
    private const SIN_RED = ['app/Services/Telephony', 'app/Models/Voice', 'app/Http/Controllers/Api/Voice', 'app/Http/Controllers/Crm/Voice',
        'app/Jobs/Voice', 'app/Console/Commands/Voice', 'routes/voice.php', ...self::SIN_RED_FUERA_DE_LA_VOZ];

    private const STUDIO_VOZ = ['app/Services/UltronStudio/StudioVoice*.php', 'app/Http/Controllers/Crm/UltronStudio/UltronStudioVoice*.php'];

    /** Los únicos hosts a los que la voz habla (ElevenLabsConfig::REGIONS y la API de Twilio). */
    private const HOSTS_OFICIALES = ['api.elevenlabs.io', 'api.eu.residency.elevenlabs.io', 'api.twilio.com'];

    /** Nombres de llamada: un método público así solo existe donde el contrato lo pone. */
    private const ACCION_DE_LLAMADA = '/^(call|dial|place|start|initiate|register|transfer|outbound|originate|make|connect|ring|hangup|send)/i';

    /** Dónde puede haber un método público con nombre de llamada (H8). */
    private const ACCIONES_PERMITIDAS = [
        'VoiceCallStarter.php' => ['startOutbound'],
        'ElevenLabs/ElevenLabsCallStarter.php' => ['startOutbound'],
        'TelephonyCarrier.php' => ['hangup'],
        // La fábrica devuelve el iniciador; no llama (no usa la red, comprobado arriba).
        'VoiceAdapters.php' => ['callStarter'],
    ];

    /** Las pruebas de voz (las del H6 y las que el contrato asigna en el H8). */
    private const PRUEBAS_DE_VOZ = ['tests/Unit/Voice', 'tests/Feature/Voice', 'tests/Unit/Telephony', 'tests/Feature/Telephony'];

    /** Además, por nombre, en cualquier sitio de `tests/`. */
    private const PRUEBAS_DE_VOZ_POR_NOMBRE = ['*H8*Test.php'];

    /** Y por directorio y nombre. */
    private const PRUEBAS_DE_VOZ_POR_RUTA = ['tests/Unit/Twilio/*Voice*Test.php', 'tests/Feature/UltronStudio/*Voice*Test.php', 'tests/Unit/UltronStudio/*Voice*Test.php'];

    /** Lo que en una prueba es «hablar con algo»: los adaptadores con red. */
    private const ADAPTADORES_CON_RED = ['ElevenLabsVoiceProvider', 'ElevenLabsAgentDeployer', 'ElevenLabsCallStarter', 'ElevenLabsLabSessionIssuer',
        'TwilioVoiceCarrier', 'VoiceProviders'];

    // ── Lista cerrada y la constante ────────────────────────────────────────

    public function test_estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato(): void
    {
        $paquete = self::paquete();
        $presentes = array_keys($paquete);
        foreach (self::PAQUETE_H6 as $fichero) {
            $this->assertContains($fichero, $presentes, "falta {$fichero} (H6)");
        }

        $nulos = [];
        foreach ($presentes as $fichero) {
            if (in_array($fichero, self::PAQUETE_H6, true) || in_array($fichero, self::PAQUETE_H8, true)) {
                continue;
            }
            if (str_starts_with($fichero, self::DTO)) {
                $this->assertStringNotContainsString('/', substr($fichero, strlen(self::DTO)), "{$fichero}: Dto/ no tiene subdirectorios");
                $this->assertStringEndsWith('.php', $fichero);
                $this->assertInerte($paquete[$fichero], $fichero);

                continue;
            }
            $this->assertMatchesRegularExpression(self::NULO, $fichero, "{$fichero} no está en la lista cerrada del contrato H8 (D8-3): un fichero nuevo del paquete pasa por SEC8");
            $nulos[] = $fichero;
        }

        // Cada nulo implementa UNA interfaz, distinta de la de los demás, y no sale a la red ni lee configuración.
        $implementadas = [];
        foreach ($nulos as $nulo) {
            $interfaces = self::implementa($paquete[$nulo]);
            $this->assertCount(1, $interfaces, "{$nulo} tiene que implementar una sola interfaz del paquete");
            $this->assertArrayHasKey($interfaces[0], self::INTERFACES, "{$nulo} implementa {$interfaces[0]}, que no es una interfaz del paquete");
            $this->assertNotContains($interfaces[0], $implementadas, "dos nulos para {$interfaces[0]}");
            $implementadas[] = $interfaces[0];
            $this->assertNotContains('Http', self::usos($paquete[$nulo]), "{$nulo}: un nulo no sale a la red");
            $this->assertSame([], self::lecturasDeConfiguracion($paquete[$nulo]), "{$nulo}: un nulo no lee la configuración");
        }

        // Los comandos de voz: el doctor y, con su forma, los del H8.
        $comandos = array_map('basename', array_keys(self::php('app/Console/Commands/Voice')));
        $this->assertContains(self::DOCTOR, $comandos);
        foreach ($comandos as $comando) {
            $this->assertMatchesRegularExpression(self::COMANDO, $comando, "{$comando} no es un comando de voz con nombre de comando");
        }

        // El transportista y el validador de Twilio de la voz: como mucho uno de cada sitio.
        $firmas = array_values(array_filter(self::FIRMA_TWILIO, static fn (string $f): bool => is_file(self::raiz().'/'.$f)));
        $this->assertLessThanOrEqual(1, count($firmas), 'TwilioRequestSignature está en dos sitios');
    }

    public function test_las_llamadas_estan_cerradas_en_el_codigo(): void
    {
        $this->assertFalse(VoiceCallPolicy::CALLS_ENABLED_IN_CODE);
        $codigo = (string) file_get_contents(self::raiz().'/app/Services/Voice/VoiceCallPolicy.php');
        $this->assertSame(1, preg_match_all('/public const CALLS_ENABLED_IN_CODE = false;/', $codigo), 'la constante, literal y una sola vez');

        // H8: nadie la declara otra vez, la lee de otra clase ni la nombra dentro de una cadena.
        $declaraciones = [];
        foreach (self::php('app', 'routes', 'config', 'bootstrap', 'database') as $relativa => $ruta) {
            if (! str_contains((string) file_get_contents($ruta), 'ENABLED_IN_CODE')) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $this->assertStringNotContainsString('CALLS_ENABLED_IN_CODE', $t[1], "{$relativa} nombra la constante dentro de una cadena");
                }
                if ($t[0] !== T_STRING || $t[1] !== 'CALLS_ENABLED_IN_CODE') {
                    continue;
                }
                $antes = self::anterior($tokens, $i);
                if (($antes[0] ?? null) === T_CONST) {
                    $declaraciones[] = $relativa;

                    continue;
                }
                $this->assertSame(T_DOUBLE_COLON, $antes[0] ?? null, "{$relativa} usa CALLS_ENABLED_IN_CODE sin su clase");
                $clase = $tokens[$i - 2][1] ?? '';
                $propia = $relativa === 'app/Services/Voice/VoiceCallPolicy.php' && in_array(strtolower($clase), ['self', 'static'], true);
                $this->assertTrue($propia || self::corto($clase) === 'VoiceCallPolicy', "{$relativa} lee CALLS_ENABLED_IN_CODE de {$clase}: solo existe la de VoiceCallPolicy");
            }
        }
        $this->assertSame(['app/Services/Voice/VoiceCallPolicy.php'], $declaraciones, 'la constante se declara una vez, en VoiceCallPolicy');
    }

    // ── La red ──────────────────────────────────────────────────────────────

    public function test_la_red_solo_sale_de_los_metodos_enumerados(): void
    {
        $cadenas = [];
        foreach (self::conRedPosible() as $relativa => $ruta) {
            $tokens = self::tokens($ruta);
            $metodos = self::rangosDeMetodos($tokens);
            $enCadena = [];
            foreach ($tokens as $i => $t) {
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && self::corto($t[1]) === 'Http' && (self::siguiente($tokens, $i)[0] ?? null) === T_DOUBLE_COLON) {
                    [$nombres, $posiciones] = self::cadenaHttp($tokens, $i);
                    $cadenas[] = ['fichero' => $relativa, 'metodo' => self::metodoQueContiene($metodos, $i), 'cadena' => $nombres];
                    foreach ($posiciones as $p) {
                        $enCadena[$p] = true;
                    }
                }
            }
            foreach ($tokens as $i => $t) {
                $antes = self::anterior($tokens, $i);
                $esLlamadaAMetodo = in_array($antes[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                if ($t[0] === T_STRING && $esLlamadaAMetodo && in_array(strtolower($t[1]), self::VERBOS, true) && ! isset($enCadena[$i])) {
                    $this->fail("{$relativa} llama a ->{$t[1]}() fuera de una cadena Http:: de un método enumerado");
                }
                if ($t[0] === T_STRING && ! $esLlamadaAMetodo && ($antes[0] ?? null) !== T_FUNCTION && in_array(strtolower($t[1]), self::RED_PRIMITIVA, true) && (self::siguiente($tokens, $i)[1] ?? null) === '(') {
                    $this->fail("{$relativa} usa {$t[1]}(): la red solo sale por Http:: en los adaptadores enumerados");
                }
                if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match(self::CLIENTE_DE_RED, $t[1]) === 1) {
                    $this->fail("{$relativa} nombra {$t[1]}: la red solo sale por Http:: en los adaptadores enumerados");
                }
            }
        }

        $porMetodo = [];
        foreach ($cadenas as $c) {
            ['fichero' => $fichero, 'metodo' => $metodo, 'cadena' => $cadena] = $c;
            $this->assertArrayHasKey($fichero, self::RED, "{$fichero} sale a la red y no es un adaptador enumerado (D8-3)");
            $this->assertArrayHasKey((string) $metodo, self::RED[$fichero], "{$fichero} sale a la red desde ".($metodo ?? 'fuera de un método').', que no es un método enumerado');
            $this->assertNotSame([], $cadena, "{$fichero}::{$metodo}: una cadena Http:: vacía");
            $verbo = strtolower((string) end($cadena));
            $this->assertContains($verbo, self::RED[$fichero][$metodo], "{$fichero}::{$metodo} termina en ->{$verbo}(): no es su verbo");
            foreach (array_slice($cadena, 0, -1) as $paso) {
                $this->assertContains(strtolower($paso), self::CADENA_PERMITIDA, "{$fichero}::{$metodo} usa ->{$paso}() en la cadena: ni reintentos, ni opciones, ni redirecciones");
            }
            $this->assertContains('withoutRedirecting', $cadena, "{$fichero}::{$metodo}: la credencial no viaja a otro host");
            $this->assertContains('timeout', $cadena, "{$fichero}::{$metodo}: sin plazo");
            $porMetodo["{$fichero}::{$metodo}"] = ($porMetodo["{$fichero}::{$metodo}"] ?? 0) + 1;
            if ($fichero === self::PROVEEDOR) {
                $this->assertSame(self::CADENA_H6, $cadena, 'el GET del H6 no cambia');
            }
        }
        foreach ($porMetodo as $donde => $n) {
            if (in_array($donde, self::UNA_PETICION, true)) {
                $this->assertSame(1, $n, "{$donde}: una sola petición (D8-3)");
            }
        }
        // La prueba ve lo que tiene que ver: el GET del H6.
        $this->assertSame(1, $porMetodo[self::PROVEEDOR.'::verifyCredentials'] ?? 0, 'el rastreo no ve el GET de verifyCredentials');

        // Fuera de los adaptadores, nadie sale a la red: la telefonía, los
        // trabajos, los comandos y el gobierno de la voz van por las interfaces.
        foreach (self::php(...self::SIN_RED) + self::glob(...self::STUDIO_VOZ) as $relativa => $ruta) {
            $this->assertNotContains('Http', self::usos($ruta), "{$relativa} sale a la red: tiene que ir por las interfaces de D8-3");
            foreach (self::tokens($ruta) as $i => $t) {
                if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match(self::CLIENTE_DE_RED, $t[1]) === 1) {
                    $this->fail("{$relativa} nombra {$t[1]}");
                }
            }
            foreach (self::RED_PRIMITIVA as $primitiva) {
                $this->assertNotContains($primitiva, self::funcionesLlamadas($ruta), "{$relativa} usa {$primitiva}()");
            }
        }
    }

    /**
     * Enmienda 9, E9-2 (SEC8-b): lo que la voz usa de `App\Support\Cache` (hoy, el podador de
     * `voice:prune-rate-limits`) está enumerado en {@see SIN_RED_FUERA_DE_LA_VOZ}, existe y, por estar en
     * {@see SIN_RED}, lo mira la guarda de la red. Una clase de `app/Support/Cache` que la voz llame sin
     * enumerarla, o el espacio entero importado, cae aquí: si no, saldría a la red sin que nadie lo viera.
     */
    public function test_lo_que_la_voz_usa_de_support_cache_esta_enumerado_y_sin_red(): void
    {
        foreach (self::SIN_RED_FUERA_DE_LA_VOZ as $fichero) {
            $this->assertFileExists(self::raiz().'/'.$fichero, "{$fichero} está en SIN_RED: si se movió o se renombró, la guarda de la red dejaría de mirarlo en silencio");
        }
        $vistos = 0;
        foreach (self::php('app/Services/Voice', ...self::SIN_RED) + self::glob(...self::STUDIO_VOZ) as $relativa => $ruta) {
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                // La declaración `namespace …;` de un fichero no es un uso.
                $nombre = in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && (self::anterior($tokens, $i)[0] ?? null) !== T_NAMESPACE ? ltrim($t[1], '\\') : '';
                if ($nombre === 'App\\Support\\Cache') {
                    $this->fail("{$relativa} nombra el espacio App\\Support\\Cache entero: cada clase, por su nombre y enumerada (E9-2)");
                }
                if (! str_starts_with($nombre, 'App\\Support\\Cache\\')) {
                    continue;
                }
                $vistos++;
                $fichero = 'app/'.str_replace('\\', '/', substr($nombre, strlen('App\\'))).'.php';
                $this->assertContains($fichero, self::SIN_RED_FUERA_DE_LA_VOZ, "{$relativa} usa {$nombre}: lo que la voz llama de app/Support/Cache va en SIN_RED_FUERA_DE_LA_VOZ (E9-2), para que la guarda de la red lo mire");
            }
        }
        $this->assertGreaterThanOrEqual(1, $vistos, 'el rastreo ve el podador que usa voice:prune-rate-limits');
    }

    public function test_el_transportista_solo_cuelga_y_lista(): void
    {
        $ruta = self::raiz().'/'.self::TRANSPORTISTA;
        if (! is_file($ruta)) {
            $this->assertFileDoesNotExist($ruta);

            return;
        }
        // Colgar es pasar la llamada a `completed`: nada de TwiML, ni URL, ni otro destino.
        $literales = self::literales($ruta);
        $this->assertContains('completed', $literales, 'colgar es Status=completed');
        foreach (['Twiml', 'Url', 'Method', 'To', 'From', 'CallerId', 'ApplicationSid', 'SendDigits', 'FallbackUrl', 'StatusCallback'] as $parametro) {
            $this->assertNotContains($parametro, $literales, "el transportista manda {$parametro}: con la key de Laravel una llamada viva se podría desviar");
        }
        // Colgar actualiza `Calls/{sid}.json`; `Calls.json` con POST crearía una llamada.
        $this->assertStringNotContainsString('Calls.json', self::codigoDeMetodo($ruta, 'hangup'), 'colgar no crea una llamada');

        // Nunca sigue una URL que venga en una respuesta (la credencial viajaría
        // con ella): ninguna cadena Http:: lleva `next_page_*`, y la URL de cada
        // verbo empieza por un método propio que decide la base con isProduction().
        $tokens = self::tokens($ruta);
        $metodos = self::rangosDeMetodos($tokens);
        $cadenas = 0;
        foreach ($tokens as $i => $t) {
            if (! ($t[0] === T_STRING && $t[1] === 'Http' && (self::siguiente($tokens, $i)[0] ?? null) === T_DOUBLE_COLON)) {
                continue;
            }
            $cadenas++;
            [$nombres, $posiciones] = self::cadenaHttp($tokens, $i);
            $verbo = end($posiciones);
            $this->assertNotFalse($verbo, 'una cadena Http:: sin verbo');
            $fin = $verbo;
            for ($profundidad = 0, $j = $verbo + 1; isset($tokens[$j]); $j++) {
                $profundidad += $tokens[$j][1] === '(' ? 1 : ($tokens[$j][1] === ')' ? -1 : 0);
                if ($profundidad === 0) {
                    $fin = $j;
                    break;
                }
            }
            $texto = implode('', array_map(static fn (array $x): string => $x[1], array_slice($tokens, $i, $fin - $i + 1)));
            $this->assertDoesNotMatchRegularExpression('/next_page_ur[il]/', $texto, 'el transportista pide una URL que viene en una respuesta');
            // El primer argumento del verbo: self::x(…), static::x(…) o $this->x(…), y x decide la base en production.
            $a = $verbo + 2;
            $propio = in_array(strtolower($tokens[$a][1] ?? ''), ['self', 'static'], true) && ($tokens[$a + 1][0] ?? null) === T_DOUBLE_COLON
                || ($tokens[$a][1] ?? '') === '$this' && ($tokens[$a + 1][0] ?? null) === T_OBJECT_OPERATOR;
            $this->assertTrue($propio && ($tokens[$a + 3][1] ?? null) === '(', "la URL de ->{$tokens[$verbo][1]}() no la construye un método propio del transportista");
            $base = '';
            foreach ($metodos as [$nombre, $desde, $hasta]) {
                if ($nombre === ($tokens[$a + 2][1] ?? '')) {
                    $base = implode(' ', array_map(static fn (array $x): string => $x[1], array_slice($tokens, $desde, $hasta - $desde + 1)));
                }
            }
            $this->assertStringContainsString('isProduction', $base, "{$tokens[$a + 2][1]}() no decide la base con isProduction()");
        }
        $this->assertGreaterThanOrEqual(2, $cadenas, 'el rastreo ve colgar y listar');
    }

    public function test_outbound_call_solo_en_el_iniciador_y_nada_de_register_call_lotes_sip_ni_whatsapp(): void
    {
        $nombran = [];
        foreach (self::php('app', 'routes', 'config') as $relativa => $ruta) {
            $codigo = strtolower(self::codigo($ruta));
            if (str_contains($codigo, 'outbound-call')) {
                $nombran[] = $relativa;
            }
            foreach (['register-call', 'batch-calling', 'batch_calling', 'sip-trunk', 'sip_trunk'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $codigo, "{$relativa} nombra {$prohibido}: fuera del H8");
            }
        }
        $this->assertContains($nombran, [[], [self::INICIADOR]], 'outbound-call solo en ElevenLabsCallStarter: '.implode(', ', $nombran));

        foreach (self::paquete() as $relativa => $ruta) {
            $this->assertStringNotContainsStringIgnoringCase('whatsapp', self::codigo($ruta), "{$relativa}: la voz no usa la integración de WhatsApp de ElevenLabs (protección 6)");
        }
    }

    public function test_hosts_oficiales_y_la_base_se_decide_en_produccion(): void
    {
        $vistos = 0;
        foreach (self::paquete() + self::php(self::TRANSPORTISTA, ...self::FIRMA_TWILIO, ...self::SIN_RED) + self::php('config/voice.php') as $relativa => $ruta) {
            foreach (self::tokens($ruta) as $t) {
                if (! in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $texto = $t[0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($t[1]) : $t[1];
                if (preg_match_all('#([a-z][a-z0-9+.-]*)://([^/\s\'"{$:?\#]*)#i', $texto, $urls, PREG_SET_ORDER) < 1) {
                    continue;
                }
                foreach ($urls as $url) {
                    $vistos++;
                    $this->assertSame('https', strtolower($url[1]), "{$relativa} escribe una URL {$url[1]}://");
                    $this->assertContains(strtolower($url[2]), self::HOSTS_OFICIALES, "{$relativa} escribe una URL a {$url[2]}: solo hosts oficiales, y el simulador llega por configuración fuera de production");
                }
            }
            if (in_array('Http', self::usos($ruta), true)) {
                $codigo = self::codigo($ruta);
                $decide = str_contains($codigo, 'isProduction()')
                    || (str_contains($codigo, 'ElevenLabsConfig::fromConfig()') && str_contains($codigo, 'baseUrl()'));
                $this->assertTrue($decide, "{$relativa} sale a la red sin decidir la base con isProduction() ni con ElevenLabsConfig::baseUrl()");
            }
        }
        $this->assertGreaterThanOrEqual(2, $vistos, 'el rastreo ve las URL de las regiones de ElevenLabsConfig');
    }

    // ── Secretos y configuración ────────────────────────────────────────────

    public function test_los_secretos_solo_en_sus_lectores_enumerados(): void
    {
        $lecturas = [];
        $bloques = [];
        foreach (self::php('app', 'routes') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            if (stripos($todo, 'voice') === false && stripos($todo, 'telephony') === false) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $valor = self::literal($t[1]);
                if (preg_match('/^(voice|telephony)\.[a-z0-9_.]*[a-z0-9_]$/i', $valor) === 1 && self::esSecreto($valor)) {
                    $lecturas[$valor][$relativa] = true;
                }
                $esBloque = in_array($valor, ['voice.elevenlabs', 'voice.twilio', 'telephony'], true)
                    || ($valor === 'voice' && self::esLecturaDeConfiguracion($tokens, $i))
                    // Un prefijo para componer la clave (`'voice.twilio.'.$x`), también de un solo segmento (`'telephony.'.$x`, SEC8-b).
                    || preg_match('/^(voice|telephony)\.([a-z0-9_.]*\.)?$/i', $valor) === 1;
                // E4-4: el dominio de permisos `telephony` (D8-22), solo en sus dos ficheros y sin leer la configuración.
                $esDominio = $valor === 'telephony' && in_array($relativa, self::DOMINIO_TELEPHONY, true) && ! self::esLecturaDeConfiguracion($tokens, $i);
                if ($esBloque && ! $esDominio) {
                    $bloques[$relativa][] = $valor;
                }
            }
        }

        foreach ($lecturas as $clave => $ficheros) {
            $lectores = self::lectoresDe($clave);
            $this->assertNotNull($lectores, "{$clave} es un secreto de voz sin lector enumerado en el contrato (D8-5): pasa por SEC8");
            foreach (array_keys($ficheros) as $fichero) {
                $this->assertContains($fichero, $lectores, "{$fichero} lee {$clave} y no es su lector enumerado (D8-5)");
            }
        }
        // El punto único de las huellas sigue leyendo los tres del H6.
        foreach (['voice.elevenlabs.api_key', 'voice.elevenlabs.webhook_secret', 'voice.elevenlabs.tool_secret'] as $h6) {
            $this->assertArrayHasKey(self::PROVEEDOR, $lecturas[$h6] ?? [], "el rastreo no ve {$h6} en ElevenLabsVoiceProvider");
        }
        $this->assertSame([self::CONFIGURACION => ['voice.elevenlabs']], $bloques, 'solo ElevenLabsConfig lee un bloque de la voz (y lo reduce a «hay o no hay»)');

        // Dentro del paquete, cada fichero lee lo suyo y nada más.
        foreach (self::paquete() as $relativa => $ruta) {
            $lee = self::lecturasDeConfiguracion($ruta);
            if ($relativa === 'ElevenLabs/ElevenLabsConfig.php') {
                $this->assertSame(['voice.elevenlabs'], $lee);
            } elseif ($relativa === 'ElevenLabs/ElevenLabsVoiceProvider.php') {
                foreach ($lee as $clave) {
                    $this->assertTrue(self::esSecreto($clave), "ElevenLabsVoiceProvider lee {$clave}: solo los secretos, para verificar y para las huellas");
                }
            } else {
                $permitidas = match ($relativa) {
                    'ElevenLabs/ElevenLabsAgentDeployer.php', 'ElevenLabs/ElevenLabsLabSessionIssuer.php' => ['voice.elevenlabs.api_key'],
                    'ElevenLabs/ElevenLabsCallStarter.php' => ['voice.elevenlabs.agent_phone_number_id', 'voice.elevenlabs.api_key'],
                    'ElevenLabs/ElevenLabsEventVerifier.php' => ['voice.elevenlabs.webhook_secret'],
                    default => [],
                };
                $this->assertSame([], array_values(array_diff($lee, $permitidas)), "{$relativa} lee la configuración más allá de lo suyo");
            }
        }
        $this->assertSame([], self::lecturasDeConfiguracion(self::raiz().'/app/Console/Commands/Voice/'.self::DOCTOR), 'el doctor no lee la configuración');
    }

    public function test_nadie_fuera_de_su_fichero_de_configuracion_lee_las_variables_de_voz_del_entorno(): void
    {
        $leen = ['voz' => [], 'studio' => []];
        foreach (self::php('app', 'routes', 'config', 'bootstrap', 'database') as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            if (! str_contains($todo, 'ELEVENLABS_') && ! str_contains($todo, 'TWILIO_VOICE_') && ! str_contains($todo, 'VOICE_')) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] !== T_CONSTANT_ENCAPSED_STRING || preg_match('/^(ELEVENLABS_|TWILIO_VOICE_|VOICE_|ULTRON_STUDIO_VOICE_)/', self::literal($t[1]), $m) !== 1) {
                    continue;
                }
                $antes = self::anterior($tokens, $i);
                $antesDe = $tokens[$i - 2] ?? null;
                $esLectura = ($antes[1] ?? null) === '(' && $antesDe !== null && $antesDe[0] === T_STRING && in_array(strtolower($antesDe[1]), ['env', 'getenv', 'get'], true)
                    || ($antes[1] ?? null) === '[' && $antesDe !== null && in_array($antesDe[1], ['$_ENV', '$_SERVER'], true);
                if ($esLectura) {
                    $leen[$m[1] === 'ULTRON_STUDIO_VOICE_' ? 'studio' : 'voz'][$relativa] = true;
                }
            }
        }

        $this->assertSame(['config/voice.php'], array_keys($leen['voz']), 'las variables de voz solo se leen en config/voice.php');
        $this->assertContains(array_keys($leen['studio']), [[], ['config/ultron_studio.php']], 'las del laboratorio hablado, solo en config/ultron_studio.php');
    }

    // ── Neutro, sin estado y el puerto ──────────────────────────────────────

    public function test_el_espacio_es_neutro_y_sin_estado(): void
    {
        $red = array_map(static fn (string $f): string => substr($f, strlen('app/Services/Voice/')), array_keys(self::RED));
        foreach (self::paquete() as $relativa => $ruta) {
            $todo = (string) file_get_contents($ruta);
            foreach (['UltronStudio', 'ultron_studio', 'ultron-studio', 'PhoneFingerprint', 'App\\Services\\Marketing', 'App\\Models\\', 'App\\Services\\Telephony', 'App\\Jobs\\'] as $ajeno) {
                $this->assertStringNotContainsString($ajeno, $todo, "{$relativa} nombra {$ajeno}");
            }
            foreach (self::tokens($ruta) as $t) {
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertDoesNotMatchRegularExpression('/Studio|Ultron|Marketing|Telephony\\\\/', $t[1], "{$relativa} nombra {$t[1]}");
                    // La telefonía depende de la voz, no al revés; de Twilio, solo una fábrica nombra el transportista.
                    if (preg_match('/Services\\\\Twilio\\\\/', $t[1]) === 1) {
                        $this->assertTrue(in_array($relativa, ['VoiceProviders.php', 'VoiceAdapters.php'], true) && self::corto($t[1]) === 'TwilioVoiceCarrier', "{$relativa} nombra {$t[1]}");
                    }
                }
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING && ! ($relativa === 'VoiceCallContext.php' && self::literal($t[1]) === 'marketing_calls')) {
                    // Claves de configuración o tablas del Studio y del motor (una etiqueta de contrato `ultron-voice-…/1` no lo es).
                    // La única excepción es la etiqueta del H6 del origen PROHIBIDO (`VoiceCallContext::SOURCE_MARKETING_CALLS`).
                    $this->assertDoesNotMatchRegularExpression('/^(ultron[._]|marketing[._]|meta\.|otp\.)/i', self::literal($t[1]), "{$relativa} nombra {$t[1]}");
                }
            }
            $usos = self::usos($ruta);
            foreach (self::IMPURO as $impuro) {
                $this->assertNotContains($impuro, $usos, "{$relativa} usa {$impuro}");
            }
            if (! in_array($relativa, $red, true)) {
                $this->assertNotContains('Http', $usos, "{$relativa} usa la red");
            }
        }

        $usos = self::usos(self::raiz().'/app/Console/Commands/Voice/'.self::DOCTOR);
        foreach (['DB', 'Schema', 'Storage', 'Cache', 'Queue', 'Bus', 'Log', 'Model', 'dispatch', 'logger', 'report', 'env'] as $impuro) {
            $this->assertNotContains($impuro, $usos, "el doctor usa {$impuro}: no guarda ni registra nada");
        }
        foreach (self::php('app/Console/Commands/Voice') as $relativa => $ruta) {
            $this->assertNotContains('Http', self::usos($ruta), "{$relativa} usa la red: va por los adaptadores");
        }
    }

    public function test_el_puerto_no_sabe_llamar_a_nadie(): void
    {
        $paquete = self::paquete();

        // Las interfaces que existan, con sus métodos cerrados.
        $vistas = 0;
        foreach (self::INTERFACES as $interfaz => $metodos) {
            $candidatos = array_values(array_filter(array_keys($paquete), static fn (string $f): bool => basename($f) === "{$interfaz}.php"));
            $this->assertLessThanOrEqual(1, count($candidatos), "{$interfaz} está en dos sitios");
            if ($candidatos === []) {
                continue;
            }
            $vistas++;
            $declarados = array_keys(self::metodosDeclarados($paquete[$candidatos[0]]));
            sort($declarados);
            $this->assertSame($metodos, $declarados, "{$interfaz}: sus métodos son los del contrato (ninguno actualiza ni borra un agente)");
        }
        $this->assertGreaterThanOrEqual(1, $vistas, 'el rastreo ve el puerto del H6');

        // Un método público con nombre de llamada, solo donde el contrato lo
        // pone. Los DTO de `Dto/` y el traductor son puros (sin red, base ni
        // registro, comprobado arriba): sus getters (`transferMode()`) son datos.
        foreach ($paquete as $relativa => $ruta) {
            if (str_starts_with($relativa, self::DTO) || $relativa === 'ElevenLabs/ElevenLabsAgentMapper.php') {
                continue;
            }
            $nulo = preg_match(self::NULO, $relativa) === 1;
            $permitidos = self::ACCIONES_PERMITIDAS[$relativa] ?? [];
            if ($nulo) {
                $interfaz = self::implementa($ruta)[0] ?? '';
                $permitidos = self::ACCIONES_PERMITIDAS["{$interfaz}.php"] ?? [];
            }
            foreach (self::metodosDeclarados($ruta) as $nombre => $publico) {
                if ($publico && ! in_array($nombre, $permitidos, true)) {
                    $this->assertDoesNotMatchRegularExpression(self::ACCION_DE_LLAMADA, $nombre, "{$relativa}::{$nombre}");
                }
            }
        }
    }

    /**
     * E5-2 (Enmienda 5; E4-8, E2-3): el número se observa con UN GET a su ruta
     * y solo desde la conciliación. Nadie más llama a `describeNumber` (con
     * `->`, `?->` o `::`), nadie lo nombra en una cadena (una llamada dinámica),
     * y el GET va a `/v1/convai/phone-numbers/{id}` con el id codificado: ni
     * otra ruta del proveedor (una conversación, su transcripción) ni el id en
     * crudo. El verbo, la petición única, las redirecciones y los reintentos los
     * mira {@see test_la_red_solo_sale_de_los_metodos_enumerados()}.
     */
    public function test_el_numero_se_observa_con_un_get_a_su_ruta_y_solo_desde_la_conciliacion(): void
    {
        $llaman = [];
        foreach (self::php('app', 'routes') as $relativa => $ruta) {
            if (stripos((string) file_get_contents($ruta), 'describeNumber') === false) {
                continue;
            }
            $tokens = self::tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] === T_STRING && $t[1] === 'describeNumber' && in_array(self::anterior($tokens, $i)[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $llaman[$relativa] = true;
                }
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING && strcasecmp(self::literal($t[1]), 'describeNumber') === 0) {
                    $this->fail("{$relativa} nombra describeNumber en una cadena: solo VoiceReconciler la llama, y de forma directa (E5-2)");
                }
            }
        }
        $this->assertSame([self::OBSERVA_EL_NUMERO], array_keys($llaman), 'describeNumber se llama solo desde VoiceReconciler (E5-2): '.implode(', ', array_keys($llaman)));

        $desplegador = self::raiz().'/'.self::DESPLEGADOR;
        $codigo = self::codigoDeMetodo($desplegador, 'describeNumber');
        $this->assertStringContainsString('PHONE_NUMBERS_PATH', $codigo, 'describeNumber pide otra ruta que la del número (E5-2)');
        $this->assertStringContainsString('rawurlencode', $codigo, 'describeNumber pone el id del número en la ruta sin codificar (E5-2)');
        $this->assertMatchesRegularExpression('/const PHONE_NUMBERS_PATH = \''.preg_quote(self::RUTA_DEL_NUMERO, '/').'\';/', self::codigo($desplegador), 'la ruta del número no es '.self::RUTA_DEL_NUMERO.' (E5-2)');
    }

    // ── Rutas y pruebas ─────────────────────────────────────────────────────

    public function test_ninguna_ruta_apunta_a_un_servicio_de_voz_y_los_controladores_de_voz_viven_en_su_fichero(): void
    {
        foreach (self::php('routes') as $relativa => $ruta) {
            $codigo = self::codigo($ruta);
            foreach (['Services\\Voice', 'Services\\Telephony', 'Services\\Twilio'] as $servicio) {
                $this->assertStringNotContainsString($servicio, $codigo, "{$relativa} apunta a {$servicio}: una ruta la atiende un controlador");
            }
            $this->assertStringNotContainsString('Commands\\Voice', $codigo, "{$relativa} registra el comando de voz como clase");
            if ($relativa !== 'routes/voice.php') {
                $this->assertDoesNotMatchRegularExpression('/Controllers\\\\(Api|Crm)\\\\Voice\\\\/', $codigo, "{$relativa} usa un controlador de la telefonía");
                $this->assertDoesNotMatchRegularExpression('/elevenlabs/i', $codigo, "{$relativa} nombra ElevenLabs");
                $this->assertDoesNotMatchRegularExpression('#webhooks/voice|voice/tools|admin/voice#i', $codigo, "{$relativa} declara rutas de la telefonía");
            }
            if (! in_array($relativa, ['routes/voice.php', 'routes/ultron-studio.php'], true)) {
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]*Voice[A-Za-z]*Controller/', $codigo, "{$relativa} usa un controlador de voz");
            }
            if ($relativa !== 'routes/ultron-studio.php') {
                $this->assertDoesNotMatchRegularExpression('/UltronStudioVoice(Lab)?Controller/', $codigo, "{$relativa} usa el controlador de voz del Studio");
            }
        }
    }

    public function test_las_pruebas_de_voz_con_red_cierran_la_red(): void
    {
        $sinCerrar = [];
        $vistas = 0;
        foreach (self::pruebasDeVoz() as $relativa => $ruta) {
            if (! self::hablaConAlgo($ruta)) {
                continue;
            }
            $vistas++;
            if (! self::cierraLaRed($ruta, 0)) {
                $sinCerrar[] = $relativa;
            }
        }
        $this->assertSame([], $sinCerrar, "Pruebas de voz con red sin Http::preventStrayRequests() (protección 3):\n".implode("\n", $sinCerrar));
        $this->assertGreaterThanOrEqual(3, $vistas, 'el rastreo ve las pruebas de voz con red del H6');
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Un fichero de `Dto/`: ni red, ni configuración, ni entorno, ni reloj, ni base, ni registro; nada que llame. */
    private function assertInerte(string $ruta, string $relativa): void
    {
        $usos = self::usos($ruta);
        foreach (array_merge(self::IMPURO, ['Http', 'config', 'getenv', 'app', 'resolve']) as $impuro) {
            $this->assertNotContains($impuro, $usos, "{$relativa} usa {$impuro}: un DTO es inerte");
        }
        $this->assertSame([], self::lecturasDeConfiguracion($ruta), "{$relativa} lee la configuración");
        foreach (self::RED_PRIMITIVA as $primitiva) {
            $this->assertNotContains($primitiva, self::funcionesLlamadas($ruta), "{$relativa} usa {$primitiva}()");
        }
        // Un getter como `transferMode()` es un dato, no una acción: lo que no
        // puede es ser un proveedor (implementar una interfaz del paquete).
        $this->assertSame([], array_values(array_intersect(self::implementa($ruta), array_keys(self::INTERFACES))), "{$relativa} implementa una interfaz de proveedor");
    }

    /** ¿A qué responde esta clave? Los lectores de D8-5 (y el punto único de las huellas); null = secreto sin lector enumerado. */
    private static function lectoresDe(string $clave): ?array
    {
        $firma = self::FIRMA_TWILIO;

        return match (true) {
            $clave === 'voice.elevenlabs.api_key' => [self::PROVEEDOR, self::DESPLEGADOR, self::INICIADOR, self::EMISOR],
            $clave === 'voice.elevenlabs.webhook_secret' => [self::PROVEEDOR, self::VERIFICADOR],
            $clave === 'voice.elevenlabs.tool_secret' => [self::PROVEEDOR, self::AUTH_HERRAMIENTAS],
            $clave === 'voice.elevenlabs.initiation_secret' => [self::PROVEEDOR, self::AUTH_INICIACION],
            preg_match('/^voice\.twilio\.[a-z0-9_]*secret$/', $clave) === 1 => [self::PROVEEDOR, self::TRANSPORTISTA],
            preg_match('/^voice\.twilio\.[a-z0-9_]*token$/', $clave) === 1 => [self::PROVEEDOR, ...$firma],
            default => null,
        };
    }

    /** ¿Es secreta esta clave de configuración? (la clave, un secreto, un token o una contraseña; el SID de una key no) */
    private static function esSecreto(string $clave): bool
    {
        $ultimo = (string) substr((string) strrchr('.'.$clave, '.'), 1);

        return preg_match('/^(api_key|secret|token|password)$|_(secret|token|password)$/', $ultimo) === 1;
    }

    /** @return array<string,string> los ficheros donde la red es posible: el paquete y los adaptadores de Twilio de la voz */
    private static function conRedPosible(): array
    {
        $out = [];
        foreach (self::paquete() as $relativa => $ruta) {
            $out['app/Services/Voice/'.$relativa] = $ruta;
        }

        return $out + self::php(self::TRANSPORTISTA, ...self::FIRMA_TWILIO);
    }

    /**
     * La cadena que empieza en `Http::`: los métodos que se llaman a la
     * profundidad del propio `Http` (no los de sus argumentos), hasta el final
     * de la expresión.
     *
     * @param  list<array{0:int,1:string}>  $tokens
     * @return array{0:list<string>,1:list<int>}
     */
    private static function cadenaHttp(array $tokens, int $i): array
    {
        $nombres = [];
        $posiciones = [];
        $profundidad = 0;
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $texto = $tokens[$j][1];
            if (in_array($texto, ['(', '[', '{'], true) || in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $profundidad++;

                continue;
            }
            if (in_array($texto, [')', ']', '}'], true)) {
                $profundidad--;
                if ($profundidad < 0) {
                    break;
                }

                continue;
            }
            if ($profundidad === 0 && in_array($texto, [';', ',', '=>'], true)) {
                break;
            }
            if ($profundidad === 0 && $tokens[$j][0] === T_STRING && in_array($tokens[$j - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && ($tokens[$j + 1][1] ?? null) === '(') {
                $nombres[] = $texto;
                $posiciones[] = $j;
            }
        }

        return [$nombres, $posiciones];
    }

    /**
     * Los métodos con cuerpo de un fichero y sus límites (índices de las llaves).
     *
     * @param  list<array{0:int,1:string}>  $tokens
     * @return list<array{0:string,1:int,2:int}>
     */
    private static function rangosDeMetodos(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] !== T_FUNCTION || ($tokens[$i + 1][0] ?? null) !== T_STRING) {
                continue;
            }
            $parentesis = 0;
            for ($j = $i + 2; isset($tokens[$j]); $j++) {
                $texto = $tokens[$j][1];
                if ($texto === '(') {
                    $parentesis++;
                } elseif ($texto === ')') {
                    $parentesis--;
                } elseif ($parentesis === 0 && ($texto === ';' || $texto === '{')) {
                    break;
                }
            }
            if (($tokens[$j][1] ?? null) !== '{') {
                continue;
            }
            $profundidad = 0;
            for ($k = $j; isset($tokens[$k]); $k++) {
                if ($tokens[$k][1] === '{' || in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $profundidad++;
                } elseif ($tokens[$k][1] === '}') {
                    $profundidad--;
                    if ($profundidad === 0) {
                        $out[] = [$tokens[$i + 1][1], $j, $k];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /** @param  list<array{0:string,1:int,2:int}>  $metodos */
    private static function metodoQueContiene(array $metodos, int $i): ?string
    {
        $dentro = null;
        foreach ($metodos as [$nombre, $desde, $hasta]) {
            if ($i > $desde && $i < $hasta) {
                $dentro = $nombre;
            }
        }

        return $dentro;
    }

    /** El código de un método (sin comentarios), o '' si no está. */
    private static function codigoDeMetodo(string $ruta, string $metodo): string
    {
        $tokens = self::tokens($ruta);
        foreach (self::rangosDeMetodos($tokens) as [$nombre, $desde, $hasta]) {
            if ($nombre === $metodo) {
                return implode(' ', array_map(static fn (array $t): string => $t[1], array_slice($tokens, $desde, $hasta - $desde + 1)));
            }
        }

        return '';
    }

    /** @return list<string> las interfaces (nombre corto) que implementa la clase del fichero */
    private static function implementa(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] !== T_IMPLEMENTS) {
                continue;
            }
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j][1] !== '{'; $j++) {
                if (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $out[] = self::corto($tokens[$j][1]);
                }
            }
        }

        return $out;
    }

    /** @return array<string,string> las pruebas de voz: ruta relativa → absoluta */
    private static function pruebasDeVoz(): array
    {
        $out = self::php(...self::PRUEBAS_DE_VOZ) + self::glob(...self::PRUEBAS_DE_VOZ_POR_RUTA);
        foreach (self::php('tests') as $relativa => $ruta) {
            foreach (self::PRUEBAS_DE_VOZ_POR_NOMBRE as $patron) {
                if (fnmatch($patron, basename($relativa))) {
                    $out[$relativa] = $ruta;
                }
            }
        }
        $out = array_filter($out, static fn (string $r): bool => str_ends_with($r, 'Test.php'), ARRAY_FILTER_USE_KEY);
        ksort($out);

        return $out;
    }

    /**
     * ¿Habla la prueba con algo? Usa `Http::`, un adaptador con red, una ruta
     * de la aplicación (`$this->get/post…`) o un comando `voice:`.
     */
    private static function hablaConAlgo(string $ruta): bool
    {
        $tokens = self::tokens($ruta);
        foreach ($tokens as $i => $t) {
            $nombre = in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? self::corto($t[1]) : null;
            if ($nombre === 'Http' && (self::siguiente($tokens, $i)[0] ?? null) === T_DOUBLE_COLON) {
                return true;
            }
            if ($nombre !== null && in_array($nombre, self::ADAPTADORES_CON_RED, true)) {
                return true;
            }
            if ($t[0] === T_VARIABLE && $t[1] === '$this' && (self::siguiente($tokens, $i)[0] ?? null) === T_OBJECT_OPERATOR
                && preg_match('/^((get|post|put|patch|delete|options|json|call)(Json)?|admin(Get|Post|Put|Patch|Delete)Json|withHeaders?|artisan)$/', $tokens[$i + 2][1] ?? '') === 1
                && ($tokens[$i + 3][1] ?? null) === '(') {
                return true;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^voice:/', self::literal($t[1])) === 1) {
                return true;
            }
        }

        return false;
    }

    /** ¿Cierra la red la prueba, o una clase o un rasgo del que hereda (dentro de `tests/`)? */
    private static function cierraLaRed(string $ruta, int $nivel): bool
    {
        $tokens = self::tokens($ruta);
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && $t[1] === 'preventStrayRequests' && ($tokens[$i - 1][0] ?? null) === T_DOUBLE_COLON && self::corto($tokens[$i - 2][1] ?? '') === 'Http') {
                return true;
            }
        }
        if ($nivel >= 5) {
            return false;
        }
        foreach (self::ancestros($tokens) as $clase) {
            $fichero = self::raiz().'/tests/'.str_replace('\\', '/', substr($clase, strlen('Tests\\'))).'.php';
            if (str_starts_with($clase, 'Tests\\') && is_file($fichero) && self::cierraLaRed($fichero, $nivel + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las clases de las que hereda y los rasgos que usa, con su nombre completo.
     *
     * @param  list<array{0:int,1:string}>  $tokens
     * @return list<string>
     */
    private static function ancestros(array $tokens): array
    {
        $espacio = '';
        $importados = [];
        $nombres = [];
        $dentroDeClase = false;
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_NAMESPACE) {
                $espacio = $tokens[$i + 1][1] ?? '';
            }
            if ($t[0] === T_CLASS) {
                $dentroDeClase = true;
            }
            if ($t[0] === T_USE && ! $dentroDeClase && in_array($tokens[$i + 1][0] ?? null, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $completo = ltrim($tokens[$i + 1][1], '\\');
                $alias = ($tokens[$i + 2][0] ?? null) === T_AS ? ($tokens[$i + 3][1] ?? self::corto($completo)) : self::corto($completo);
                $importados[$alias] = $completo;
            }
            if (($t[0] === T_EXTENDS || ($t[0] === T_USE && $dentroDeClase)) && in_array($tokens[$i + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $nombres[] = $tokens[$i + 1][1];
            }
        }

        return array_map(static function (string $n) use ($importados, $espacio): string {
            if (str_starts_with($n, '\\')) {
                return ltrim($n, '\\');
            }

            return $importados[$n] ?? ($espacio !== '' ? $espacio.'\\'.$n : $n);
        }, $nombres);
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

    /** @return array<string,string> ruta relativa al backend → ruta absoluta, de todo el PHP bajo estos ficheros o directorios (los que no existan, se saltan) */
    private static function php(string ...$directorios): array
    {
        $out = [];
        foreach ($directorios as $dir) {
            $abs = self::raiz().'/'.$dir;
            if (is_file($abs)) {
                $out[$dir] = $abs;

                continue;
            }
            if (! is_dir($abs)) {
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

    /** @return array<string,string> lo mismo, por patrones relativos al backend */
    private static function glob(string ...$patrones): array
    {
        $out = [];
        foreach ($patrones as $patron) {
            foreach (glob(self::raiz().'/'.$patron) ?: [] as $f) {
                if (is_file($f)) {
                    $out[substr($f, strlen(self::raiz()) + 1)] = $f;
                }
            }
        }
        ksort($out);

        return $out;
    }

    private static function raiz(): string
    {
        return dirname(__DIR__, 3);
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

    /** @return list<string> las claves literales que se leen con `config(...)`, ordenadas (`(no literal)` si el argumento no es un literal) */
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

    /** @return list<string> los literales de cadena del código, sin comillas */
    private static function literales(string $ruta): array
    {
        $out = [];
        foreach (self::tokens($ruta) as $t) {
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = self::literal($t[1]);
            }
        }

        return $out;
    }

    /** @return list<string> las funciones (no métodos) que se llaman, en minúscula */
    private static function funcionesLlamadas(string $ruta): array
    {
        $tokens = self::tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && (self::siguiente($tokens, $i)[1] ?? null) === '('
                && ! in_array(self::anterior($tokens, $i)[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                $out[] = strtolower(self::corto($t[1]));
            }
        }

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

    /** El código de un fichero sin comentarios (las cadenas se quedan). */
    private static function codigo(string $ruta): string
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
