<?php

namespace App\Services\Telephony;

use App\Services\Voice\Dto\VoiceCanonicalJson;
use App\Services\Voice\Dto\VoiceIds;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El LECTOR de la cadena de voz y su firma (contrato H8, D8-8, D8-9;
 * Enmiendas E1-4, E2-7 y E3-2; fixture `voice-chain-vectors.json`).
 *
 * LA FIRMA ({@see signatureFor()}, implementación ÚNICA: el Studio la recibe
 * como `Closure` con {@see signer()}, como en el H5):
 *  - el mensaje es el JSON CANÓNICO (claves ordenadas, sin espacios, UTF-8)
 *    de las 10 claves de E1-4, ni una más ni una menos, tal cual (sin el
 *    prefijo de contrato del H5, E2-7);
 *  - `prev_hash` = sha256 del mensaje canónico ANTERIOR (no su firma), o 64
 *    ceros en la génesis;
 *  - firma = hex(HMAC-SHA256(k, mensaje)), con k = HMAC-SHA256(APP_KEY,
 *    `ultron-studio-voice-chain`): una firma del H5 no vale aquí ni al revés.
 *
 * EL LECTOR ({@see read()}): UNA sentencia que trae la entrada vigente de cada
 * parte (E3-2: la de mayor `seq` de `canary` y la de `rest`), la anterior de
 * cada una en la cadena (para el enlace) y el AGENTE de cada una, que es donde
 * viven tres columnas del sello que el mensaje firmado no lleva
 * (`studio_content_hash`, `voice_profile_hash` y `compiler_version`; D8-8 las
 * exige en `voice_calls`). Lo firmado y la fila del agente tienen que cuadrar
 * (agente, `spec_hash` y `agent_config_hash` = `payload_hash`). Verificación
 * en bytes, decodificación estricta y orden de DF-V6. NUNCA LANZA y, a
 * diferencia del motor, UN FALLO DENIEGA: `publication_unreadable`.
 *
 * Solo lee: no escribe la cadena ni el agente.
 */
final class VoicePublishedConfig
{
    public const SUBKEY_LABEL = 'ultron-studio-voice-chain';

    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Las 10 claves de E1-4, en orden de bytes. */
    public const MESSAGE_KEYS = ['actor_id', 'agent_config_hash', 'prev_hash', 'profile_uuid', 'provider_agent_id', 'published_at', 'scope', 'seq', 'spec_hash', 'version_uuid'];

    /** Las claves que una entrada `off` lleva a null y una de parte, todas puestas (DF-V3). */
    public const AGENT_KEYS = ['agent_config_hash', 'profile_uuid', 'provider_agent_id', 'spec_hash', 'version_uuid'];

    public const PART_CANARY = 'canary';

    public const PART_REST = 'rest';

    public const PARTS = [self::PART_CANARY, self::PART_REST];

    public const SCOPE_OFF = 'off';

    public const MESSAGE_SCOPES = [self::PART_CANARY, self::PART_REST, self::SCOPE_OFF];

    public const PROVIDER = 'elevenlabs';

    /** D8-8: el mensaje, como mucho 16 384 caracteres. */
    public const MAX_MESSAGE = 16384;

    public const RESULT_OK = 'ok';

    public const RESULTS = ['ok', 'malformed', 'not_canonical', 'signature_malformed', 'signature_mismatch', 'seq_gap', 'chain_broken'];

    /** Motivos propios del lector (además de los de {@see RESULTS}), sin valores. */
    public const REASON_STORED_HASH = 'stored_hash_mismatch';

    public const REASON_SCOPE = 'scope_mismatch';

    public const REASON_AGENT = 'agent_mismatch';

    public const REASON_READ_FAILED = 'read_failed';

    private const TABLE = 'ultron_studio_voice_publications';

    private const AGENTS = 'ultron_studio_voice_agents';

    private const HEX64 = '/^[0-9a-f]{64}$/D';

    private const PUBLISHED_AT = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D';

    private const COMPILER_VERSION = '/^ultron-voice-compiler\/[1-9][0-9]{0,5}$/D';

    // ── La firma ─────────────────────────────────────────────────────────────

    /** La firma de un mensaje con la `APP_KEY` de la aplicación. */
    public static function signatureFor(string $message): string
    {
        return self::signatureWithKey($message, VoicePhone::appKey());
    }

    /** La firma como `Closure` para el Studio (S8): `fn (string $mensaje): string`. */
    public static function signer(): Closure
    {
        return static fn (string $mensaje): string => self::signatureFor($mensaje);
    }

    public static function signatureWithKey(string $message, #[\SensitiveParameter] string $appKeyRaw): string
    {
        return hash_hmac('sha256', $message, self::subkey($appKeyRaw));
    }

    /** k = HMAC-SHA256(APP_KEY, etiqueta), 32 bytes. */
    public static function subkey(#[\SensitiveParameter] string $appKeyRaw): string
    {
        return hash_hmac('sha256', self::SUBKEY_LABEL, $appKeyRaw, true);
    }

    /** El mensaje canónico de una entrada (las 10 claves de E1-4). */
    public static function message(array $entry): string
    {
        return VoiceCanonicalJson::encode($entry);
    }

    // ── La verificación (pura) ───────────────────────────────────────────────

    /**
     * DF-V6: forma → canónico → forma de la firma → firma → seq → enlace. El
     * primer fallo deniega.
     *
     * @param  array{seq: int, entryHash: string}|null  $previous  la entrada anterior de la cadena, o null en la génesis
     */
    public static function verifyEntry(string $message, string $signature, ?array $previous, #[\SensitiveParameter] string $appKeyRaw): string
    {
        $entrada = self::decode($message);
        if ($entrada === null) {
            return 'malformed';
        }
        if (VoiceCanonicalJson::encode($entrada) !== $message) {
            return 'not_canonical';
        }
        if (preg_match(self::HEX64, $signature) !== 1) {
            return 'signature_malformed';
        }
        if (! hash_equals(self::signatureWithKey($message, $appKeyRaw), $signature)) {
            return 'signature_mismatch';
        }
        if ($entrada['seq'] !== ($previous === null ? 1 : $previous['seq'] + 1)) {
            return 'seq_gap';
        }
        $enlace = $previous === null ? self::GENESIS_PREV_HASH : $previous['entryHash'];
        if (! hash_equals($enlace, $entrada['prev_hash'])) {
            return 'chain_broken';
        }

        return self::RESULT_OK;
    }

    /**
     * La decodificación estricta de un mensaje: exactamente las 10 claves con
     * su tipo y su forma, o null.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $message): ?array
    {
        if ($message === '' || strlen($message) > self::MAX_MESSAGE) {
            return null;
        }
        $e = json_decode($message, true, 4);
        if (! is_array($e) || array_is_list($e)) {
            return null;
        }
        $claves = array_keys($e);
        sort($claves, SORT_STRING);
        if ($claves !== self::MESSAGE_KEYS) {
            return null;
        }
        if (! is_int($e['seq']) || $e['seq'] < 1 || ! is_int($e['actor_id']) || $e['actor_id'] < 1) {
            return null;
        }
        if (! is_string($e['prev_hash']) || preg_match(self::HEX64, $e['prev_hash']) !== 1) {
            return null;
        }
        if (! in_array($e['scope'], self::MESSAGE_SCOPES, true)) {
            return null;
        }
        if (! is_string($e['published_at']) || preg_match(self::PUBLISHED_AT, $e['published_at']) !== 1
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $e['published_at']) === false
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $e['published_at'])->format('Y-m-d\TH:i:s\Z') !== $e['published_at']) {
            return null;
        }
        $apagada = $e['scope'] === self::SCOPE_OFF;
        foreach (self::AGENT_KEYS as $clave) {
            if ($apagada !== ($e[$clave] === null)) {
                return null;
            }
        }
        if (! $apagada) {
            if (! VoiceIds::isUuid($e['version_uuid']) || ! VoiceIds::isUuid($e['profile_uuid']) || ! VoiceIds::isProviderId($e['provider_agent_id'])
                || ! is_string($e['spec_hash']) || preg_match(self::HEX64, $e['spec_hash']) !== 1
                || ! is_string($e['agent_config_hash']) || preg_match(self::HEX64, $e['agent_config_hash']) !== 1) {
                return null;
            }
        }

        return $e;
    }

    // ── El lector (una sentencia; nunca lanza) ──────────────────────────────

    /** @return array{canary: VoicePublishedPart, rest: VoicePublishedPart} */
    public static function read(): array
    {
        try {
            $filas = self::rows();
            $clave = VoicePhone::appKey();
        } catch (Throwable) {
            // Sin la cadena legible (tabla, base o APP_KEY) no hay voz: un fallo deniega (D8-8).
            return [
                self::PART_CANARY => new VoicePublishedPart(self::PART_CANARY, VoicePublishedPart::STATUS_UNREADABLE, reason: self::REASON_READ_FAILED),
                self::PART_REST => new VoicePublishedPart(self::PART_REST, VoicePublishedPart::STATUS_UNREADABLE, reason: self::REASON_READ_FAILED),
            ];
        }

        $porSeq = [];
        foreach ($filas as $fila) {
            $porSeq[(int) $fila->seq] = $fila;
        }
        $out = [];
        foreach (self::PARTS as $parte) {
            $vigente = null;
            foreach ($porSeq as $seq => $fila) {
                if ($fila->scope === $parte && ($vigente === null || $seq > (int) $vigente->seq)) {
                    $vigente = $fila;
                }
            }
            $out[$parte] = $vigente === null
                ? new VoicePublishedPart($parte, VoicePublishedPart::STATUS_NONE)
                : self::part($parte, $vigente, $porSeq[(int) $vigente->seq - 1] ?? null, $clave);
        }

        return $out;
    }

    /** La parte que atiende un alcance de salientes: `canary` (4a) o `rest` (4b, D8-19). */
    public static function partForScope(string $scope): string
    {
        return $scope === OutboundPolicy::SCOPE_CONTACTS ? self::PART_REST : self::PART_CANARY;
    }

    private static function part(string $parte, object $fila, ?object $anterior, string $clave): VoicePublishedPart
    {
        $mensaje = (string) $fila->effective_json;
        $previa = $anterior === null ? null : ['seq' => (int) $anterior->seq, 'entryHash' => hash('sha256', (string) $anterior->effective_json)];
        $resultado = self::verifyEntry($mensaje, (string) $fila->signature, $previa, $clave);
        if ($resultado !== self::RESULT_OK) {
            return new VoicePublishedPart($parte, VoicePublishedPart::STATUS_UNREADABLE, (int) $fila->seq, $resultado);
        }
        if (! hash_equals(hash('sha256', $mensaje), (string) $fila->effective_hash)) {
            return new VoicePublishedPart($parte, VoicePublishedPart::STATUS_UNREADABLE, (int) $fila->seq, self::REASON_STORED_HASH);
        }
        $e = self::decode($mensaje);
        if ($e['seq'] !== (int) $fila->seq || ($e['scope'] !== self::SCOPE_OFF && $e['scope'] !== $parte)) {
            return new VoicePublishedPart($parte, VoicePublishedPart::STATUS_UNREADABLE, (int) $fila->seq, self::REASON_SCOPE);
        }
        if ($e['scope'] === self::SCOPE_OFF || $fila->agent_id === null) {
            return $e['scope'] === self::SCOPE_OFF && $fila->agent_id === null
                ? new VoicePublishedPart($parte, VoicePublishedPart::STATUS_OFF, (int) $fila->seq)
                : new VoicePublishedPart($parte, VoicePublishedPart::STATUS_UNREADABLE, (int) $fila->seq, self::REASON_AGENT);
        }
        $cuadra = is_string($fila->a_provider_agent_id) && hash_equals($fila->a_provider_agent_id, $e['provider_agent_id'])
            && is_string($fila->a_spec_hash) && hash_equals($fila->a_spec_hash, $e['spec_hash'])
            && is_string($fila->a_payload_hash) && hash_equals($fila->a_payload_hash, $e['agent_config_hash'])
            && $fila->a_purpose === 'publication' && $fila->a_status === 'deployed'
            && is_string($fila->a_studio_content_hash) && preg_match(self::HEX64, $fila->a_studio_content_hash) === 1
            && is_string($fila->a_voice_profile_hash) && preg_match(self::HEX64, $fila->a_voice_profile_hash) === 1
            && is_string($fila->a_projection_hash) && preg_match(self::HEX64, $fila->a_projection_hash) === 1
            && is_string($fila->a_compiler_version) && preg_match(self::COMPILER_VERSION, $fila->a_compiler_version) === 1;
        if (! $cuadra) {
            return new VoicePublishedPart($parte, VoicePublishedPart::STATUS_UNREADABLE, (int) $fila->seq, self::REASON_AGENT);
        }

        return new VoicePublishedPart(
            part: $parte,
            status: VoicePublishedPart::STATUS_OK,
            seq: (int) $fila->seq,
            studioVersionUuid: $e['version_uuid'],
            studioContentHash: $fila->a_studio_content_hash,
            voiceProfileUuid: $e['profile_uuid'],
            voiceProfileHash: $fila->a_voice_profile_hash,
            specHash: $e['spec_hash'],
            payloadHash: $e['agent_config_hash'],
            projectionHash: $fila->a_projection_hash,
            compilerVersion: $fila->a_compiler_version,
            providerAgentId: $e['provider_agent_id'],
        );
    }

    /**
     * UNA sentencia: las vigentes de cada parte y la anterior de cada una en
     * la cadena, con su agente.
     *
     * @return list<object>
     */
    private static function rows(): array
    {
        return DB::table(self::TABLE.' as p')
            ->leftJoin(self::AGENTS.' as a', 'a.id', '=', 'p.agent_id')
            ->where(function ($q): void {
                $q->whereIn('p.seq', fn ($s) => $s->from(self::TABLE)->selectRaw('max(seq)')->groupBy('scope'))
                    ->orWhereIn('p.seq', fn ($s) => $s->from(self::TABLE)->selectRaw('max(seq) - 1')->groupBy('scope'));
            })
            ->orderBy('p.seq')
            ->get([
                'p.seq', 'p.scope', 'p.agent_id', 'p.effective_json', 'p.effective_hash', 'p.signature',
                'a.provider_agent_id as a_provider_agent_id', 'a.spec_hash as a_spec_hash', 'a.payload_hash as a_payload_hash',
                'a.projection_hash as a_projection_hash', 'a.studio_content_hash as a_studio_content_hash',
                'a.voice_profile_hash as a_voice_profile_hash', 'a.compiler_version as a_compiler_version',
                'a.purpose as a_purpose', 'a.status as a_status',
            ])
            ->all();
    }
}
