<?php

namespace Tests\Feature\Telephony;

use App\Services\Marketing\OutboundContentGuard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * LA FRONTERA DE LA TELEFONÍA (contrato H8, D8-2, D8-10, D8-12, D8-16, D8-17 y
 * las protecciones 5, 6 y 9), por tokens. Es de SEC8: quien implementa no la
 * toca; si cae, avisa al lead.
 *
 * LO QUE SE PROTEGE.
 *  1. DEL STUDIO, la telefonía solo nombra el modelo de los canarios
 *     (`StudioCanaryNumber`, de solo lectura, en un único adaptador que no
 *     escribe nada), su normalizador puro (`StudioCanaryPhone`, auditoría
 *     OTP-4) y, en `VoicePublishedConfig`, la tabla de SU cadena, una vez y sin
 *     escribirla. Ni la cadena del motor (`ultron_studio_publications`) ni sus
 *     servicios.
 *  2. DEL MOTOR, solo los dos adaptadores de solo lectura de la protección 5:
 *     `OutboundContentGuard::inspect()` (nunca `assertSafe`, que registra) y
 *     las señales del lead (`MarketingLead`), cada uno en un único fichero de
 *     `app/Services/Telephony/` que no escribe nada. `marketing_calls` solo
 *     como la etiqueta del origen prohibido del H6.
 *  3. EL MOTOR NO NOMBRA LA VOZ: ni sus clases, ni sus tablas, ni su
 *     configuración.
 *  4. LA CONFIGURACIÓN QUE LEE LA VOZ: `voice.*` y `telephony.*`; además, solo
 *     `app.key` (las subclaves de las huellas y de la cadena) y los dos números
 *     de WhatsApp que protege D8-5. Nada del entorno fuera de config/voice.php.
 *  5. SIN TELÉFONOS NI TRANSCRIPCIONES EN CLARO (D8-17, protección 9): ningún
 *     número literal; el registro, solo por el canal `voice` y sin teléfonos,
 *     textos ni cuerpos crudos en sus argumentos; lo que se guarda o se
 *     devuelve, con huella, 4 dígitos o cifrado; y en las migraciones del H8,
 *     ninguna columna con el número en claro, la transcripción o el cuerpo
 *     del webhook.
 *  6. LOS WEBHOOKS VALIDAN: cada controlador público de voz llega a su
 *     validador; los validadores comparan en tiempo constante y nunca con
 *     `fullUrl()` (OTP-3).
 *  7. UNA SOLA PUERTA DE SALIENTES (D8-12): `startOutbound` solo desde el
 *     trabajo de voz, con un intento y su carril; las políticas nuevas componen
 *     la del H6; desplegar y asignar número, solo desde la publicación; la URL
 *     firmada, solo desde el laboratorio.
 *  8. EL STUDIO DE VOZ, de la telefonía solo nombra `VoicePublishedConfig::
 *     signatureFor()`, y de la voz las interfaces de D8-3, los DTO, la fábrica y
 *     el traductor: nunca un adaptador con red, el iniciador ni el
 *     transportista.
 */
class TelephonyIsolationTest extends TestCase
{
    /** La telefonía con estado y lo que la rodea (D8-2): lo que el contrato asigna a B8 fuera del paquete neutro. */
    private const TELEFONIA = ['app/Services/Telephony', 'app/Models/Voice', 'app/Http/Controllers/Api/Voice', 'app/Http/Controllers/Crm/Voice',
        'app/Jobs/Voice', 'app/Console/Commands/Voice', 'routes/voice.php', 'config/voice.php'];

    /** El paquete neutro y los adaptadores de Twilio de la voz. */
    private const NEUTRO = ['app/Services/Voice', 'app/Services/Twilio/TwilioVoiceCarrier.php', 'app/Services/Twilio/TwilioRequestSignature.php'];

    /** El gobierno de la voz en el Studio (S8 y LAB8). */
    private const STUDIO_VOZ = ['app/Services/UltronStudio/StudioVoice*.php', 'app/Models/UltronStudio/StudioVoice*.php',
        'app/Http/Controllers/Crm/UltronStudio/UltronStudioVoice*.php'];

    /** El motor (protección 5). Los trabajos de la voz no son del motor. */
    private const MOTOR = ['app/Services/Marketing', 'app/Jobs', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'config/marketing.php'];

    private const MOTOR_MODELOS = ['app/Models/Marketing*.php'];

    private const NO_ES_MOTOR = 'app/Jobs/Voice/';

    private const TELEFONIA_SERVICIOS = 'app/Services/Telephony/';

    /** El lector de la cadena de voz (D8-8): la única que nombra la tabla, y sin escribirla. */
    private const LECTOR_DE_VOZ = 'app/Services/Telephony/VoicePublishedConfig.php';

    private const TABLA_DE_LA_CADENA = "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_voice_publications'";

    /** Del Studio, lo único que la telefonía nombra. */
    private const STUDIO_PERMITIDO = [
        // D8-2: el modelo de los canarios de voz, de solo lectura.
        'App\\Models\\UltronStudio\\StudioCanaryNumber',
        // Auditoría OTP-4: el normalizador estricto de los canarios (puro).
        // No lo nombra D8-2: decisión pendiente del lead (informe de SEC8).
        'App\\Services\\UltronStudio\\StudioCanaryPhone',
    ];

    /** Lo único del normalizador que se puede usar: sus funciones puras. */
    private const NORMALIZADOR_PERMITIDO = ['parse', 'masked', 'message'];

    /** Lo que sería escribir o cambiar un estado (más `transition`, la máquina de estados de los canarios). */
    private const ESCRITURAS = ['insert', 'insertorignore', 'insertgetid', 'insertusing', 'update', 'updateorinsert', 'updateorcreate', 'firstorcreate',
        'upsert', 'delete', 'forcedelete', 'truncate', 'statement', 'unprepared', 'affectingstatement', 'increment', 'decrement', 'create',
        'forcecreate', 'save', 'savequietly', 'fill', 'forcefill', 'push', 'touch', 'destroy', 'transition', 'lockforupdate', 'sharedlock',
        'dispatch', 'dispatchsync'];

    /** Del motor, lo único que la voz puede nombrar (protección 5). */
    private const GUARDA = 'OutboundContentGuard';

    private const LEAD = 'MarketingLead';

    private const MENSAJE = 'MarketingMessage';

    /** Las dos claves de Meta que lee la guarda del número de WhatsApp (D8-5). */
    private const META_PERMITIDO = ['meta.whatsapp_display_phone', 'meta.protected_numbers'];

    /** Las tablas de la voz (contratos de datos del H8). */
    private const TABLAS_DE_VOZ = '/^(voice_(calls|call_events|contacts|consents|exclusions|rne_checks|brakes)|ultron_studio_voice_[a-z_]+)$/';

    /** Los validadores de los webhooks y las herramientas (D8-16). */
    private const VALIDADORES = ['VoiceEventVerifier', 'ElevenLabsEventVerifier', 'VoiceInitiationAuth', 'VoiceToolAuth', 'TwilioRequestSignature'];

    private const FICHEROS_VALIDADORES = ['app/Services/Telephony/VoiceToolAuth.php', 'app/Services/Telephony/VoiceInitiationAuth.php',
        'app/Services/Telephony/TwilioRequestSignature.php', 'app/Services/Twilio/TwilioRequestSignature.php'];

    /** Teléfonos: un nombre así, guardado, devuelto o registrado, tiene que ser huella, 4 dígitos o cifrado. */
    private const TELEFONO = '/(^|_)(phone|telefono|e164|msisdn|caller_id|called_number|to_number|from_number|remote_number|phone_number|destination_number|transfer_number|numero_destino|celular|movil)(_|$)/';

    private const SUFIJO_SEGURO = '/(_hash|_last4|_encrypted|_fp|_fingerprint|_number_id|_count|_length|_len)$/';

    /** Textos: la transcripción no se guarda ni se registra (D8-17). */
    private const TEXTO = '/(^|_)(transcript|transcription|transcripcion|turn_text|message_text|mensaje|texto|audio|recording)(_|$)/';

    /** Los nombres de evento del contrato (D8-13) que llevan esas palabras. */
    private const EVENTOS = ['post_call_transcription', 'post_call_audio', 'post_call_audio_discarded'];

    /** Cuerpos crudos: un webhook entero lleva el número y la transcripción. */
    private const CRUDO = '/^(payload|raw|raw_body|request_body|response_body|body|cuerpo|carga|request)$/';

    /** Un número de teléfono escrito en el código: E.164 o nacional de Colombia (móvil 3…, fijo 60…). */
    private const NUMERO_LITERAL = '/\+[1-9][0-9]{7,14}(?![0-9])|(?<![0-9])(57)?(3[0-9]{9}|60[0-9]{8})(?![0-9])/';

    // ── 1. El Studio ────────────────────────────────────────────────────────

    public function test_del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena(): void
    {
        $nombranCanarios = [];
        foreach ($this->php(...self::TELEFONIA) + $this->php(...self::NEUTRO) as $relativa => $ruta) {
            foreach ($this->referenciasAlStudio($ruta) as [$tipo, $texto]) {
                $nombre = ltrim($texto, '\\');
                if (in_array($tipo, ['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED'], true) && in_array($nombre, self::STUDIO_PERMITIDO, true) && str_starts_with($relativa, self::TELEFONIA_SERVICIOS)) {
                    if ($nombre === self::STUDIO_PERMITIDO[0]) {
                        $nombranCanarios[$relativa] = true;
                    }

                    continue;
                }
                if ($relativa === self::LECTOR_DE_VOZ && "{$tipo} {$texto}" === self::TABLA_DE_LA_CADENA) {
                    continue;
                }
                $this->fail("{$relativa} nombra el Studio ({$tipo} {$texto}): la telefonía solo nombra el modelo de los canarios, su normalizador y, en VoicePublishedConfig, la tabla de su cadena (D8-2)");
            }
        }

        // Los canarios: en un solo adaptador, y ese adaptador no escribe nada.
        $this->assertLessThanOrEqual(1, count($nombranCanarios), 'los canarios se leen desde un único adaptador: '.implode(', ', array_keys($nombranCanarios)));
        foreach (array_keys($nombranCanarios) as $relativa) {
            $this->assertSame([], $this->escrituras(base_path($relativa)), "{$relativa} lee los canarios y escribe: el adaptador es de solo lectura (D8-2)");
        }
        // El normalizador, solo por sus funciones puras.
        foreach ($this->php(...self::TELEFONIA) as $relativa => $ruta) {
            $tokens = $this->tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] === T_STRING && $t[1] === 'StudioCanaryPhone' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                    $miembro = $tokens[$i + 2][1] ?? '';
                    $esConstante = preg_match('/^[A-Z][A-Z0-9_]*$/', $miembro) === 1;
                    $this->assertTrue($esConstante || in_array($miembro, self::NORMALIZADOR_PERMITIDO, true), "{$relativa} usa StudioCanaryPhone::{$miembro}");
                }
            }
        }

        // El lector de la cadena de voz: la tabla una vez y ninguna escritura.
        $lector = base_path(self::LECTOR_DE_VOZ);
        if (is_file($lector)) {
            $this->assertSame(1, substr_count($this->codigo($lector), 'ultron_studio_'), 'VoicePublishedConfig nombra la tabla de su cadena una sola vez');
            $this->assertSame([], $this->escrituras($lector), 'VoicePublishedConfig escribe: es un lector (D8-8)');
        }
    }

    // ── 2 y 3. El motor ─────────────────────────────────────────────────────

    public function test_del_motor_la_voz_solo_lee_por_los_dos_adaptadores(): void
    {
        $publicosDeLaGuarda = array_map('strtolower', array_diff(get_class_methods(OutboundContentGuard::class), ['inspect']));
        $nombran = [self::GUARDA => [], self::LEAD => [], self::MENSAJE => []];
        $meta = [];

        foreach ($this->php(...self::TELEFONIA) + $this->php(...self::NEUTRO) as $relativa => $ruta) {
            $tokens = $this->tokens($ruta);
            foreach ($tokens as $i => $t) {
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $completo = ltrim($t[1], '\\');
                    $corto = $this->corto($completo);
                    $antes = $tokens[$i - 1][0] ?? null;
                    if (in_array($antes, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE], true)) {
                        continue;   // un método, una constante o una declaración propia, no una clase del motor
                    }
                    if (! $this->esDelMotor($completo)) {
                        continue;
                    }
                    if (isset($nombran[$corto]) && in_array($completo, [$corto, 'App\\Services\\Marketing\\'.self::GUARDA, 'App\\Models\\'.self::LEAD, 'App\\Models\\'.self::MENSAJE], true)) {
                        $nombran[$corto][$relativa] = true;

                        continue;
                    }
                    $this->fail("{$relativa} nombra {$completo}: del motor, la voz solo lee OutboundContentGuard::inspect() y las señales del lead (protección 5)");
                }
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $valor = $this->literal($t[1]);
                    if ($valor === 'marketing_calls' && $relativa === 'app/Services/Voice/VoiceCallContext.php') {
                        continue;   // la etiqueta del origen PROHIBIDO del H6
                    }
                    if (in_array($valor, self::META_PERMITIDO, true)) {
                        $meta[$relativa] = true;

                        continue;
                    }
                    $this->assertDoesNotMatchRegularExpression('/^(marketing[._]|meta\.|ultron\.)|whatsapp/i', $valor, "{$relativa} nombra {$valor}: el motor, Meta y WhatsApp no son de la voz");
                }
            }
        }

        // Cada adaptador, en un único fichero de la telefonía, y sin escribir nada.
        foreach ([self::GUARDA, self::LEAD] as $clase) {
            $this->assertLessThanOrEqual(1, count($nombran[$clase]), "{$clase} se usa desde más de un fichero: ".implode(', ', array_keys($nombran[$clase])));
            foreach (array_keys($nombran[$clase]) as $relativa) {
                $this->assertStringStartsWith(self::TELEFONIA_SERVICIOS, $relativa, "{$clase} solo desde un adaptador de app/Services/Telephony");
                $this->assertSame([], $this->escrituras(base_path($relativa)), "{$relativa} usa {$clase} y escribe: el adaptador es de solo lectura");
                $this->assertNotContains('Log', $this->usos(base_path($relativa)), "{$relativa}: el adaptador no registra (lo hace quien decide, por el canal voice)");
            }
        }
        // De la guarda, solo inspect() (assertSafe registra y lanza: `OutboundContentGuard.php:710`).
        foreach (array_keys($nombran[self::GUARDA]) as $relativa) {
            $this->assertSame([], array_values(array_intersect($this->metodosLlamados(base_path($relativa)), $publicosDeLaGuarda)), "{$relativa} usa de la guarda algo que no es inspect()");
        }
        // El autor de máquina, solo como constante y junto a la guarda.
        foreach (array_keys($nombran[self::MENSAJE]) as $relativa) {
            $this->assertArrayHasKey($relativa, $nombran[self::GUARDA], "{$relativa} nombra MarketingMessage sin ser el adaptador de la guarda");
            $tokens = $this->tokens(base_path($relativa));
            foreach ($tokens as $i => $t) {
                if ($t[0] === T_STRING && $t[1] === self::MENSAJE && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                    $this->assertSame('SENDER_AI', $tokens[$i + 2][1] ?? null, "{$relativa}: de MarketingMessage, solo SENDER_AI");
                }
            }
        }
        // Las dos claves de Meta, en un solo fichero de la telefonía (la guarda del número de WhatsApp).
        $this->assertLessThanOrEqual(1, count($meta), 'las claves de Meta se leen desde más de un fichero');
        foreach (array_keys($meta) as $relativa) {
            $this->assertStringStartsWith(self::TELEFONIA_SERVICIOS, $relativa);
        }
    }

    public function test_el_motor_no_nombra_la_voz(): void
    {
        $vistos = 0;
        foreach ($this->php(...self::MOTOR) + $this->glob(...self::MOTOR_MODELOS) as $relativa => $ruta) {
            if (str_starts_with($relativa, self::NO_ES_MOTOR)) {
                continue;
            }
            $vistos++;
            $codigo = $this->codigo($ruta);
            foreach (['Services\\Voice', 'Services\\Telephony', 'Models\\Voice', 'Jobs\\Voice', 'Controllers\\Api\\Voice', 'Controllers\\Crm\\Voice',
                'TwilioVoiceCarrier', 'TwilioRequestSignature', 'VoicePublishedConfig', 'ElevenLabs', 'StudioVoice'] as $nombre) {
                $this->assertStringNotContainsString($nombre, $codigo, "{$relativa} nombra {$nombre}: el motor no sabe de la voz (protección 5)");
            }
            foreach ($this->literales($ruta) as $valor) {
                $this->assertDoesNotMatchRegularExpression(self::TABLAS_DE_VOZ, $valor, "{$relativa} nombra la tabla {$valor}");
                $this->assertDoesNotMatchRegularExpression('/^(voice|telephony)\.[a-z]/', $valor, "{$relativa} lee la configuración de la voz ({$valor})");
            }
        }
        $this->assertGreaterThan(50, $vistos, 'el rastreo ve el motor');
    }

    // ── 4. Configuración ────────────────────────────────────────────────────

    public function test_la_voz_solo_lee_su_configuracion_y_nada_del_entorno(): void
    {
        $vistas = 0;
        foreach ($this->php(...self::TELEFONIA) + $this->php(...self::NEUTRO) as $relativa => $ruta) {
            if ($relativa === 'config/voice.php') {
                continue;
            }
            foreach ($this->lecturasDeConfiguracion($ruta) as $clave) {
                $vistas++;
                $permitida = preg_match('/^(voice|telephony)(\.[a-z0-9_.]+)?$/', $clave) === 1 || $clave === 'app.key' || in_array($clave, self::META_PERMITIDO, true);
                $this->assertTrue($permitida, "{$relativa} lee config('{$clave}'): la voz solo lee voice.* y telephony.* (y app.key y los números de WhatsApp de D8-5)");
            }
            foreach ($this->funcionesLlamadas($ruta) as $funcion) {
                $this->assertNotContains($funcion, ['env', 'getenv', 'putenv'], "{$relativa} lee el entorno: solo config/voice.php");
            }
            foreach ($this->tokens($ruta) as $t) {
                $this->assertFalse($t[0] === T_VARIABLE && in_array($t[1], ['$_ENV', '$_SERVER'], true), "{$relativa} lee {$t[1]}");
            }
        }
        $this->assertGreaterThanOrEqual(2, $vistas, 'el rastreo ve las lecturas de ElevenLabsConfig y del proveedor');
    }

    // ── 5. Teléfonos y textos ───────────────────────────────────────────────

    public function test_sin_numeros_escritos_en_el_codigo(): void
    {
        foreach ($this->php(...self::TELEFONIA) + $this->php(...self::NEUTRO) + $this->glob(...self::STUDIO_VOZ) + $this->migracionesDeVoz() as $relativa => $ruta) {
            foreach ($this->tokens($ruta) as $t) {
                if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_LNUMBER], true)) {
                    $this->assertDoesNotMatchRegularExpression(self::NUMERO_LITERAL, $t[1], "{$relativa} escribe un número de teléfono en el código");
                }
            }
        }
    }

    public function test_el_registro_de_la_voz_va_por_su_canal_y_sin_telefonos_ni_textos(): void
    {
        foreach ($this->php(...self::NEUTRO) as $relativa => $ruta) {
            $usos = $this->usos($ruta);
            foreach (['Log', 'ChannelLog', 'logger', 'info', 'report', 'error_log', 'syslog', 'dump', 'dd', 'var_dump', 'print_r'] as $registro) {
                $this->assertNotContains($registro, $usos, "{$relativa} registra ({$registro}): los adaptadores son HTTP puro (D8-2)");
            }
        }

        foreach ($this->php(...self::TELEFONIA) as $relativa => $ruta) {
            $tokens = $this->tokens($ruta);
            foreach ($this->funcionesLlamadas($ruta) as $funcion) {
                $this->assertNotContains($funcion, ['logger', 'info', 'report', 'error_log', 'syslog', 'dump', 'dd', 'var_dump', 'print_r', 'var_export'], "{$relativa} registra con {$funcion}(): solo Log::channel('voice')");
            }
            $this->assertNotContains('ChannelLog', $this->usos($ruta), "{$relativa} usa ChannelLog: el canal de la voz es `voice`");
            foreach ($tokens as $i => $t) {
                if (! ($t[0] === T_STRING && $t[1] === 'Log' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON)) {
                    continue;
                }
                $this->assertSame(['channel', '(', "'voice'", ')'], [$tokens[$i + 2][1] ?? null, $tokens[$i + 3][1] ?? null, $tokens[$i + 4][1] ?? null, $tokens[$i + 5][1] ?? null], "{$relativa} registra fuera del canal voice (D8-4)");
                // Los argumentos del nivel: Log::channel('voice')->warning(...)
                $abre = $i + 8;
                $this->assertSame(T_OBJECT_OPERATOR, $tokens[$i + 6][0] ?? null, "{$relativa}: Log::channel('voice') sin una llamada de nivel detrás");
                $this->assertSame('(', $tokens[$abre][1] ?? null, "{$relativa}: Log::channel('voice') sin una llamada de nivel detrás");
                foreach ($this->argumentos($tokens, $abre) as $j) {
                    $this->assertArgumentoDeRegistroLimpio($tokens, $j, $relativa);
                }
            }
        }
    }

    public function test_lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado(): void
    {
        foreach ($this->php(...self::TELEFONIA) as $relativa => $ruta) {
            if ($relativa === 'config/voice.php') {
                continue;
            }
            $tokens = $this->tokens($ruta);
            $metodos = $this->rangosDeMetodos($tokens);
            foreach ($tokens as $i => $t) {
                // Las claves de un array (`'k' =>`), salvo las reglas de validación de lo que llega.
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_ARROW && ! $this->esValidacion($tokens, $i, $metodos)) {
                    $this->assertNombreGuardable($this->literal($t[1]), "{$relativa} guarda o devuelve '{$this->literal($t[1])}'");
                }
                // `$x->campo = …` (asignar un atributo).
                if ($t[0] === T_STRING && in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && ($tokens[$i + 1][1] ?? null) === '=') {
                    $this->assertNombreGuardable($this->serpiente($t[1]), "{$relativa} asigna ->{$t[1]}");
                }
                // Los modelos: todo identificador literal (fillable, casts, visibles).
                if (str_starts_with($relativa, 'app/Models/Voice/') && $t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[a-z][a-z0-9_]*$/', $this->literal($t[1])) === 1) {
                    $this->assertNombreGuardable($this->literal($t[1]), "{$relativa} declara '{$this->literal($t[1])}'");
                }
            }
        }

        // Las migraciones del H8 (DB8): ninguna columna con el número en claro, la transcripción ni el cuerpo del webhook.
        foreach ($this->migracionesDeVoz() as $relativa => $ruta) {
            $tokens = $this->tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] === T_VARIABLE && ($tokens[$i + 1][0] ?? null) === T_OBJECT_OPERATOR && ($tokens[$i + 2][0] ?? null) === T_STRING
                    && ($tokens[$i + 3][1] ?? null) === '(' && ($tokens[$i + 4][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                    && ! in_array(strtolower($tokens[$i + 2][1]), ['comment', 'default', 'index', 'unique', 'primary', 'foreign', 'on', 'references', 'dropcolumn', 'dropindex', 'dropunique', 'dropforeign', 'renamecolumn', 'raw', 'table', 'create', 'drop', 'dropifexists', 'hastable', 'hascolumn'], true)) {
                    $columna = $this->literal($tokens[$i + 4][1]);
                    $this->assertNombreGuardable($columna, "{$relativa} declara la columna {$columna}");
                    $this->assertDoesNotMatchRegularExpression(self::CRUDO, $columna, "{$relativa} declara la columna {$columna}: el cuerpo de un webhook lleva el número y la transcripción (D8-17)");
                }
            }
        }
    }

    // ── 6. Webhooks ─────────────────────────────────────────────────────────

    public function test_cada_entrada_publica_llega_a_su_validador_y_los_validadores_comparan_bien(): void
    {
        foreach ($this->php('app/Http/Controllers/Api/Voice') as $relativa => $ruta) {
            $nombres = $this->clasesNombradas($ruta);
            // Nombra un validador, o llama al verificador (también por la fábrica: `VoiceAdapters::eventVerifier()->verifyPostCall(…)`).
            $directo = array_intersect($nombres, self::VALIDADORES) !== [] || in_array('verifypostcall', $this->metodosLlamados($ruta), true);
            $indirecto = false;
            foreach ($nombres as $clase) {
                $servicio = base_path(self::TELEFONIA_SERVICIOS.$clase.'.php');
                if (is_file($servicio) && (array_intersect($this->clasesNombradas($servicio), self::VALIDADORES) !== [] || in_array('verifypostcall', $this->metodosLlamados($servicio), true))) {
                    $indirecto = true;
                }
            }
            $this->assertTrue($directo || $indirecto, "{$relativa} es una entrada pública de voz y no llega a ningún validador (D8-16)");
        }

        foreach (self::FICHEROS_VALIDADORES as $relativa) {
            $ruta = base_path($relativa);
            if (! is_file($ruta)) {
                continue;
            }
            $funciones = $this->funcionesLlamadas($ruta);
            $this->assertContains('hash_equals', $funciones, "{$relativa} no compara en tiempo constante");
            $this->assertNotContains('strcmp', $funciones, "{$relativa} compara con strcmp()");
        }
        $verificador = base_path('app/Services/Voice/ElevenLabs/ElevenLabsEventVerifier.php');
        if (is_file($verificador)) {
            $this->assertContains('ElevenLabsWebhookSignature', $this->clasesNombradas($verificador), 'ElevenLabsEventVerifier envuelve la firma del H6, sin reescribirla (D8-3)');
            $this->assertNotContains('hash_hmac', $this->funcionesLlamadas($verificador), 'ElevenLabsEventVerifier reimplementa el HMAC');
        }

        foreach ($this->php(...self::TELEFONIA) + $this->php(...self::NEUTRO) as $relativa => $ruta) {
            $this->assertNotContains('fullurl', $this->metodosLlamados($ruta), "{$relativa} usa fullUrl(): reordena la consulta y rompe la firma (OTP-3); la URL es la base canónica más la URI tal como llega");
        }
    }

    // ── 7. Una sola puerta ──────────────────────────────────────────────────

    public function test_una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio(): void
    {
        $quien = ['startoutbound' => [], 'assignnumber' => [], 'createagent' => [], 'signedsessionurl' => []];
        $rastreados = $this->php('app', 'routes');
        $this->assertGreaterThan(500, count($rastreados), 'el rastreo ve la aplicación');
        foreach ($rastreados as $relativa => $ruta) {
            $codigo = (string) file_get_contents($ruta);
            if (preg_match('/startOutbound|assignNumber|createAgent|signedSessionUrl/', $codigo) !== 1) {
                continue;
            }
            foreach (array_intersect($this->metodosLlamados($ruta), array_keys($quien)) as $metodo) {
                $quien[$metodo][] = $relativa;
            }
        }
        foreach ($quien['startoutbound'] as $relativa) {
            $this->assertStringStartsWith('app/Jobs/Voice/', $relativa, "{$relativa} inicia una saliente: solo el trabajo de voz, detrás de la puerta (D8-12)");
        }
        foreach ($quien['assignnumber'] as $relativa) {
            $this->assertSame('app/Services/UltronStudio/StudioVoicePublishing.php', $relativa, "{$relativa} asigna el número: solo la publicación (D8-7); un agente de laboratorio nunca tiene número");
        }
        foreach ($quien['createagent'] as $relativa) {
            $this->assertMatchesRegularExpression('#^app/Services/UltronStudio/StudioVoice(Publishing|Lab[A-Za-z]*)\.php$#', $relativa, "{$relativa} crea un agente fuera de la publicación y del laboratorio");
        }
        foreach ($quien['signedsessionurl'] as $relativa) {
            $this->assertMatchesRegularExpression('#^app/Services/UltronStudio/StudioVoiceLab[A-Za-z]*\.php$#', $relativa, "{$relativa} pide una URL firmada fuera del laboratorio");
        }

        // El trabajo de la saliente: un intento, sin reintentos ni vuelta a la cola, en su carril (D8-12).
        foreach ($this->php('app/Jobs/Voice') as $relativa => $ruta) {
            $codigo = preg_replace('/\s+/', ' ', $this->codigo($ruta));
            $this->assertMatchesRegularExpression('/public (int )?\$tries = 1;/', (string) $codigo, "{$relativa}: tries = 1 (D8-12)");
            $this->assertDoesNotMatchRegularExpression('/function (backoff|retryUntil)\b|\$backoff\b|\$maxExceptions\b/', (string) $codigo, "{$relativa} reintenta");
            $this->assertNotContains('release', $this->metodosLlamados($ruta), "{$relativa} vuelve a la cola");
            $this->assertContains('voice', $this->literales($ruta), "{$relativa} no va al carril voice (D8-4)");
        }

        // Las políticas nuevas componen la del H6 (protección 2).
        $salientes = base_path(self::TELEFONIA_SERVICIOS.'OutboundPolicy.php');
        if (is_file($salientes)) {
            $this->assertStringContainsString('VoiceCallPolicy::decide(', $this->codigo($salientes), 'OutboundPolicy compone VoiceCallPolicy::decide() (D8-12)');
        }
        $entrantes = base_path(self::TELEFONIA_SERVICIOS.'InboundPolicy.php');
        if (is_file($entrantes)) {
            $this->assertMatchesRegularExpression('/VoiceCallPolicy::(CALLS_ENABLED_IN_CODE|decide\()/', $this->codigo($entrantes), 'InboundPolicy mira la constante del H6 (D8-11)');
        }
    }

    // ── 8. El Studio de voz ─────────────────────────────────────────────────

    public function test_el_studio_de_voz_solo_nombra_los_adaptadores_enumerados(): void
    {
        $permitidasDeVoz = ['VoiceAgentDeployer', 'VoiceEventVerifier', 'VoiceLabSessionIssuer', 'VoiceAdapters', 'VoiceProviders', 'VoiceProviderStatus', 'VoiceCheckResult', 'ElevenLabsAgentMapper'];
        $this->assertGreaterThan(10, count($this->glob('app/Services/UltronStudio/*.php')), 'el rastreo ve el Studio');
        foreach ($this->glob(...self::STUDIO_VOZ) as $relativa => $ruta) {
            $tokens = $this->tokens($ruta);
            foreach ($tokens as $i => $t) {
                if (! in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if ($t[0] === T_STRING && $t[1] === 'VoicePublishedConfig' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                        $miembro = $tokens[$i + 2][1] ?? '';
                        $this->assertTrue($miembro === 'signatureFor' || preg_match('/^[A-Z][A-Z0-9_]*$/', $miembro) === 1, "{$relativa} usa VoicePublishedConfig::{$miembro}: del lector, solo la firma");
                    }

                    continue;
                }
                $nombre = ltrim($t[1], '\\');
                // E2-6: la evidencia del canario y el freno, SOLO por los servicios de B8 y SOLO desde StudioVoiceEvidence.
                $porE26 = $relativa === 'app/Services/UltronStudio/StudioVoiceEvidence.php'
                    && in_array($nombre, ['App\\Services\\Telephony\\VoiceEvidence', 'App\\Services\\Telephony\\VoiceBrake'], true);
                if (str_starts_with($nombre, 'App\\Services\\Telephony\\') && ! $porE26) {
                    $this->assertSame('App\\Services\\Telephony\\VoicePublishedConfig', $nombre, "{$relativa} nombra {$nombre}: de la telefonía, el Studio solo nombra VoicePublishedConfig::signatureFor() (D8-2)");
                }
                if (str_starts_with($nombre, 'App\\Services\\Voice\\')) {
                    $this->assertTrue(str_starts_with($nombre, 'App\\Services\\Voice\\Dto\\') || in_array($this->corto($nombre), $permitidasDeVoz, true), "{$relativa} nombra {$nombre}: el Studio no usa un adaptador con red, el iniciador ni el transportista (D8-2, D8-3)");
                }
                foreach (['App\\Services\\Twilio\\', 'App\\Models\\Voice\\', 'App\\Jobs\\Voice\\', 'App\\Http\\Controllers\\Api\\Voice\\', 'App\\Http\\Controllers\\Crm\\Voice\\'] as $ajeno) {
                    $this->assertStringStartsNotWith($ajeno, $nombre, "{$relativa} nombra {$nombre}: no es un adaptador enumerado de D8-2 (decisión del lead si hace falta)");
                }
            }
            $this->assertNotContains('Http', $this->usos($ruta), "{$relativa} sale a la red: va por las interfaces de D8-3");
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    private function assertNombreGuardable(string $nombre, string $donde): void
    {
        if (in_array($nombre, self::EVENTOS, true)) {
            return;
        }
        if (preg_match(self::TELEFONO, $nombre) === 1) {
            $this->assertMatchesRegularExpression(self::SUFIJO_SEGURO, $nombre, "{$donde}: un teléfono se guarda, se devuelve o se registra como huella, 4 dígitos o cifrado (D8-17)");
        }
        $this->assertDoesNotMatchRegularExpression(self::TEXTO, $nombre, "{$donde}: Laravel no guarda transcripciones ni audio (D8-17, P-8)");
    }

    /** Un argumento de una llamada de registro: ni teléfonos, ni textos, ni cuerpos crudos, ni volcados. */
    private function assertArgumentoDeRegistroLimpio(array $tokens, int $j, string $relativa): void
    {
        $t = $tokens[$j];
        $nombre = match (true) {
            $t[0] === T_VARIABLE => $this->serpiente(ltrim($t[1], '$')),
            $t[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW => $this->literal($t[1]),
            $t[0] === T_STRING && in_array($tokens[$j - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) => $this->serpiente($t[1]),
            default => null,
        };
        if ($nombre !== null && preg_match(self::SUFIJO_SEGURO, $nombre) !== 1) {
            $this->assertDoesNotMatchRegularExpression(self::TELEFONO, $nombre, "{$relativa} registra {$t[1]}: sin teléfonos en el log (D8-4)");
            $this->assertDoesNotMatchRegularExpression(self::TEXTO, $nombre, "{$relativa} registra {$t[1]}: sin transcripciones en el log (D8-4)");
            $this->assertDoesNotMatchRegularExpression(self::CRUDO, $nombre, "{$relativa} registra {$t[1]}: un cuerpo crudo lleva el número y la transcripción");
        }
        if ($t[0] === T_STRING && ($tokens[$j + 1][1] ?? null) === '(') {
            $this->assertNotContains(strtolower($t[1]), ['all', 'getcontent', 'json_encode', 'serialize', 'print_r', 'var_export', 'toarray', 'attributestoarray', 'getattributes', 'getmessage', 'gettraceasstring'], "{$relativa} vuelca {$t[1]}() en el log");
        }
    }

    /** ¿Es esta clave parte de unas reglas de validación? (argumento de validate()/make() o de un método rules()) */
    private function esValidacion(array $tokens, int $i, array $metodos): bool
    {
        foreach ($metodos as [$nombre, $desde, $hasta]) {
            if ($i > $desde && $i < $hasta && strtolower($nombre) === 'rules') {
                return true;
            }
        }
        $profundidad = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $texto = $tokens[$j][1];
            if (in_array($texto, [')', ']'], true)) {
                $profundidad++;
            } elseif (in_array($texto, ['(', '['], true)) {
                if ($profundidad === 0 && $texto === '(') {
                    return in_array(strtolower($tokens[$j - 1][1] ?? ''), ['validate', 'make', 'validatewithbag', 'validated'], true);
                }
                if ($profundidad > 0) {
                    $profundidad--;
                }
            } elseif ($profundidad === 0 && in_array($texto, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Los índices de los tokens de los argumentos de la llamada cuyo paréntesis abre en $abre.
     *
     * @return list<int>
     */
    private function argumentos(array $tokens, int $abre): array
    {
        $out = [];
        $profundidad = 0;
        for ($j = $abre; isset($tokens[$j]); $j++) {
            if ($tokens[$j][1] === '(') {
                $profundidad++;
            } elseif ($tokens[$j][1] === ')') {
                $profundidad--;
                if ($profundidad === 0) {
                    break;
                }
            }
            if ($j > $abre) {
                $out[] = $j;
            }
        }

        return $out;
    }

    /** ¿Es del motor este nombre de clase? */
    private function esDelMotor(string $completo): bool
    {
        $corto = $this->corto($completo);

        return str_contains($completo, 'Services\\Marketing\\')
            || preg_match('/^Marketing[A-Z]/', $corto) === 1
            || in_array($corto, ['OutboundContentGuard', 'SalesAgentDecisionSchema', 'ConsentAuthority', 'UltronCommitService', 'UltronPublishedConfig'], true)
            || (str_starts_with($completo, 'App\\Jobs\\') && ! str_starts_with($completo, 'App\\Jobs\\Voice\\'))
            || preg_match('/WhatsApp|Whatsapp/', $completo) === 1
            || preg_match('/^Meta[A-Z]/', $corto) === 1
            || str_contains($completo, 'Services\\Meta\\');
    }

    /** @return list<array{0:string,1:string}> lo que nombra del Studio (tipo de token y texto), mirando solo el código */
    private function referenciasAlStudio(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (! is_array($t) || in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $cadena = $t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE;
            if (str_contains($t[1], 'UltronStudio') || str_contains($t[1], 'ultron_studio_') || ($cadena && preg_match('/^[\'"]?ultron_studio(\.|[\'"]?$)/', $t[1]) === 1)) {
                $out[] = [token_name($t[0]), $t[1]];
            }
        }

        return $out;
    }

    /** @return list<string> las llamadas de escritura (o de estado) de un fichero, en minúscula */
    private function escrituras(string $ruta): array
    {
        $out = array_values(array_intersect($this->metodosLlamados($ruta), self::ESCRITURAS));
        // `DB` y `Cache` leen también: sus escrituras ya salen por el nombre del método (insert, update, statement…).
        foreach (['Queue', 'Bus', 'Storage', 'Event'] as $fachada) {
            if (in_array($fachada, $this->usos($ruta), true)) {
                $out[] = $fachada;
            }
        }
        foreach (['dispatch', 'event', 'cache'] as $funcion) {
            if (in_array($funcion, $this->funcionesLlamadas($ruta), true)) {
                $out[] = $funcion.'()';
            }
        }

        return $out;
    }

    /** @return list<string> las clases que nombra un fichero, por su nombre corto */
    private function clasesNombradas(string $ruta): array
    {
        $out = [];
        foreach ($this->tokens($ruta) as $i => $t) {
            if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/^[A-Z]/', $this->corto($t[1])) === 1) {
                $out[] = $this->corto($t[1]);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string,string> las migraciones del H8 que nombran la voz (DB8: `2026_11_1*_*voice*`) */
    private function migracionesDeVoz(): array
    {
        return $this->glob('database/migrations/2026_11_1*voice*.php');
    }

    /** @return list<string> las claves literales que se leen de la configuración (`(no literal)` si no lo es) */
    private function lecturasDeConfiguracion(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            $abre = null;
            if ($t[0] === T_STRING && strtolower($t[1]) === 'config' && ($tokens[$i + 1][1] ?? null) === '(' && ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                if (($tokens[$i + 2][1] ?? null) === ')') {
                    // config()->get('x'), config()->string('x')…
                    if (($tokens[$i + 3][0] ?? null) === T_OBJECT_OPERATOR && ($tokens[$i + 5][1] ?? null) === '(') {
                        $abre = $i + 5;
                    }
                } else {
                    $abre = $i + 1;
                }
            }
            if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $this->corto($t[1]) === 'Config' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON && ($tokens[$i + 3][1] ?? null) === '(') {
                $abre = $i + 3;
            }
            if ($abre !== null) {
                $arg = $tokens[$abre + 1] ?? null;
                $out[] = $arg !== null && $arg[0] === T_CONSTANT_ENCAPSED_STRING ? $this->literal($arg[1]) : '(no literal)';
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array{0:string,1:int,2:int}> los métodos con cuerpo y sus límites */
    private function rangosDeMetodos(array $tokens): array
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

    /** @return list<string> los métodos que se llaman (`->x(`, `?->x(` o `::x(`), en minúscula */
    private function metodosLlamados(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && ($tokens[$i + 1][1] ?? null) === '(') {
                $out[] = strtolower($t[1]);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> las funciones (no métodos) que se llaman, en minúscula */
    private function funcionesLlamadas(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$i + 1][1] ?? null) === '('
                && ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                $out[] = strtolower($this->corto($t[1]));
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> los nombres usados como función o como clase (`::`, `new`, `use`), por su nombre corto */
    private function usos(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
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
            if (($despues !== null && in_array($despues[1], ['(', '::'], true)) || ($antes !== null && in_array($antes[0], [T_NEW, T_USE], true))) {
                $out[] = $this->corto($t[1]);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> los literales de cadena, sin comillas */
    private function literales(string $ruta): array
    {
        $out = [];
        foreach ($this->tokens($ruta) as $t) {
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = $this->literal($t[1]);
            }
        }

        return $out;
    }

    /** `toNumber` → `to_number`; `$callerId` → `caller_id`. */
    private function serpiente(string $nombre): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $nombre));
    }

    /** @return array<string,string> ruta relativa → absoluta, de todo el PHP bajo estos ficheros o directorios (los que no existan, se saltan) */
    private function php(string ...$raices): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices as $raiz) {
            $absoluta = $base.$raiz;
            if (is_file($absoluta)) {
                $out[$raiz] = $absoluta;

                continue;
            }
            if (! is_dir($absoluta)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[substr($f->getPathname(), strlen($base))] = $f->getPathname();
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,string> lo mismo, por patrones */
    private function glob(string ...$patrones): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($patrones as $patron) {
            foreach (glob($base.$patron) ?: [] as $f) {
                if (is_file($f)) {
                    $out[substr($f, strlen($base))] = $f;
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** El código sin comentarios (las cadenas se quedan). */
    private function codigo(string $ruta): string
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

    /** @return list<array{0:int,1:string}> sin comentarios ni espacios */
    private function tokens(string $ruta): array
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

    private function corto(string $nombre): string
    {
        return substr((string) strrchr('\\'.$nombre, '\\'), 1);
    }

    private function literal(string $token): string
    {
        return stripcslashes(substr($token, 1, -1));
    }
}
