<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\Voice\Dto\VoiceIds;
use App\Support\Text\InvisibleCharacters;
use Closure;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * EL COMPILADOR DE LA VOZ (contrato H8 §D8-6): de una versión del Studio y un
 * perfil de voz a la especificación NEUTRA del agente, con su huella. Puro:
 * ni base, ni configuración, ni reloj, ni red. Quien publica (S8-b) le pasa la
 * versión, el perfil y el tope de duración del servidor; el laboratorio
 * hablado (LAB8), lo mismo con el propósito `lab`.
 *
 * ENTRADAS (las tres obligatorias):
 *  1. La versión: `{uuid, status, contentHash, content, createdBy, approvedBy}`
 *     (`content` es el de `ultron_studio_versions`; `approvedBy`, quien firmó
 *     la reseña `approved`, o null). Se usan sus parámetros de forma
 *     (`persona.tone`, `strategy.goal`, `intent.fallback`), sus cuatro
 *     interruptores críticos y las frases que prohíben sus reglas S-NNN.
 *  2. El perfil de voz: `{uuid, status, contentHash, content, createdBy,
 *     approvedBy}`, con el contenido CERRADO de {@see PROFILE_FIELDS}. Los
 *     destinos de transferencia llegan como `{alias, phoneHash}`: el teléfono
 *     nunca entra aquí.
 *  3. {@see VERSION}, una constante del código, más la plantilla
 *     ({@see StudioVoiceTemplate}), cuya huella viaja en la especificación.
 *
 * SALIDA: `{spec, specHash}`, la entrada de `VoiceAgentSpec::fromCompiled()`
 * (el lector estricto de B8 en el espacio neutro). `spec` es un JSON canónico
 * sin un solo nombre del proveedor, sin secretos y SIN TELÉFONOS (cada destino
 * es su alias y su huella, `ref`; el número lo pone, descifrado en memoria,
 * quien despliega: D8-14); `specHash`, el sha256 de su JSON canónico
 * ({@see StudioContentHasher}): no cambia si se reordenan las claves, y los
 * conjuntos (herramientas, destinos, frases) salen ordenados, así que tampoco
 * cambia si el perfil los trae en otro orden. El PROPÓSITO no entra en la
 * especificación: lo que se prueba en el laboratorio y lo que se publica
 * desde la misma versión y el mismo perfil tienen el mismo `specHash`.
 *
 * SE NIEGA A COMPILAR ({@see REFUSALS}) con una UltronStudioException 422
 * `voice_compile_refused` y `{reason, field?}`: `field` es la ruta del campo,
 * nunca su valor. Comprueba en este orden y se para en el primer motivo:
 * versión (estado, cuatro ojos, huella, catálogo, interruptores, parámetros),
 * perfil (estado, cuatro ojos, huella, campos), frases de las reglas S-NNN.
 * Lo que no es del dominio (un propósito desconocido, un tope de servidor
 * fuera de 60–600, una envoltura sin forma) es un error de quien llama:
 * InvalidArgumentException. R-07 al desplegar (los números descifrados son
 * exactamente los destinos aprobados): {@see assertDestinationsMatch()}.
 *
 * DECISIONES DE ESTA VERSIÓN (informe S8, para que el lead las confirme):
 *  - versión «aprobada» = APPROVED, CANARY o PUBLISHED (aprobada y vigente,
 *    como las vinculables del H6); el laboratorio admite además VALIDATED y
 *    REVIEW_REQUIRED (enviada a revisión), y el perfil `submitted` (D8-23);
 *  - los cuatro interruptores críticos de la versión, como segundo cerrojo;
 *  - `no_volver_a_llamar` es obligatoria (la revocación oral de la Ley 2300);
 *  - `transfer_to_number` exige `solicitar_humano` (D8-14);
 *  - buzón: solo `hang_up` (P-10 recomendado): dejar un mensaje exige otra
 *    plantilla con su texto aprobado.
 */
final class StudioVoiceCompiler
{
    public const VERSION = 'ultron-voice-compiler/1';

    /** El contrato de la especificación neutra. */
    public const SPEC_CONTRACT = 'ultron-voice-spec/1';

    /** El formato del contenido de un perfil de voz. */
    public const PROFILE_FORMAT = 'ultron-voice-profile/1';

    public const PURPOSE_PUBLICATION = 'publication';

    public const PURPOSE_LAB = 'lab';

    public const PURPOSES = [self::PURPOSE_PUBLICATION, self::PURPOSE_LAB];

    /**
     * Las versiones aprobadas por otra persona y VIGENTES: las mismas que se
     * pueden vincular a una campaña en el H6 (`StudioCampaignLinks::LINKABLE`).
     * Una RETIRED fue sustituida y una ROLLED_BACK, revertida: no se compilan.
     */
    public const APPROVED_VERSION_STATES = ['APPROVED', 'CANARY', 'PUBLISHED'];

    /** Las versiones enviadas a revisión: solo para el laboratorio (D8-23). */
    public const SUBMITTED_VERSION_STATES = ['VALIDATED', 'REVIEW_REQUIRED'];

    public const PROFILE_APPROVED = 'approved';

    public const PROFILE_SUBMITTED = 'submitted';

    /** Los campos del contenido de un perfil: un conjunto CERRADO (D8-6). */
    public const PROFILE_FIELDS = [
        'format', 'voiceId', 'language', 'aiNotice', 'greetings', 'holes', 'maxDurationSeconds',
        'tools', 'systemTools', 'transfer', 'privacy', 'voicemail',
    ];

    public const GREETING_FIELDS = ['inbound', 'outbound'];

    public const TRANSFER_FIELDS = ['destinations'];

    public const DESTINATION_FIELDS = ['alias', 'phoneHash'];

    public const PRIVACY_FIELDS = ['retentionDays', 'storeAudio', 'recording'];

    /** Destinos de transferencia, como mucho (D8-14). */
    public const MAX_DESTINATIONS = 3;

    /** El mínimo de `max_duration_seconds` del proveedor (auditoría §d.1). */
    public const MIN_DURATION_SECONDS = 60;

    /** El recorte de `VOICE_PER_CALL_MAX_SECONDS` (D8-15): el tope del servidor nunca pasa de aquí. */
    public const MAX_DURATION_CEILING = 600;

    /** P-7 (supuesto del lead): grabación apagada. */
    public const RECORDING_ALLOWED = false;

    /** P-8 (supuesto del lead): audio guardado apagado. */
    public const STORE_AUDIO_ALLOWED = false;

    /** P-8 (supuesto del lead): retención en el proveedor de 30 días como mucho. */
    public const MAX_RETENTION_DAYS = 30;

    /** La retención «ilimitada» del proveedor. */
    public const UNLIMITED_RETENTION = -1;

    /** P-10 recomendado: colgar sin dejar mensaje. */
    public const VOICEMAIL_POLICIES = ['hang_up'];

    /** Un id de voz del proveedor: letras y dígitos. */
    public const VOICE_ID_PATTERN = '/^[A-Za-z0-9]{1,64}$/D';

    /** Una huella: sha256 o HMAC-SHA256 en hexadecimal minúsculo. */
    public const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /** E.164 estricto (el de `StudioCanaryPhone`). */
    public const E164_PATTERN = StudioCanaryPhone::E164_PATTERN;

    public const ERROR_CODE = 'voice_compile_refused';

    /** Los motivos, en un conjunto cerrado, con el texto del 422 (nunca repite lo que se escribió). */
    public const REFUSALS = [
        'version_not_approved' => 'La versión del Studio no está aprobada: la voz solo se compila desde una versión aprobada con cuatro ojos (en el laboratorio, también desde una enviada a revisión).',
        'version_four_eyes' => 'La versión del Studio no tiene la aprobación de otra persona.',
        'version_hash_mismatch' => 'La huella de la versión del Studio no coincide con su contenido.',
        'version_catalog_mismatch' => 'La versión del Studio se creó con otro catálogo: crea una versión nueva.',
        'version_facts_unverified' => 'La versión admite hechos sin verificar (R-01): la voz no se compila.',
        'version_discounts_allowed' => 'La versión admite descuentos (R-02): la voz no se compila.',
        'version_payment_enabled' => 'La versión enciende los enlaces de pago: la voz nunca cobra.',
        'version_handoff_uncorroborated' => 'La versión deriva a una persona sin corroborar (R-07): la voz no se compila.',
        'version_param_invalid' => 'Un parámetro de la versión que usa la voz (tono, objetivo o qué hacer si no entiende) no es una opción del catálogo.',
        'profile_not_approved' => 'El perfil de voz no está aprobado (en el laboratorio, también vale enviado a revisión).',
        'profile_four_eyes' => 'El perfil de voz no tiene la aprobación de otra persona.',
        'profile_hash_mismatch' => 'La huella del perfil de voz no coincide con su contenido.',
        'profile_field_unknown' => 'El perfil de voz tiene un campo que no existe.',
        'profile_field_invalid' => 'Un campo del perfil de voz no tiene un valor admitido.',
        'required_field_empty' => 'Falta un campo obligatorio del perfil de voz.',
        'notice_missing' => 'Falta el aviso de IA del principio de la llamada (P-9).',
        'notice_mismatch' => 'El aviso de IA del perfil no es el de la plantilla vigente: hay que revisar el perfil otra vez.',
        'text_invisible' => 'Un texto del perfil de voz lleva un carácter invisible, de control o un salto de línea. Suele colarse al copiar y pegar: escríbelo de nuevo.',
        'text_email' => 'Un texto del perfil de voz lleva un correo.',
        'text_phone' => 'Un texto del perfil de voz lleva un teléfono o una serie de dígitos.',
        'text_url' => 'Un texto del perfil de voz lleva una dirección web.',
        'text_money' => 'Un texto del perfil de voz lleva una cifra de dinero: los precios solo salen de la herramienta del catálogo.',
        'text_discount' => 'Un texto del perfil de voz lleva vocabulario de descuento (R-02).',
        'text_characters' => 'Un texto del perfil de voz lleva caracteres que la plantilla no admite (llaves, comillas angulares, símbolos o emojis).',
        'text_too_short' => 'Un texto del perfil de voz es demasiado corto.',
        'text_too_long' => 'Un texto del perfil de voz es demasiado largo.',
        'tool_not_in_catalog' => 'Una herramienta del perfil no está en el catálogo cerrado de la voz.',
        'system_tool_not_allowed' => 'Solo se admiten las herramientas de sistema end_call y transfer_to_number.',
        'end_call_required' => 'Falta end_call: el agente tiene que poder terminar la llamada.',
        'opt_out_tool_required' => 'Falta no_volver_a_llamar: la persona tiene que poder pedir que no la llamen más (Ley 2300).',
        'transfer_tools_mismatch' => 'transfer_to_number necesita solicitar_humano: no se transfiere sin comprobar antes que hay alguien disponible (D8-14).',
        'transfer_destinations_invalid' => 'Los destinos de transferencia no son válidos: de 1 a 3 con transfer_to_number, ninguno sin ella, y sin repetir.',
        'transfer_destination_not_in_profile' => 'Un destino de transferencia no es de los aprobados en el perfil de voz (R-07).',
        'duration_exceeds_limit' => 'La duración máxima del perfil supera el tope de duración del servidor.',
        'recording_not_allowed' => 'La grabación de las llamadas está apagada (P-7).',
        'audio_retention_not_allowed' => 'El audio guardado está apagado (P-8).',
        'retention_exceeds_limit' => 'La retención supera el máximo admitido (P-8).',
        'rule_phrase_invalid' => 'Una frase que prohíbe una regla del Studio no puede ir en el prompt de la voz (invisibles, datos de contacto, una dirección web o dinero).',
    ];

    /** El «si» de una regla se pregunta con esta frase de sonda (ver {@see forbiddenPhrases()}). */
    private const WHEN_PROBE = 'sonda del compilador de voz';

    /** El motivo de cada comprobación de texto de la plantilla. */
    private const TEXT_REASONS = [
        StudioVoiceTemplate::TEXT_INVISIBLE => 'text_invisible',
        StudioVoiceTemplate::TEXT_EMAIL => 'text_email',
        StudioVoiceTemplate::TEXT_PHONE => 'text_phone',
        StudioVoiceTemplate::TEXT_URL => 'text_url',
        StudioVoiceTemplate::TEXT_MONEY => 'text_money',
        StudioVoiceTemplate::TEXT_DISCOUNT => 'text_discount',
        StudioVoiceTemplate::TEXT_CHARACTERS => 'text_characters',
    ];

    /**
     * Compila. Ver la cabecera de la clase.
     *
     * @param  array<string,mixed>  $version
     * @param  array<string,mixed>  $profile
     * @param  int  $maxCallSeconds  `VOICE_PER_CALL_MAX_SECONDS` efectivo (60–600)
     * @return array{spec:array<string,mixed>,specHash:string}
     *
     * @throws UltronStudioException 422 `voice_compile_refused`
     * @throws InvalidArgumentException si quien llama no da la forma
     */
    public static function compile(array $version, array $profile, int $maxCallSeconds, string $purpose = self::PURPOSE_PUBLICATION): array
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Propósito de compilación desconocido.');
        }
        if ($maxCallSeconds < self::MIN_DURATION_SECONDS || $maxCallSeconds > self::MAX_DURATION_CEILING) {
            throw new InvalidArgumentException('El tope de duración del servidor tiene que estar entre 60 y 600 segundos.');
        }
        $v = self::envelope($version, 'versión');
        $p = self::envelope($profile, 'perfil');

        $parametros = self::checkVersion($v, $purpose);
        $perfil = self::checkProfile($p, $purpose, $maxCallSeconds);
        $frases = self::forbiddenPhrases($v['content']);

        $herramientas = $perfil['tools'];
        $textos = StudioVoiceTemplate::render([
            'tone' => $parametros['tone'],
            'goal' => $parametros['goal'],
            'fallback' => $parametros['fallback'],
            'inboundGreeting' => $perfil['greetings']['inbound'],
            'outboundGreeting' => $perfil['greetings']['outbound'],
            'holes' => $perfil['holes'],
            'tools' => $herramientas,
            'transfer' => in_array(StudioVoiceTemplate::SYSTEM_TRANSFER, $perfil['systemTools'], true),
            'forbiddenPhrases' => $frases,
        ]);
        self::assertRenderedTexts($textos);

        $catalogo = StudioVoiceTemplate::serverTools();
        $spec = [
            'contract' => self::SPEC_CONTRACT,
            'compilerVersion' => self::VERSION,
            'template' => ['version' => StudioVoiceTemplate::VERSION, 'hash' => StudioVoiceTemplate::hash()],
            'source' => [
                'studioVersionUuid' => $v['uuid'],
                'studioContentHash' => $v['contentHash'],
                'catalogVersion' => StudioCatalog::VERSION,
                'voiceProfileUuid' => $p['uuid'],
                'voiceProfileHash' => $p['contentHash'],
            ],
            'language' => StudioVoiceTemplate::LANGUAGE,
            'voiceId' => $perfil['voiceId'],
            'firstMessage' => $textos['firstMessage'],
            'systemPrompt' => $textos['systemPrompt'],
            'dynamicVariables' => StudioVoiceTemplate::DYNAMIC_VARIABLES,
            'overridesAllowed' => false,
            'maxDurationSeconds' => $perfil['maxDurationSeconds'],
            'tools' => array_map(static fn (string $t): array => ['name' => $t, 'description' => $catalogo[$t]], $herramientas),
            'systemTools' => array_map(static fn (string $t): array => ['name' => $t, 'description' => StudioVoiceTemplate::SYSTEM_TOOLS[$t]], $perfil['systemTools']),
            'transfer' => [
                'mode' => StudioVoiceTemplate::TRANSFER_MODE,
                'destinations' => array_map(static fn (array $d): array => [
                    'alias' => $d['alias'],
                    'ref' => $d['phoneHash'],
                    'condition' => sprintf(StudioVoiceTemplate::TRANSFER_CONDITION, $d['alias']),
                ], $perfil['destinations']),
            ],
            'prices' => StudioVoiceTemplate::pricePolicy(),
            'forbiddenPhrases' => $frases,
            'privacy' => $perfil['privacy'],
            'voicemail' => $perfil['voicemail'],
        ];

        return ['spec' => $spec, 'specHash' => self::specHash($spec)];
    }

    /** sha256 del JSON canónico de la especificación: el `specHash`. */
    public static function specHash(array $spec): string
    {
        return StudioContentHasher::hash($spec);
    }

    /**
     * La huella del contenido de un perfil de voz (D8-6: «con
     * StudioContentHasher»). Los conjuntos (herramientas y destinos) se
     * ordenan antes: el orden en que se eligieron no cambia el perfil. Lo que
     * no tiene forma se firma tal cual (la validación lo rechaza después).
     *
     * @param  array<string,mixed>  $content
     *
     * @throws InvalidArgumentException|JsonException si no se puede firmar (decimales, objetos, UTF-8 inválido)
     */
    public static function profileHash(array $content): string
    {
        foreach (['tools', 'systemTools'] as $conjunto) {
            if (is_array($content[$conjunto] ?? null) && array_is_list($content[$conjunto]) && self::allStrings($content[$conjunto])) {
                $lista = $content[$conjunto];
                sort($lista, SORT_STRING);
                $content[$conjunto] = $lista;
            }
        }
        $destinos = $content['transfer']['destinations'] ?? null;
        if (is_array($destinos) && array_is_list($destinos) && self::sortableDestinations($destinos)) {
            usort($destinos, static fn (array $a, array $b): int => strcmp($a['alias'], $b['alias']));
            $content['transfer']['destinations'] = $destinos;
        }

        return StudioContentHasher::hash($content);
    }

    /** El 422 del compilador. */
    public static function refusal(string $reason, ?string $field = null): UltronStudioException
    {
        $mensaje = self::REFUSALS[$reason] ?? throw new LogicException('Motivo de negativa desconocido: '.$reason);
        $payload = ['reason' => $reason];
        if ($field !== null) {
            $payload['field'] = $field;
        }

        return new UltronStudioException($mensaje, self::ERROR_CODE, 422, $payload);
    }

    /**
     * R-07 al desplegar: los teléfonos que quien publica descifró EN MEMORIA
     * para la carga del proveedor (la forma de `VoiceAgentTarget`: `ref` →
     * E.164) son exactamente los destinos aprobados de la especificación, y
     * cada uno da su huella. Si no, `transfer_destination_not_in_profile`.
     * `$fingerprint` es la huella de voz de un E.164 (HMAC con su subclave,
     * D8-17): aquí no hay clave.
     *
     * @param  array<string,mixed>  $spec
     * @param  array<array-key,mixed>  $numbers  ref → E.164
     * @param  Closure(string):string  $fingerprint
     *
     * @throws UltronStudioException 422 `voice_compile_refused`
     */
    public static function assertDestinationsMatch(array $spec, #[\SensitiveParameter] array $numbers, Closure $fingerprint): void
    {
        $refs = array_column($spec['transfer']['destinations'] ?? [], 'ref');
        $campo = 'transfer.destinations';
        if (count($numbers) !== count($refs)) {
            throw self::refusal('transfer_destination_not_in_profile', $campo);
        }
        foreach ($numbers as $ref => $e164) {
            if (! is_string($ref) || ! in_array($ref, $refs, true) || ! is_string($e164) || preg_match(self::E164_PATTERN, $e164) !== 1) {
                throw self::refusal('transfer_destination_not_in_profile', $campo);
            }
            $huella = $fingerprint($e164);
            if (! is_string($huella) || ! hash_equals($ref, $huella)) {
                throw self::refusal('transfer_destination_not_in_profile', $campo);
            }
        }
    }

    // ── La versión ──────────────────────────────────────────────────────────

    /**
     * @param  array{uuid:string,status:string,contentHash:string,content:array<string,mixed>,createdBy:int,approvedBy:int|null}  $v
     * @return array{tone:string,goal:string,fallback:string}
     */
    private static function checkVersion(array $v, string $purpose): array
    {
        $aprobada = in_array($v['status'], self::APPROVED_VERSION_STATES, true);
        $enviada = in_array($v['status'], self::SUBMITTED_VERSION_STATES, true);
        if (! $aprobada && ! ($purpose === self::PURPOSE_LAB && $enviada)) {
            throw self::refusal('version_not_approved', 'version.status');
        }
        if ($aprobada && ($v['approvedBy'] === null || $v['approvedBy'] === $v['createdBy'])) {
            throw self::refusal('version_four_eyes', 'version.approvedBy');
        }

        $c = $v['content'];
        if (! self::versionIntegrityOk($c, $v['contentHash'])) {
            throw self::refusal('version_hash_mismatch', 'version.contentHash');
        }
        if ($c['catalogVersion'] !== StudioCatalog::VERSION) {
            throw self::refusal('version_catalog_mismatch', 'version.content.catalogVersion');
        }

        $configs = is_array($c['configs'] ?? null) ? $c['configs'] : [];
        $valor = static fn (string $capa, string $clave): mixed => is_array($configs[$capa] ?? null) && array_key_exists($clave, $configs[$capa]) ? $configs[$capa][$clave] : null;
        foreach ([
            ['knowledge', 'strictFacts', true, 'version_facts_unverified'],
            ['strategy', 'discountsAllowed', false, 'version_discounts_allowed'],
            ['rules', 'paymentLinksEnabled', false, 'version_payment_enabled'],
            ['rules', 'handoffOnlyCorroborated', true, 'version_handoff_uncorroborated'],
        ] as [$capa, $clave, $esperado, $motivo]) {
            // Falla cerrada: un interruptor ausente o de otro tipo no es el esperado.
            if ($valor($capa, $clave) !== $esperado) {
                throw self::refusal($motivo, "version.content.configs.{$capa}.{$clave}");
            }
        }

        $parametros = [];
        foreach ([
            'tone' => ['persona', 'tone', StudioVoiceTemplate::TONES],
            'goal' => ['strategy', 'goal', StudioVoiceTemplate::GOALS],
            'fallback' => ['intent', 'fallback', StudioVoiceTemplate::FALLBACKS],
        ] as $nombre => [$capa, $clave, $opciones]) {
            $actual = $valor($capa, $clave);
            // Las opciones de la plantilla son las del catálogo congelado (una prueba lo fija).
            if (! is_string($actual) || ! array_key_exists($actual, $opciones)) {
                throw self::refusal('version_param_invalid', "version.content.configs.{$capa}.{$clave}");
            }
            $parametros[$nombre] = $actual;
        }

        return $parametros;
    }

    /**
     * La de {@see StudioVersions::integrityOk()} sobre el contenido suelto: un
     * contenido que ni se puede firmar tampoco es íntegro.
     *
     * @param  array<string,mixed>  $content
     */
    private static function versionIntegrityOk(array $content, string $contentHash): bool
    {
        if (! is_string($content['catalogVersion'] ?? null) || ! is_array($content['rules'] ?? null)) {
            return false;
        }

        try {
            $hash = StudioContentHasher::versionHash($content['catalogVersion'], $content, $content['rules']);
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }

        return hash_equals($contentHash, $hash);
    }

    // ── El perfil ───────────────────────────────────────────────────────────

    /**
     * @param  array{uuid:string,status:string,contentHash:string,content:array<string,mixed>,createdBy:int,approvedBy:int|null}  $p
     * @return array{voiceId:string,greetings:array{inbound:string,outbound:string},holes:array<string,string>,maxDurationSeconds:int,tools:list<string>,systemTools:list<string>,destinations:list<array{alias:string,phoneHash:string}>,privacy:array{retentionDays:int,storeAudio:bool,recording:bool},voicemail:string}
     */
    private static function checkProfile(array $p, string $purpose, int $maxCallSeconds): array
    {
        $aprobado = $p['status'] === self::PROFILE_APPROVED;
        if (! $aprobado && ! ($purpose === self::PURPOSE_LAB && $p['status'] === self::PROFILE_SUBMITTED)) {
            throw self::refusal('profile_not_approved', 'profile.status');
        }
        if ($aprobado && ($p['approvedBy'] === null || $p['approvedBy'] === $p['createdBy'])) {
            throw self::refusal('profile_four_eyes', 'profile.approvedBy');
        }

        $c = $p['content'];
        try {
            $huella = self::profileHash($c);
        } catch (InvalidArgumentException|JsonException) {
            $huella = null;   // decimales, objetos o UTF-8 inválido: no se puede firmar, así que no es íntegro
        }
        if ($huella === null || ! hash_equals($p['contentHash'], $huella)) {
            throw self::refusal('profile_hash_mismatch', 'profile.contentHash');
        }

        return self::profileContent($c, $maxCallSeconds);
    }

    /**
     * El CONTENIDO de un perfil, sin mirar su estado, sus cuatro ojos ni su
     * huella: las mismas comprobaciones, en el mismo orden, que al compilar.
     * Lo usa también quien crea y aprueba perfiles (S8-b), para negarse con el
     * mismo motivo y el mismo campo antes de que el perfil llegue a compilarse.
     *
     * @param  array<string,mixed>  $c
     * @return array{voiceId:string,greetings:array{inbound:string,outbound:string},holes:array<string,string>,maxDurationSeconds:int,tools:list<string>,systemTools:list<string>,destinations:list<array{alias:string,phoneHash:string}>,privacy:array{retentionDays:int,storeAudio:bool,recording:bool},voicemail:string}
     *
     * @throws UltronStudioException 422 `voice_compile_refused`
     */
    public static function profileContent(array $c, int $maxCallSeconds): array
    {
        self::closedKeys($c, self::PROFILE_FIELDS, 'profile.content');

        if ($c['format'] !== self::PROFILE_FORMAT) {
            throw self::refusal('profile_field_invalid', 'profile.content.format');
        }
        if ($c['language'] !== StudioVoiceTemplate::LANGUAGE) {
            throw self::refusal('profile_field_invalid', 'profile.content.language');
        }
        if (! is_string($c['voiceId'])) {
            throw self::refusal('profile_field_invalid', 'profile.content.voiceId');
        }
        if ($c['voiceId'] === '') {
            throw self::refusal('required_field_empty', 'profile.content.voiceId');
        }
        if (preg_match(self::VOICE_ID_PATTERN, $c['voiceId']) !== 1) {
            throw self::refusal('profile_field_invalid', 'profile.content.voiceId');
        }

        // P-9: el aviso fijo, aprobado con el perfil, es el de la plantilla vigente.
        if (! is_string($c['aiNotice']) || trim($c['aiNotice']) === '') {
            throw self::refusal('notice_missing', 'profile.content.aiNotice');
        }
        if ($c['aiNotice'] !== StudioVoiceTemplate::AI_NOTICE) {
            throw self::refusal('notice_mismatch', 'profile.content.aiNotice');
        }

        if (! is_array($c['greetings'])) {
            throw self::refusal('profile_field_invalid', 'profile.content.greetings');
        }
        self::closedKeys($c['greetings'], self::GREETING_FIELDS, 'profile.content.greetings');
        $saludos = [];
        foreach (self::GREETING_FIELDS as $sentido) {
            $saludos[$sentido] = self::text($c['greetings'][$sentido], "profile.content.greetings.{$sentido}", StudioVoiceTemplate::GREETING_MAX);
        }

        if (! is_array($c['holes'])) {
            throw self::refusal('profile_field_invalid', 'profile.content.holes');
        }
        self::closedKeys($c['holes'], array_keys(StudioVoiceTemplate::HOLES), 'profile.content.holes');
        $huecos = [];
        foreach (StudioVoiceTemplate::HOLES as $hueco => $def) {
            $huecos[$hueco] = self::text($c['holes'][$hueco], "profile.content.holes.{$hueco}", $def['max']);
        }

        $duracion = $c['maxDurationSeconds'];
        if (! is_int($duracion) || $duracion < self::MIN_DURATION_SECONDS) {
            throw self::refusal('profile_field_invalid', 'profile.content.maxDurationSeconds');
        }
        if ($duracion > $maxCallSeconds) {
            throw self::refusal('duration_exceeds_limit', 'profile.content.maxDurationSeconds');
        }

        $catalogo = StudioVoiceTemplate::serverTools();
        $herramientas = self::nameSet($c['tools'], 'profile.content.tools');
        foreach ($herramientas as $i => $t) {
            if (! array_key_exists($t, $catalogo)) {
                throw self::refusal('tool_not_in_catalog', "profile.content.tools.{$i}");
            }
        }
        $sistema = self::nameSet($c['systemTools'], 'profile.content.systemTools');
        foreach ($sistema as $i => $t) {
            if (! array_key_exists($t, StudioVoiceTemplate::SYSTEM_TOOLS)) {
                throw self::refusal('system_tool_not_allowed', "profile.content.systemTools.{$i}");
            }
        }
        if (! in_array(StudioVoiceTemplate::SYSTEM_END_CALL, $sistema, true)) {
            throw self::refusal('end_call_required', 'profile.content.systemTools');
        }
        if (! in_array(StudioVoiceTemplate::TOOL_OPT_OUT, $herramientas, true)) {
            throw self::refusal('opt_out_tool_required', 'profile.content.tools');
        }
        // D8-14: el agente solo transfiere si solicitar_humano dice que sí. (Al
        // revés no hace falta: sin transferencia, la herramienta dice que no.)
        $transfiere = in_array(StudioVoiceTemplate::SYSTEM_TRANSFER, $sistema, true);
        if ($transfiere && ! in_array(StudioVoiceTemplate::TOOL_HUMAN, $herramientas, true)) {
            throw self::refusal('transfer_tools_mismatch', 'profile.content.systemTools');
        }

        $destinos = self::destinations($c['transfer'], $transfiere);
        $privacidad = self::privacy($c['privacy']);

        if (! is_string($c['voicemail']) || ! in_array($c['voicemail'], self::VOICEMAIL_POLICIES, true)) {
            throw self::refusal('profile_field_invalid', 'profile.content.voicemail');
        }

        sort($herramientas, SORT_STRING);
        sort($sistema, SORT_STRING);

        return [
            'voiceId' => $c['voiceId'],
            'greetings' => $saludos,
            'holes' => $huecos,
            'maxDurationSeconds' => $duracion,
            'tools' => $herramientas,
            'systemTools' => $sistema,
            'destinations' => $destinos,
            'privacy' => $privacidad,
            'voicemail' => $c['voicemail'],
        ];
    }

    /**
     * Un texto del perfil: obligatorio, acotado, comprobado por la plantilla
     * y sin espacios en los bordes (se guarda tal como se firma).
     */
    private static function text(mixed $value, string $field, int $max): string
    {
        if (! is_string($value)) {
            throw self::refusal('profile_field_invalid', $field);
        }
        if (trim($value) === '') {
            throw self::refusal('required_field_empty', $field);
        }
        // Antes que las expresiones: un texto enorme no llega a evaluarse. (El
        // UTF-8 inválido lo rechaza la plantilla como invisible.)
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw self::refusal('text_too_long', $field);
        }
        $motivo = StudioVoiceTemplate::textViolation($value);
        if ($motivo !== null) {
            throw self::refusal(self::TEXT_REASONS[$motivo], $field);
        }
        if ($value !== trim($value)) {
            throw self::refusal('profile_field_invalid', $field);
        }
        if (mb_strlen($value, 'UTF-8') < StudioVoiceTemplate::TEXT_MIN) {
            throw self::refusal('text_too_short', $field);
        }

        return $value;
    }

    /**
     * Una lista de nombres sin repetir.
     *
     * @return list<string>
     */
    private static function nameSet(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || ! self::allStrings($value)) {
            throw self::refusal('profile_field_invalid', $field);
        }
        if (count(array_unique($value)) !== count($value)) {
            throw self::refusal('profile_field_invalid', $field);
        }

        return $value;
    }

    /**
     * Los destinos: `{alias, phoneHash}`, el alias con la regla de los alias
     * de los canarios (una función, no una persona) y la huella de voz del
     * teléfono.
     *
     * @return list<array{alias:string,phoneHash:string}> ordenados por alias
     */
    private static function destinations(mixed $transfer, bool $transfiere): array
    {
        $base = 'profile.content.transfer';
        if (! is_array($transfer) || array_is_list($transfer) && $transfer !== []) {
            throw self::refusal('profile_field_invalid', $base);
        }
        self::closedKeys($transfer, self::TRANSFER_FIELDS, $base);
        $lista = $transfer['destinations'];
        if (! is_array($lista) || ! array_is_list($lista)) {
            throw self::refusal('profile_field_invalid', "{$base}.destinations");
        }
        if (count($lista) > self::MAX_DESTINATIONS || ($transfiere && $lista === []) || (! $transfiere && $lista !== [])) {
            throw self::refusal('transfer_destinations_invalid', "{$base}.destinations");
        }

        $alias = [];
        $huellas = [];
        $destinos = [];
        foreach ($lista as $i => $d) {
            $ruta = "{$base}.destinations.{$i}";
            if (! is_array($d) || array_is_list($d)) {
                throw self::refusal('profile_field_invalid', $ruta);
            }
            self::closedKeys($d, self::DESTINATION_FIELDS, $ruta);
            $nombre = self::text($d['alias'], "{$ruta}.alias", StudioCanaryPhone::ALIAS_MAX);
            if (StudioCanaryPhone::aliasError($nombre) !== null) {
                throw self::refusal('profile_field_invalid', "{$ruta}.alias");
            }
            if (! is_string($d['phoneHash']) || preg_match(self::HASH_PATTERN, $d['phoneHash']) !== 1) {
                throw self::refusal('profile_field_invalid', "{$ruta}.phoneHash");
            }
            $clave = mb_strtolower($nombre, 'UTF-8');
            if (isset($alias[$clave]) || isset($huellas[$d['phoneHash']])) {
                throw self::refusal('transfer_destinations_invalid', "{$base}.destinations");
            }
            $alias[$clave] = true;
            $huellas[$d['phoneHash']] = true;
            $destinos[] = ['alias' => $nombre, 'phoneHash' => $d['phoneHash']];
        }
        usort($destinos, static fn (array $a, array $b): int => strcmp($a['alias'], $b['alias']));

        return $destinos;
    }

    /**
     * @return array{retentionDays:int,storeAudio:bool,recording:bool}
     */
    private static function privacy(mixed $privacy): array
    {
        $base = 'profile.content.privacy';
        if (! is_array($privacy) || array_is_list($privacy)) {
            throw self::refusal('profile_field_invalid', $base);
        }
        self::closedKeys($privacy, self::PRIVACY_FIELDS, $base);

        if (! is_bool($privacy['recording'])) {
            throw self::refusal('profile_field_invalid', "{$base}.recording");
        }
        if ($privacy['recording'] && ! self::RECORDING_ALLOWED) {
            throw self::refusal('recording_not_allowed', "{$base}.recording");
        }
        if (! is_bool($privacy['storeAudio'])) {
            throw self::refusal('profile_field_invalid', "{$base}.storeAudio");
        }
        if ($privacy['storeAudio'] && ! self::STORE_AUDIO_ALLOWED) {
            throw self::refusal('audio_retention_not_allowed', "{$base}.storeAudio");
        }
        $dias = $privacy['retentionDays'];
        if (! is_int($dias)) {
            throw self::refusal('profile_field_invalid', "{$base}.retentionDays");
        }
        if ($dias === self::UNLIMITED_RETENTION || $dias > self::MAX_RETENTION_DAYS) {
            throw self::refusal('retention_exceeds_limit', "{$base}.retentionDays");
        }
        if ($dias < 1) {
            throw self::refusal('profile_field_invalid', "{$base}.retentionDays");
        }

        return ['retentionDays' => $dias, 'storeAudio' => $privacy['storeAudio'], 'recording' => $privacy['recording']];
    }

    /**
     * Exactamente esas claves: una de más es `profile_field_unknown` y una que
     * falta, `required_field_empty`, con su ruta.
     *
     * @param  array<array-key,mixed>  $value
     * @param  list<string>  $keys
     */
    private static function closedKeys(array $value, array $keys, string $base): void
    {
        foreach (array_keys($value) as $clave) {
            if (! in_array($clave, $keys, true)) {
                // La ruta de un campo inventado no repite un texto largo: se recorta.
                throw self::refusal('profile_field_unknown', $base.'.'.mb_substr((string) $clave, 0, 40, 'UTF-8'));
            }
        }
        foreach ($keys as $clave) {
            if (! array_key_exists($clave, $value)) {
                // Sin el aviso no falta «un campo»: falta el aviso de P-9.
                throw self::refusal($base === 'profile.content' && $clave === 'aiNotice' ? 'notice_missing' : 'required_field_empty', "{$base}.{$clave}");
            }
        }
    }

    // ── Las reglas S-NNN ────────────────────────────────────────────────────

    /**
     * Las frases que prohíben (`forbid text contains`) las reglas S-NNN vivas
     * de la versión cuyo «si» se da en ella, sin repetir y ordenadas. El «si»
     * se le pregunta a la comprobación del laboratorio (`lab-regla-<código>`
     * de {@see StudioLabChecks}), que es LA semántica de las frases del Studio
     * desde G: con una copia de la regla cuya única frase es una sonda, la
     * prueba sale si y solo si su «si» se cumple. Una copia aquí podría
     * derivar. `require text` no se aplica, como en el laboratorio.
     *
     * @param  array<string,mixed>  $content
     * @return list<string>
     */
    private static function forbiddenPhrases(array $content): array
    {
        $configs = is_array($content['configs'] ?? null) ? $content['configs'] : [];
        $aristas = is_array($content['definition']['edgeIds'] ?? null) ? $content['definition']['edgeIds'] : [];

        $frases = [];
        foreach (is_array($content['rules'] ?? null) ? $content['rules'] : [] as $regla) {
            if (! is_array($regla) || ! is_string($regla['code'] ?? null) || ! str_starts_with($regla['code'], 'S-')
                || ($regla['status'] ?? null) === StudioRuleCoherence::STATUS_ARCHIVED || ! is_array($regla['conditions'] ?? null)) {
                continue;
            }
            $propias = [];
            foreach (is_array($regla['conditions']['then'] ?? null) ? $regla['conditions']['then'] : [] as $a) {
                if (! is_array($a) || ($a['kind'] ?? null) !== 'forbid' || ($a['target'] ?? null) !== 'text' || ($a['op'] ?? null) !== 'contains') {
                    continue;
                }
                foreach (is_array($a['phrases'] ?? null) ? $a['phrases'] : [] as $f) {
                    if (is_string($f)) {
                        $propias[] = $f;
                    }
                }
            }
            if ($propias === []) {
                continue;
            }

            $sonda = $regla;
            $sonda['conditions'] = [
                'when' => $regla['conditions']['when'] ?? [],
                'then' => [['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => [self::WHEN_PROBE]]],
            ];
            $hallazgos = StudioLabChecks::check(self::WHEN_PROBE, ['configs' => $configs, 'edgeIds' => $aristas, 'rules' => [$sonda]]);
            if (! in_array(StudioLabChecks::ID_RULE_PREFIX.$regla['code'], array_column($hallazgos, 'id'), true)) {
                continue;
            }

            foreach ($propias as $f) {
                $f = StudioLabChecks::canonical($f);
                if ($f === '' || StudioVoiceTemplate::textViolation($f, allowDiscountWords: true) !== null) {
                    throw self::refusal('rule_phrase_invalid', 'version.content.rules.'.$regla['code']);
                }
                $frases[$f] = true;
            }
        }

        $lista = array_map('strval', array_keys($frases));
        sort($lista, SORT_STRING);

        return $lista;
    }

    // ── Comprobaciones de forma ─────────────────────────────────────────────

    /**
     * La envoltura de la versión o del perfil, con sus tipos. Sin forma es un
     * error de quien llama (lo construye el servidor desde la base).
     *
     * @param  array<string,mixed>  $e
     * @return array{uuid:string,status:string,contentHash:string,content:array<string,mixed>,createdBy:int,approvedBy:int|null}
     */
    private static function envelope(array $e, string $que): array
    {
        if (! VoiceIds::isUuid($e['uuid'] ?? null) || ! is_string($e['status'] ?? null)
            || ! is_string($e['contentHash'] ?? null) || preg_match(self::HASH_PATTERN, $e['contentHash']) !== 1
            || ! is_array($e['content'] ?? null) || ! is_int($e['createdBy'] ?? null) || ! array_key_exists('approvedBy', $e)
            || ($e['approvedBy'] !== null && ! is_int($e['approvedBy']))) {
            throw new InvalidArgumentException("La {$que} que llega al compilador no tiene la forma esperada.");
        }

        return [
            'uuid' => $e['uuid'],
            'status' => $e['status'],
            'contentHash' => $e['contentHash'],
            'content' => $e['content'],
            'createdBy' => $e['createdBy'],
            'approvedBy' => $e['approvedBy'],
        ];
    }

    /**
     * Lo que devolvió la plantilla: el aviso primero, y como únicas `{{…}}`
     * las variables declaradas (nunca una `system__…`: el teléfono de quien
     * llama es una de ellas), sin llaves sueltas, invisibles ni datos de
     * contacto. Un fallo aquí es un error de la plantilla, no del perfil.
     *
     * @param  array{firstMessage:string,systemPrompt:string}  $textos
     */
    private static function assertRenderedTexts(array $textos): void
    {
        if (StudioVoiceTemplate::AI_NOTICE === '' || $textos['firstMessage'] !== StudioVoiceTemplate::AI_NOTICE) {
            throw new LogicException('La plantilla de voz no empieza por el aviso de IA.');
        }
        foreach ($textos as $texto) {
            preg_match_all('/\{\{([^{}]*)\}\}/', $texto, $m);
            foreach ($m[1] as $nombre) {
                if (! array_key_exists($nombre, StudioVoiceTemplate::DYNAMIC_VARIABLES)) {
                    throw new LogicException('La plantilla de voz usa una variable no declarada.');
                }
            }
            $sinVariables = (string) preg_replace('/\{\{[a-z_]+\}\}/', '', $texto);
            if (str_contains($sinVariables, '{') || str_contains($sinVariables, '}')) {
                throw new LogicException('La plantilla de voz tiene llaves sueltas.');
            }
            if (! InvisibleCharacters::isPlain($texto, multiline: true) || StudioLabPersonalData::violation($texto) !== null) {
                throw new LogicException('La plantilla de voz produjo un texto con invisibles o datos de contacto.');
            }
        }
    }

    /** @param  array<array-key,mixed>  $values */
    private static function allStrings(array $values): bool
    {
        foreach ($values as $v) {
            if (! is_string($v)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<mixed>  $destinos */
    private static function sortableDestinations(array $destinos): bool
    {
        foreach ($destinos as $d) {
            if (! is_array($d) || ! is_string($d['alias'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
