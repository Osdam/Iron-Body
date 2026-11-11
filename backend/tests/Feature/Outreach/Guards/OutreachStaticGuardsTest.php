<?php

namespace Tests\Feature\Outreach\Guards;

use App\Services\Outreach\Facts\PhoneRef;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Run\OutreachSendGate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * GUARDAS ESTÁTICAS DEL HITO 9 (contrato H9, §3 protecciones 2, 4, 5, 7, 8 y
 * 10; §9.3; SEC9-a). Rastreos de tokens sobre el PHP del H9, como
 * `VoiceIsolationTest` y `TelephonyIsolationTest` en el H8:
 *
 *  - la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE` es un literal
 *    `false`, una vez, declarada solo ahí, leída por su método y exigida por
 *    `automaticSendAllowed()`; nadie la nombra como cadena;
 *  - el espacio `Outreach`, el canal SSE y el gobierno de estrategias no
 *    nombran los proveedores (Meta, el outbox de WhatsApp, ElevenLabs, Twilio,
 *    OpenAI), `Http::`, `curl`, sockets, correo ni notificaciones; ni n8n
 *    (`SendAutomationEventToN8n`, `AutomationEventService`,
 *    `SendUltronEventToN8n`, `config('automation.*')`), ni el OTP (`otp.*`,
 *    `voice.twilio.*`), ni ULTRON Legacy (`Marketing\Ultron\*` salvo
 *    `UltronAbortLatch::engaged()` en `Facts\OperationalBrakes`), ni la cadena
 *    del motor (`ultron_studio_publications`, `UltronPublishedConfig`,
 *    `MARKETING_ULTRON_STUDIO_CONFIG`);
 *  - de fuera de `Outreach`, los hechos solo importan lo que L9-28 permite
 *    (las reglas puras del H8, `PaymentController::PAID_STATUSES`, el
 *    vocabulario comercial, el interruptor del agente, el registro de
 *    incidentes);
 *  - ningún `Log::` del H9 lleva una clave con nombre de dato personal;
 *  - los modelos del gobierno llevan `$guarded = ['*']`;
 *  - `released_at` solo lo escribe `OutreachStop::release()` (nunca se suelta solo);
 *  - las etiquetas HMAC de los seudónimos están ancladas (cambiarlas deja
 *    obsoletos todos los `subject_ref`/`phone_ref`, L9-4).
 */
class OutreachStaticGuardsTest extends TestCase
{
    /** Todo el PHP del H9 que corre en producción (directorios con barra final; ficheros o patrones sin ella). */
    private const H9 = [
        'app/Services/Outreach/', 'app/Services/UltronStudio/Stream/', 'app/Console/Commands/Outreach/', 'app/Jobs/Outreach/',
        'app/Services/UltronStudio/StudioStrateg*.php', 'app/Services/UltronStudio/StudioMessageTemplates.php', 'app/Services/UltronStudio/StudioPersonBindings.php',
        'app/Http/Controllers/Crm/UltronStudio/UltronStudioStrateg*.php', 'app/Http/Controllers/Crm/UltronStudio/UltronStudioStream*.php', 'app/Http/Controllers/Api/Admin/UltronStudio/',
        'app/Models/UltronStudio/StudioStrateg*.php', 'app/Models/UltronStudio/StudioMessageTemplate.php', 'app/Models/UltronStudio/StudioPersonBinding.php',
        'config/outreach.php', 'config/ultron_studio_stream.php',
        // El registro de lo que aprobó Meta (W9 gancho 4, b9-cierre; L9-88): vive en el Studio y corre en producción.
        'app/Services/UltronStudio/StudioTemplateMetaChecks.php', 'app/Console/Commands/UltronStudio/TemplateMetaCheckCommand.php',
        // L9-96 (plantillas de Meta desde el CRM; plan §6, paso 5): lo del Studio que no hace red directa (la red va por
        // el cliente neutro `App\Services\Meta\Templates`, zona neutra de UltronEngineIsolationTest) y su modelo.
        'app/Services/UltronStudio/StudioMetaTemplates.php', 'app/Http/Controllers/Crm/UltronStudio/UltronStudioMetaTemplateController.php',
        'app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php', 'app/Models/UltronStudio/StudioMetaTemplateSubmission.php',
    ];

    private const GATE = 'app/Services/Outreach/Run/OutreachSendGate.php';

    /** Lo que ningún fichero del H9 nombra (protecciones 4, 5, 7 y 8; §9.3): proveedores, red, n8n, OTP, Legacy y la cadena del motor. */
    private const FORBIDDEN = [
        'MetaMessagingService', 'WhatsappOutboxService', 'MarketingMessageDispatcher', 'ElevenLabs', 'Twilio', 'OpenAI', 'openai',
        'Facades\\Http', 'Http::', 'GuzzleHttp', 'curl_', 'fsockopen', 'stream_socket_client', 'Mail::', 'Notification::', 'Broadcast::',
        'SendAutomationEventToN8n', 'AutomationEventService', 'SendUltronEventToN8n', "'automation.", 'internal_secret',
        "'otp.", "'voice.twilio", 'UltronPublishedConfig', 'MARKETING_ULTRON_STUDIO_CONFIG', "'marketing.ultron.studio_config", "'ultron_studio_publications'",
    ];

    /** Del motor de ULTRON, lo único que un fichero del H9 puede importar, y desde dónde (§9.3, D9-2). */
    private const ULTRON_LEGACY_IMPORT = 'App\\Services\\Marketing\\Ultron\\UltronAbortLatch';

    private const ULTRON_LEGACY_READER = 'app/Services/Outreach/Facts/OperationalBrakes.php';

    /**
     * W9 (contrato H9, D9-9.3 «W-B»; D9-2 «Puertas de los canales»): el canal de WhatsApp es el ÚNICO fichero de
     * `Outreach` que nombra la puerta de Marketing, y solo así: el despachador y el DTO de la plantilla; del
     * despachador, solo `dispatchWhatsappTemplate` (nunca `dispatchWhatsapp`); el outbox y Meta, nunca.
     */
    private const W9_GATE_CALLER = 'app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php';

    private const W9_GATE_IMPORTS = ['App\\Services\\Marketing\\MarketingMessageDispatcher', 'App\\Services\\Marketing\\OutreachTemplateMessage'];

    /**
     * V9 (contrato H9, D9-9.4 y D9-2 «en V9, `OutboundGate::requestForStrategy`»; Enmienda 17 del H8, L9-81): el canal
     * de voz es el ÚNICO fichero de `Outreach` que nombra la puerta de la telefonía, y solo así: de la puerta, una
     * llamada a `requestForStrategy` y ninguna a `request` (la de las personas). Twilio y ElevenLabs, nunca.
     */
    private const V9_GATE_CALLER = 'app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php';

    private const V9_GATE_IMPORTS = ['App\\Services\\Telephony\\OutboundGate'];

    /**
     * De fuera de `Outreach`, lo que `Outreach` puede importar (L9-28 y D9-2):
     * las reglas puras del H8 y el freno de voz (solo lectura), los estados de
     * pago, el vocabulario comercial, el interruptor del agente y el registro
     * de incidentes de IRON GUARD; el resto de `App\` tiene que ser `Models`
     * (lectura por Eloquent), `Support` o el propio `Outreach`.
     */
    private const ALLOWED_FOREIGN_IMPORTS = [
        'App\\Services\\Telephony\\ColombianHolidays', 'App\\Services\\Telephony\\OutboundSchedule', 'App\\Services\\Telephony\\VoiceFrequency',
        'App\\Services\\Telephony\\VoiceConsentRule', 'App\\Services\\Telephony\\VoiceExclusionRule', 'App\\Services\\Telephony\\VoiceBrake',
        'App\\Http\\Controllers\\Api\\PaymentController', 'App\\Services\\Commercial\\CommercialVocabulary', 'App\\Services\\Marketing\\MarketingAgentSwitch',
        'App\\Services\\Membership\\MembershipAccess', 'App\\Services\\Wompi\\PaymentStateMachine',
        'App\\Services\\IronGuard\\IncidentRecorder', self::ULTRON_LEGACY_IMPORT,
        // El puente (D9-2, §9.3): las dos ayudas puras del Studio.
        'App\\Services\\UltronStudio\\StudioMessageTemplates', 'App\\Services\\UltronStudio\\StudioStrategyValidator',
        // Los temas `outreach.*` del canal SSE (Paquete SSE9: «los implementa B9-run sobre el registro de adaptadores de SSE9»).
        'App\\Services\\UltronStudio\\Stream\\StreamTopic', 'App\\Services\\UltronStudio\\Stream\\StreamEvent', 'App\\Services\\UltronStudio\\Stream\\StreamCursors',
        'App\\Services\\UltronStudio\\Stream\\StreamCountable', 'App\\Services\\UltronStudio\\Stream\\StreamViewerGate', 'App\\Services\\UltronStudio\\StudioAccess',
    ];

    /** Prefijos propios: el espacio de la automatización, sus órdenes y su trabajo de cola; los modelos (lectura), el soporte y las reglas de validación. */
    private const OWN_PREFIXES = ['App\\Services\\Outreach', 'App\\Console\\Commands\\Outreach', 'App\\Jobs\\Outreach', 'App\\Models\\', 'App\\Support\\', 'App\\Rules\\'];

    /** Claves que no pueden aparecer en el contexto de un `Log::` del H9 (protección 10). */
    private const LOG_FORBIDDEN_KEYS = '/^(phone|telefono|celular|remote|to|from|number|name|nombre|first_name|last_name|full_name|email|correo|document|documento|cedula|body|cuerpo|message|mensaje|text|texto|script|transcript|note|nota|facts|trace|traza|address|direccion|birth|amount|importe|monto|payload|raw|request|response)$/i';

    private const MODELS = [
        'app/Models/UltronStudio/StudioStrategy.php', 'app/Models/UltronStudio/StudioStrategyVersion.php', 'app/Models/UltronStudio/StudioStrategyPublication.php',
        'app/Models/UltronStudio/StudioStrategyReview.php', 'app/Models/UltronStudio/StudioStrategySimulation.php', 'app/Models/UltronStudio/StudioMessageTemplate.php',
        'app/Models/UltronStudio/StudioPersonBinding.php',
        // L9-96: los envíos de plantillas a Meta.
        'app/Models/UltronStudio/StudioMetaTemplateSubmission.php',
    ];

    public function test_la_constante_de_envio_es_un_literal_false_declarado_una_vez_y_leido_solo_por_su_puerta(): void
    {
        $this->assertFalse(OutreachSendGate::SENDS_ENABLED_IN_CODE);
        $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
        $this->assertSame(['simulated', 'assisted', 'whatsapp_template', 'voice_call'], OutreachSendGate::LIVE_CHANNELS, 'W9 añade WhatsApp por plantillas y V9 la voz (Enmienda 17 del H8, L9-81)');

        $fuente = (string) file_get_contents(base_path(self::GATE));
        $this->assertSame(1, preg_match_all('/public const SENDS_ENABLED_IN_CODE = false;/', $fuente), 'El literal, una vez, con esa forma exacta.');
        // W9: el doble de la puerta (`sendsEnabledInCode` del canal de WhatsApp y de la entrada de la escalera) es SOLO de las pruebas.
        foreach ($this->phpFiles(['app', 'routes', 'config', 'database', 'bootstrap']) as $relativa) {
            $codigo = $this->codeOnly(base_path($relativa));
            $this->assertSame(0, preg_match_all('/sendsEnabledInCode\s*:/', $codigo), "{$relativa} pasa el doble de la puerta.");
            $this->assertSame(0, preg_match_all('/new\s+(WhatsappTemplateChannel|LadderInputBuilder)\s*\(\s*[^)\s]/', $codigo), "{$relativa} construye el canal o la escalera con argumentos.");
        }
        $this->assertSame(0, preg_match_all('/SENDS_ENABLED_IN_CODE\s*=\s*(true|1|\$|self|static|env|config)/i', $fuente), 'La constante no se calcula ni se lee del entorno.');
        $codigo = $this->codeOnly(base_path(self::GATE));
        $this->assertStringContainsString('self::sendsEnabledInCode()', $this->methodBody($codigo, 'automaticSendAllowed'), 'automaticSendAllowed() tiene que exigir la constante.');

        // Fuera de la puerta, nadie declara ni nombra la constante (se pregunta por el método, y el mando de OPS9 lee el fichero).
        foreach ($this->phpFiles(['app', 'routes', 'config', 'database', 'bootstrap']) as $relativa) {
            if ($relativa === self::GATE) {
                continue;
            }
            $this->assertStringNotContainsString('SENDS_ENABLED_IN_CODE', $this->codeOnly(base_path($relativa)), "{$relativa} nombra la constante de envío.");
        }
    }

    public function test_el_h9_no_nombra_proveedores_red_n8n_otp_legacy_ni_la_cadena_del_motor(): void
    {
        $vistos = 0;
        foreach ($this->h9Files() as $relativa) {
            $vistos++;
            $codigo = $this->codeOnly(base_path($relativa));
            // W9: la puerta de Marketing, solo desde su canal y solo por su método de plantillas.
            $prohibidos = $relativa === self::W9_GATE_CALLER ? array_values(array_diff(self::FORBIDDEN, ['MarketingMessageDispatcher'])) : self::FORBIDDEN;
            foreach ($prohibidos as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $codigo, "{$relativa} nombra «{$prohibido}» (protecciones 4, 5, 7 y 8).");
            }
            if ($relativa === self::W9_GATE_CALLER) {
                $this->assertSame(1, preg_match_all('/->dispatchWhatsappTemplate\(/', $codigo), 'De la puerta de Marketing, una llamada: dispatchWhatsappTemplate.');
                $this->assertSame(0, preg_match_all('/->dispatchWhatsapp\(/', $codigo), 'Nunca el texto libre de la puerta.');
            }
            // V9: la puerta de la telefonía, solo desde su canal y solo por la entrada de las estrategias.
            if ($relativa === self::V9_GATE_CALLER) {
                $this->assertSame(1, preg_match_all('/->requestForStrategy\(/', $codigo), 'De la puerta de la telefonía, una llamada: requestForStrategy.');
                $this->assertSame(0, preg_match_all('/->request\(/', $codigo), 'Nunca la puerta de las personas (request).');
            }
            // ULTRON Legacy: solo el cerrojo, solo desde los frenos operativos, solo `engaged()`.
            if (str_contains($codigo, 'Marketing\\Ultron\\')) {
                $this->assertSame(self::ULTRON_LEGACY_READER, $relativa, "{$relativa} importa ULTRON Legacy.");
                $this->assertSame(1, substr_count($codigo, 'Marketing\\Ultron\\'), 'Solo el cerrojo.');
                $this->assertStringContainsString(self::ULTRON_LEGACY_IMPORT, $codigo);
                $this->assertSame(1, preg_match_all('/UltronAbortLatch::class\)->engaged\(\)/', $codigo), 'Del cerrojo, solo engaged().');
            }
        }
        $this->assertGreaterThan(60, $vistos, 'el rastreo ve los ficheros del H9');
    }

    /** De `App\`, `Outreach` solo importa modelos, soporte, su propio espacio y la lista cerrada de L9-28/D9-2. */
    public function test_outreach_solo_importa_lo_que_l9_28_y_d9_2_permiten(): void
    {
        $ajenos = [];
        foreach ($this->phpFiles(['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach']) as $relativa) {
            foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
                // Todo nombre cualificado de `App\…` (los `use` de cabecera y el propio `namespace`).
                if (! is_array($t) || $t[0] !== T_NAME_QUALIFIED || ! str_starts_with($t[1], 'App\\')) {
                    continue;
                }
                $nombre = $t[1];
                $propio = false;
                foreach (self::OWN_PREFIXES as $prefijo) {
                    if (str_starts_with($nombre, $prefijo)) {
                        $propio = true;
                    }
                }
                if ($propio || in_array($nombre, self::ALLOWED_FOREIGN_IMPORTS, true) || ($relativa === self::W9_GATE_CALLER && in_array($nombre, self::W9_GATE_IMPORTS, true)) || ($relativa === self::V9_GATE_CALLER && in_array($nombre, self::V9_GATE_IMPORTS, true))) {
                    continue;
                }
                $ajenos[] = "{$relativa}: {$nombre}";
            }
        }
        $this->assertSame([], array_values(array_unique($ajenos)), "Outreach importa algo fuera de la lista cerrada (L9-28, D9-2):\n".implode("\n", array_unique($ajenos)));
    }

    public function test_ningun_log_del_h9_lleva_claves_con_nombre_de_dato_personal(): void
    {
        $logs = 0;
        foreach ($this->h9Files() as $relativa) {
            $tokens = $this->significantTokens(base_path($relativa));
            foreach ($tokens as $i => $t) {
                if (! (is_array($t) && $t[0] === T_STRING && $t[1] === 'Log' && ($tokens[$i + 1] ?? null) !== null && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON)) {
                    continue;
                }
                $logs++;
                // El canal: una cadena literal. El contexto: claves literales, ninguna de la lista prohibida.
                $canal = $tokens[$i + 4] ?? null;
                $this->assertTrue(is_array($canal) && $canal[0] === T_CONSTANT_ENCAPSED_STRING, "{$relativa}: un Log:: sin canal literal.");
                $this->assertMatchesRegularExpression("/^'(outreach|ultron_studio)\\./", $canal[1], "{$relativa}: el canal del registro es del H9.");
                for ($j = $i + 5, $nivel = 0; isset($tokens[$j]); $j++) {
                    $x = $tokens[$j];
                    if ($x === '(' || $x === '[') {
                        $nivel++;
                    } elseif ($x === ')' || $x === ']') {
                        if (--$nivel < 0) {
                            break;
                        }
                    }
                    if (is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING && is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_DOUBLE_ARROW) {
                        $clave = trim($x[1], "'\"");
                        $this->assertDoesNotMatchRegularExpression(self::LOG_FORBIDDEN_KEYS, $clave, "{$relativa}: Log:: con la clave «{$clave}» (protección 10).");
                    }
                    if ($x === ';') {
                        break;
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(8, $logs, 'el rastreo ve los registros del H9');
    }

    public function test_los_modelos_del_gobierno_van_blindados_y_la_ejecucion_no_usa_modelos_propios(): void
    {
        foreach (self::MODELS as $modelo) {
            $this->assertFileExists(base_path($modelo));
            $this->assertStringContainsString("protected \$guarded = ['*'];", $this->codeOnly(base_path($modelo)), "{$modelo} sin \$guarded = ['*'] (asignación masiva).");
        }
        $this->assertDirectoryDoesNotExist(base_path('app/Models/Outreach'), 'La ejecución escribe por DB::table con columnas explícitas (D9-8), no por modelos.');
    }

    public function test_released_at_solo_lo_escribe_release_y_las_etiquetas_hmac_estan_ancladas(): void
    {
        $escritores = [];
        foreach ($this->phpFiles(['app/Services/Outreach', 'app/Console/Commands/Outreach']) as $relativa) {
            $codigo = $this->codeOnly(base_path($relativa));
            $n = preg_match_all("/'released_at'\s*=>/", $codigo);
            if ($n > 0) {
                $escritores[$relativa] = $n;
            }
        }
        $this->assertSame(['app/Services/Outreach/Run/OutreachStop.php' => 1], $escritores, 'Un freno solo lo suelta OutreachStop::release(), una vez (D9-14: nunca se suelta solo).');
        $stop = $this->codeOnly(base_path('app/Services/Outreach/Run/OutreachStop.php'));
        $this->assertStringContainsString("'released_at' => now()", $this->methodBody($stop, 'release'), 'La única escritura de released_at vive en release().');
        $this->assertStringContainsString('samePerson(', $this->methodBody($stop, 'release'), 'release() compara la persona que abrió (L9-7).');

        $this->assertSame('outreach-subject/1', SubjectRef::LABEL);
        $this->assertSame('outreach-phone/1', PhoneRef::LABEL);
        $this->assertSame('/^[0-9a-f]{64}$/D', SubjectRef::PATTERN);
        $this->assertSame(hash_hmac('sha256', 'm:1', hash_hmac('sha256', 'outreach-subject/1', 'k', true)), SubjectRef::withKey('m:1', 'k'), 'La derivación del seudónimo es la del contrato (D9-13).');
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function h9Files(): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach (self::H9 as $entrada) {
            if (str_ends_with($entrada, '/')) {
                if (is_dir($base.$entrada)) {
                    $out = array_merge($out, $this->phpFiles([rtrim($entrada, '/')]));
                }

                continue;
            }
            foreach (glob($base.$entrada) ?: [] as $ruta) {
                $out[] = substr($ruta, strlen($base));
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $raices
     * @return list<string>
     */
    private function phpFiles(array $raices): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices as $raiz) {
            $absoluta = $base.$raiz;
            if (is_file($absoluta)) {
                $out[] = $raiz;

                continue;
            }
            if (! is_dir($absoluta)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/bootstrap/cache/')) {
                    $out[] = substr($f->getPathname(), strlen($base));
                }
            }
        }
        sort($out);

        return $out;
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

    /** @return list<array{0:int,1:string,2:int}|string> */
    private function significantTokens(string $ruta): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** El cuerpo de un método (entre su primera llave y la que la cierra), sobre el código sin comentarios. */
    private function methodBody(string $codigo, string $metodo): string
    {
        $inicio = strpos($codigo, 'function '.$metodo.'(');
        $this->assertNotFalse($inicio, "No existe el método {$metodo}().");
        $abre = strpos($codigo, '{', $inicio);
        $this->assertNotFalse($abre);
        for ($i = $abre, $nivel = 0, $n = strlen($codigo); $i < $n; $i++) {
            $nivel += $codigo[$i] === '{' ? 1 : ($codigo[$i] === '}' ? -1 : 0);
            if ($nivel === 0) {
                return substr($codigo, $abre, $i - $abre + 1);
            }
        }
        $this->fail("El método {$metodo}() no se cierra.");
    }
}
