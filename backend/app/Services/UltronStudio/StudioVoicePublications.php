<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use App\Services\Voice\Dto\VoiceIds;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * LA CADENA DE VOZ (contrato H8, D8-8, D8-9, E1-4, E2-7 y E3-2): el mensaje que
 * se firma, su verificación, lo vigente de cada parte y lo que se enseña de
 * ella. Solo la escribe {@see StudioVoicePublishing}, dentro de su transacción,
 * por {@see insertEntry()}.
 *
 * EL MENSAJE (E1-4 y E2-7) es el JSON CANÓNICO (claves ordenadas por bytes, sin
 * espacios, UTF-8 sin escapar: {@see VoiceCanonicalJson}, los mismos bytes que
 * `StudioContentHasher`) de exactamente diez claves:
 *
 *     {actor_id, agent_config_hash, prev_hash, profile_uuid, provider_agent_id,
 *      published_at, scope, seq, spec_hash, version_uuid}
 *
 *  - `seq`: la cabeza + 1 (el disparador `usvpub_chain_next` de DB8 lo exige);
 *  - `prev_hash`: el sha256 del MENSAJE de la entrada anterior (su
 *    `effective_hash`), o 64 ceros en la primera; nunca su firma (DF-V1);
 *  - `scope`: la parte (`canary` o `rest`) que fija la entrada (E3-2: una
 *    entrada por parte y por agente). Una entrada que APAGA una parte firma
 *    `scope = off` con las cinco claves del agente a null (DF-V3, D8-20 nivel
 *    2); la parte apagada es la columna `scope` de su fila, que es de solo
 *    inserción (el lector cruza las dos: {@see verifyRow()});
 *  - `agent_config_hash`: la huella de la carga enviada al proveedor
 *    (`payload_hash` del agente; el fixture la llama así), `spec_hash` la de la
 *    especificación neutra y `provider_agent_id` el agente inmutable (D8-7);
 *  - `actor_id`: quien publica; `published_at`: UTC al segundo,
 *    `Y-m-d\TH:i:s\Z` (DF-V2).
 *
 * LA FIRMA es hex(HMAC-SHA256(k, mensaje)), sin el prefijo de contrato del H5
 * (E2-7), con `k` derivada de la `APP_KEY` con el contexto
 * `ultron-studio-voice-chain` (una firma de un dominio no vale en el otro). Su
 * ÚNICA implementación es la del lector de la telefonía,
 * `VoicePublishedConfig::signatureFor()` (D8-8): el Studio la recibe como
 * `Closure` ({@see signer()}), igual que el H5 recibe la del motor. Si ese
 * lector no está (o no hay `APP_KEY`), no se firma nada: falla cerrado.
 *
 * LA VERIFICACIÓN ({@see verifyMessage()}) sigue el orden del fixture (DF-V6):
 * forma (`malformed`) → bytes canónicos (`not_canonical`, DF-V4) → forma de la
 * firma (`signature_malformed`, DF-V5) → firma (`signature_mismatch`) → `seq` =
 * anterior + 1 (`seq_gap`) → `prev_hash` = huella anterior (`chain_broken`). El
 * primer fallo deniega. NUNCA LANZA. Los 27 casos de `voice-chain-vectors.json`
 * la fijan byte a byte (`StudioVoicePublicationsParityTest`).
 *
 * NO RETROACTIVA (D8-9): una llamada usa el agente con el que empezó (el sello
 * se toma al empezar y el agente no cambia nunca). Revertir es insertar una
 * entrada que apunta al agente anterior de la parte, que sigue existiendo.
 */
final class StudioVoicePublications
{
    /** El contexto de la subclave de la firma (E1-4). Documental: firma `VoicePublishedConfig`. */
    public const CHAIN_LABEL = 'ultron-studio-voice-chain';

    /** `prev_hash` de la primera entrada. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Las diez claves del mensaje (E1-4), en orden de bytes. */
    public const MESSAGE_KEYS = ['actor_id', 'agent_config_hash', 'prev_hash', 'profile_uuid', 'provider_agent_id', 'published_at', 'scope', 'seq', 'spec_hash', 'version_uuid'];

    /** Las del agente: null en una entrada que apaga (DF-V3), todas presentes en las demás. */
    public const AGENT_KEYS = ['agent_config_hash', 'profile_uuid', 'provider_agent_id', 'spec_hash', 'version_uuid'];

    /** `scope` del mensaje de una entrada que apaga una parte (DF-V3). */
    public const SCOPE_OFF = 'off';

    /** Los `scope` del mensaje (el conjunto cerrado del fixture). */
    public const MESSAGE_SCOPES = [StudioVoicePublication::SCOPE_CANARY, StudioVoicePublication::SCOPE_REST, self::SCOPE_OFF];

    /** El tope de `effective_json` (`usvpub_size_chk`). */
    public const MAX_MESSAGE = 16384;

    /** DF-V2: UTC al segundo. */
    public const PUBLISHED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    public const PER_PAGE_MAX = 50;

    // ── Resultados de la verificación (los del fixture, más el cruce con la fila) ──

    public const VERIFY_OK = 'ok';

    public const VERIFY_MALFORMED = 'malformed';

    public const VERIFY_NOT_CANONICAL = 'not_canonical';

    public const VERIFY_SIGNATURE_MALFORMED = 'signature_malformed';

    public const VERIFY_SIGNATURE_MISMATCH = 'signature_mismatch';

    public const VERIFY_SEQ_GAP = 'seq_gap';

    public const VERIFY_CHAIN_BROKEN = 'chain_broken';

    /** Solo de una fila guardada: lo firmado no es lo que dicen sus columnas o su agente. */
    public const VERIFY_ROW_MISMATCH = 'row_mismatch';

    /** Los del fixture (`closedSets.verifyResults`), en su orden. */
    public const VERIFY_RESULTS = [
        self::VERIFY_OK, self::VERIFY_MALFORMED, self::VERIFY_NOT_CANONICAL, self::VERIFY_SIGNATURE_MALFORMED,
        self::VERIFY_SIGNATURE_MISMATCH, self::VERIFY_SEQ_GAP, self::VERIFY_CHAIN_BROKEN,
    ];

    private const HEX64 = '/^[0-9a-f]{64}$/D';

    private const PUBLISHED_AT_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D';

    /** Lo que se lee de una entrada (con su texto firmado acotado como lo lee la base: una fila enorme no agota la memoria). */
    private const COLUMNS = [
        'id', 'uuid', 'seq', 'client_request_id', 'action', 'scope', 'previous_publication_id', 'prev_hash', 'restores_publication_id',
        'agent_id', 'effective_hash', 'signature', 'lab_gate', 'evidence', 'reason', 'actor_admin_id', 'actor_name', 'actor_role', 'created_at',
    ];

    // ── El mensaje ──────────────────────────────────────────────────────────

    /**
     * Los bytes del mensaje de una entrada (E1-4): exige su forma (la misma que
     * comprueba {@see verifyMessage()}) y devuelve su JSON canónico.
     *
     * @param  array<string, mixed>  $entry  las diez claves
     *
     * @throws InvalidArgumentException si la entrada no tiene la forma de la cadena
     */
    public static function message(array $entry): string
    {
        if (! self::wellFormed($entry)) {
            throw new InvalidArgumentException('La entrada de la cadena de voz no tiene la forma del mensaje firmado (E1-4).');
        }
        $bytes = VoiceCanonicalJson::encode($entry);
        if (mb_strlen($bytes, 'UTF-8') > self::MAX_MESSAGE) {
            throw new InvalidArgumentException('La entrada de la cadena de voz pasa del tope del mensaje.');
        }

        return $bytes;
    }

    /** La huella de un mensaje: sha256 de sus bytes (el `effective_hash`, y el `prev_hash` de la siguiente). */
    public static function entryHash(string $message): string
    {
        return hash('sha256', $message);
    }

    /** `published_at` de un instante (DF-V2). */
    public static function publishedAt(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format(self::PUBLISHED_AT_FORMAT);
    }

    /**
     * La firma de la cadena de voz: la ÚNICA implementación es la del lector
     * de la telefonía (D8-8), que el Studio recibe como `Closure`. Sin
     * `APP_KEY` lanza al firmar (no firma con una clave vacía).
     *
     * @return Closure(string):string
     */
    public static function signer(): Closure
    {
        return VoicePublishedConfig::signatureFor(...);
    }

    /**
     * Verifica un mensaje guardado (DF-V6). `$previous` es la entrada anterior
     * de la cadena (`{seq, entryHash}`) o null si no la hay. NUNCA LANZA: un
     * fallo al firmar es `signature_mismatch` (falla cerrado).
     *
     * @param  array{seq:int,entryHash:string}|null  $previous
     * @param  Closure(string):string  $signer
     * @return string uno de {@see VERIFY_RESULTS}
     */
    public static function verifyMessage(string $stored, string $signature, ?array $previous, Closure $signer): string
    {
        $mensaje = self::decode($stored);
        if ($mensaje === null || ! self::wellFormed($mensaje)) {
            return self::VERIFY_MALFORMED;
        }
        try {
            $canonico = VoiceCanonicalJson::encode($mensaje);
        } catch (InvalidArgumentException|JsonException) {
            return self::VERIFY_MALFORMED;
        }
        if ($canonico !== $stored) {
            return self::VERIFY_NOT_CANONICAL;
        }
        if (preg_match(self::HEX64, $signature) !== 1) {
            return self::VERIFY_SIGNATURE_MALFORMED;
        }
        try {
            $esperada = $signer($stored);
        } catch (Throwable $e) {
            // Tragado a propósito: la verificación nunca lanza y, sin poder
            // firmar, no verifica (falla cerrado). Al log, solo la clase.
            Log::warning('ultron_studio.voice_chain_signer_unavailable', ['error_class' => class_basename($e)]);

            return self::VERIFY_SIGNATURE_MISMATCH;
        }
        if (! is_string($esperada) || ! hash_equals($esperada, $signature)) {
            return self::VERIFY_SIGNATURE_MISMATCH;
        }

        $seqEsperado = $previous === null ? 1 : $previous['seq'] + 1;
        if ($mensaje['seq'] !== $seqEsperado) {
            return self::VERIFY_SEQ_GAP;
        }
        $prevEsperado = $previous === null ? self::GENESIS_HASH : $previous['entryHash'];
        if (! hash_equals($prevEsperado, $mensaje['prev_hash'])) {
            return self::VERIFY_CHAIN_BROKEN;
        }

        return self::VERIFY_OK;
    }

    /**
     * El mensaje decodificado en estricto: un objeto JSON plano, con el tope
     * de bytes. null si no lo es.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $stored): ?array
    {
        if ($stored === '' || mb_strlen($stored, 'UTF-8') > self::MAX_MESSAGE || preg_match('//u', $stored) !== 1) {
            return null;
        }
        try {
            // Profundidad 2: un objeto de escalares; cualquier anidamiento es malformado.
            $valor = json_decode($stored, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($valor) && $valor !== [] && ! array_is_list($valor) ? $valor : null;
    }

    /**
     * ¿Tiene la forma del mensaje? Exactamente las diez claves y sus tipos;
     * `off` con las cinco del agente a null, `canary`/`rest` con todas.
     *
     * @param  array<array-key, mixed>  $m
     */
    public static function wellFormed(array $m): bool
    {
        $claves = array_map('strval', array_keys($m));
        sort($claves, SORT_STRING);
        if ($claves !== self::MESSAGE_KEYS) {
            return false;
        }
        if (! is_int($m['seq']) || $m['seq'] < 1 || ! is_int($m['actor_id']) || $m['actor_id'] < 1) {
            return false;
        }
        if (! is_string($m['prev_hash']) || preg_match(self::HEX64, $m['prev_hash']) !== 1) {
            return false;
        }
        if (! is_string($m['published_at']) || ! self::validPublishedAt($m['published_at'])) {
            return false;
        }
        if (! is_string($m['scope']) || ! in_array($m['scope'], self::MESSAGE_SCOPES, true)) {
            return false;
        }
        if ($m['scope'] === self::SCOPE_OFF) {
            foreach (self::AGENT_KEYS as $clave) {
                if ($m[$clave] !== null) {
                    return false;
                }
            }

            return true;
        }

        return VoiceIds::isUuid($m['version_uuid'])
            && VoiceIds::isUuid($m['profile_uuid'])
            && is_string($m['spec_hash']) && preg_match(self::HEX64, $m['spec_hash']) === 1
            && is_string($m['agent_config_hash']) && preg_match(self::HEX64, $m['agent_config_hash']) === 1
            && VoiceIds::isProviderId($m['provider_agent_id']);
    }

    // ── Las filas ───────────────────────────────────────────────────────────

    /** La cabeza: la entrada de mayor `seq` (índice único). */
    public static function head(): ?StudioVoicePublication
    {
        return self::withJson(StudioVoicePublication::query())->orderByDesc('seq')->first();
    }

    /** Lo vigente de una parte: su entrada de mayor `seq` (`usvpub_scope_idx`). */
    public static function current(string $part): ?StudioVoicePublication
    {
        return self::withJson(StudioVoicePublication::query())
            ->with(['agent.version:id,uuid,label', 'agent.profile:id,uuid,label,revision'])
            ->where('scope', $part)
            ->orderByDesc('seq')
            ->first();
    }

    /** La entrada inmediatamente anterior a `$seq` (la que encadena), o null. */
    public static function predecessor(int $seq): ?StudioVoicePublication
    {
        return StudioVoicePublication::query()->where('seq', $seq - 1)->first(['id', 'seq', 'effective_hash']);
    }

    /**
     * Verifica una fila guardada: su mensaje (DF-V6, con la entrada anterior de
     * la base) y que lo firmado es lo que dicen sus columnas y su agente (una
     * fila copiada de otra o con el agente cambiado no verifica). Nunca lanza.
     *
     * @param  Closure(string):string|null  $signer  null = {@see signer()}
     */
    public static function verifyRow(StudioVoicePublication $fila, ?Closure $signer = null): string
    {
        $anterior = (int) $fila->seq > 1 ? self::predecessor((int) $fila->seq) : null;

        return self::verifyRowWith($fila, $signer, $anterior === null ? null : (string) $anterior->effective_hash);
    }

    /**
     * {@see verifyRow()} con la huella de la entrada anterior ya leída (null si
     * no la hay): el historial las lee todas en una consulta.
     *
     * @param  Closure(string):string|null  $signer
     */
    public static function verifyRowWith(StudioVoicePublication $fila, ?Closure $signer, ?string $previousHash): string
    {
        $firma = $signer ?? self::signer();
        $json = $fila->effective_json;
        if (! is_string($json) || ! hash_equals(self::entryHash($json), (string) $fila->effective_hash)) {
            return self::VERIFY_ROW_MISMATCH;
        }
        $seq = (int) $fila->seq;
        if ($seq > 1 && $previousHash === null) {
            return self::VERIFY_SEQ_GAP;
        }
        $previo = $seq > 1 ? ['seq' => $seq - 1, 'entryHash' => (string) $previousHash] : null;
        $resultado = self::verifyMessage($json, (string) $fila->signature, $previo, $firma);
        if ($resultado !== self::VERIFY_OK) {
            return $resultado;
        }

        return self::rowMatches($fila, (array) self::decode($json)) ? self::VERIFY_OK : self::VERIFY_ROW_MISMATCH;
    }

    /**
     * Firma e inserta una entrada, DENTRO de la transacción de quien publica
     * (que ya tomó el cerrojo y comprobó la cabeza). `seq` = cabeza + 1 y
     * `prev_hash` = su huella. Una carrera perdida es el UNIQUE de `seq`
     * (23505), que quien publica traduce a 409 `publication_moved` fuera de la
     * transacción.
     *
     * @param  array<string, mixed>  $labGate
     * @param  array<string, mixed>|null  $evidence
     * @param  Closure(string):string  $signer
     */
    public static function insertEntry(
        string $action,
        string $part,
        ?StudioVoiceAgent $agent,
        ?StudioVoicePublication $head,
        DateTimeInterface $at,
        StudioAccess $access,
        string $clientRequestId,
        array $labGate,
        ?array $evidence,
        ?string $reason,
        ?int $restores,
        Closure $signer,
    ): StudioVoicePublication {
        $seq = ($head !== null ? (int) $head->seq : 0) + 1;
        $prev = $head !== null ? (string) $head->effective_hash : self::GENESIS_HASH;
        $yo = $access->snapshot();
        $agent?->loadMissing(['version:id,uuid', 'profile:id,uuid']);

        $mensaje = self::message([
            'seq' => $seq,
            'prev_hash' => $prev,
            'version_uuid' => $agent?->version?->uuid,
            'profile_uuid' => $agent?->profile?->uuid,
            'spec_hash' => $agent?->spec_hash,
            'agent_config_hash' => $agent?->payload_hash,
            'provider_agent_id' => $agent?->provider_agent_id,
            'scope' => $agent === null ? self::SCOPE_OFF : $part,
            'actor_id' => $yo['id'],
            'published_at' => self::publishedAt($at),
        ]);
        $firma = $signer($mensaje);
        if (! is_string($firma) || preg_match(self::HEX64, $firma) !== 1) {
            throw new InvalidArgumentException('La firma de la cadena de voz no tiene la forma esperada.');
        }

        return StudioVoicePublication::query()->forceCreate([
            'uuid' => strtolower((string) Str::uuid()),
            'seq' => $seq,
            'client_request_id' => $clientRequestId,
            'action' => $action,
            'scope' => $part,
            'previous_publication_id' => $head?->id,
            'prev_hash' => $prev,
            'restores_publication_id' => $restores,
            'agent_id' => $agent?->id,
            'effective_json' => $mensaje,
            'effective_hash' => self::entryHash($mensaje),
            'signature' => $firma,
            'lab_gate' => $labGate,
            'evidence' => $evidence,
            'reason' => $reason,
            'actor_admin_id' => $yo['id'],
            'actor_name' => $yo['name'],
            'actor_role' => $yo['role'],
            'created_at' => $at,
        ]);
    }

    // ── Lo que se enseña ────────────────────────────────────────────────────

    /**
     * El estado de la cadena: la cabeza y lo vigente de cada parte, cada una
     * verificada (`verified`). Una parte apagada lleva su entrada y ningún
     * agente; una parte sin entradas, null.
     *
     * @param  Closure(string):string|null  $signer
     * @return array{head:array<string,mixed>|null,canary:array<string,mixed>|null,rest:array<string,mixed>|null,verified:bool}
     */
    public static function state(?Closure $signer = null): array
    {
        $cabeza = self::head();
        $partes = [];
        $todo = true;
        foreach (StudioVoicePublication::SCOPES as $parte) {
            $fila = self::current($parte);
            if ($fila === null) {
                $partes[$parte] = null;

                continue;
            }
            $resultado = self::verifyRow($fila, $signer);
            $todo = $todo && $resultado === self::VERIFY_OK;
            $partes[$parte] = [
                'entry' => self::entryDto($fila),
                'agent' => $fila->agent === null ? null : self::agentDto($fila->agent),
                'serving' => $fila->agent !== null,
                'verified' => $resultado,
            ];
        }

        return [
            'head' => $cabeza === null ? null : [
                'uuid' => (string) $cabeza->uuid,
                'seq' => (int) $cabeza->seq,
                'action' => (string) $cabeza->action,
                'scope' => (string) $cabeza->scope,
                'at' => $cabeza->created_at?->toIso8601String(),
            ],
            'canary' => $partes[StudioVoicePublication::SCOPE_CANARY],
            'rest' => $partes[StudioVoicePublication::SCOPE_REST],
            'verified' => $todo,
        ];
    }

    /**
     * El historial (`GET voice/publications`), de la más reciente a la más
     * antigua, cada entrada con su `verified`.
     */
    public static function page(int $perPage): LengthAwarePaginator
    {
        $pagina = self::withJson(StudioVoicePublication::query())
            ->with(['agent.version:id,uuid,label', 'agent.profile:id,uuid,label,revision'])
            ->orderByDesc('seq')
            ->paginate($perPage);
        // Las huellas de las anteriores, en UNA consulta (sin N+1).
        $seqs = array_map(static fn (StudioVoicePublication $p): int => (int) $p->seq - 1, $pagina->items());
        $anteriores = $seqs === [] ? [] : StudioVoicePublication::query()->whereIn('seq', $seqs)->pluck('effective_hash', 'seq')->all();

        return $pagina->through(static fn (StudioVoicePublication $p): array => self::entryDto($p) + [
            'agent' => $p->agent === null ? null : self::agentDto($p->agent),
            'verified' => self::verifyRowWith($p, null, isset($anteriores[(int) $p->seq - 1]) ? (string) $anteriores[(int) $p->seq - 1] : null),
        ]);
    }

    /**
     * `VoicePublicationEntryDto`: sin el texto firmado (lo identifica su
     * huella), sin ids internos y sin el id del proveedor en claro.
     *
     * @return array<string, mixed>
     */
    public static function entryDto(StudioVoicePublication $p): array
    {
        $modo = is_array($p->lab_gate) ? ($p->lab_gate['mode'] ?? null) : null;

        return [
            'uuid' => (string) $p->uuid,
            'seq' => (int) $p->seq,
            'action' => (string) $p->action,
            'scope' => (string) $p->scope,
            'effectiveHash' => (string) $p->effective_hash,
            'shortHash' => substr((string) $p->effective_hash, 0, 8),
            'labGate' => $p->action === StudioVoicePublication::ACTION_PUBLISH && is_string($modo) ? ['mode' => $modo] : null,
            'evidence' => $p->action === StudioVoicePublication::ACTION_PROMOTE && is_array($p->evidence) ? $p->evidence : null,
            'actor' => ['name' => (string) $p->actor_name, 'role' => $p->actor_role],
            'reason' => $p->reason,
            'at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * `VoiceAgentDto`: huellas y el id del proveedor ENMASCARADO (sus 4
     * últimos, como el agente de verificación en los hechos de voz). Nunca la
     * carga ni sus destinos.
     *
     * @return array<string, mixed>
     */
    public static function agentDto(StudioVoiceAgent $a): array
    {
        return [
            'uuid' => (string) $a->uuid,
            'purpose' => (string) $a->purpose,
            'status' => (string) $a->status,
            'failureCode' => $a->failure_code,
            'providerAgentRef' => $a->provider_agent_id === null ? null : '…'.mb_substr((string) $a->provider_agent_id, -4),
            'version' => $a->relationLoaded('version') && $a->version !== null ? ['uuid' => (string) $a->version->uuid, 'label' => (string) $a->version->label] : null,
            'profile' => $a->relationLoaded('profile') && $a->profile !== null
                ? ['uuid' => (string) $a->profile->uuid, 'label' => (string) $a->profile->label, 'revision' => (int) $a->profile->revision]
                : null,
            'compilerVersion' => (string) $a->compiler_version,
            'specHash' => (string) $a->spec_hash,
            'shortSpecHash' => substr((string) $a->spec_hash, 0, 8),
            'payloadHash' => (string) $a->payload_hash,
            'projectionHash' => (string) $a->projection_hash,
            'servesCanary' => (bool) $a->serves_canary,
            'servesRest' => (bool) $a->serves_rest,
            'createdBy' => ['name' => (string) $a->created_by_name, 'role' => $a->created_by_role],
            'at' => $a->created_at?->toIso8601String(),
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Las columnas de {@see COLUMNS} y el texto firmado acotado (una fila
     * enorme escrita a mano no agota la memoria; una truncada no casa con su
     * huella).
     *
     * @param  Builder<StudioVoicePublication>  $consulta
     * @return Builder<StudioVoicePublication>
     */
    private static function withJson(Builder $consulta): Builder
    {
        return $consulta->select(self::COLUMNS)->selectRaw('substr(effective_json, 1, '.(self::MAX_MESSAGE + 1).') as effective_json');
    }

    /**
     * ¿Lo firmado es lo que dicen la fila y su agente? La parte apagada es la
     * columna `scope` (el mensaje dice `off`).
     *
     * @param  array<string, mixed>  $m
     */
    private static function rowMatches(StudioVoicePublication $fila, array $m): bool
    {
        if ($m['seq'] !== (int) $fila->seq || ! hash_equals((string) $fila->prev_hash, (string) $m['prev_hash']) || $m['actor_id'] !== (int) $fila->actor_admin_id) {
            return false;
        }
        $apaga = $fila->action === StudioVoicePublication::ACTION_OFF;
        if ($apaga !== ($m['scope'] === self::SCOPE_OFF) || $apaga !== ($fila->agent_id === null)) {
            return false;
        }
        if ($apaga) {
            return true;
        }
        if ($m['scope'] !== $fila->scope) {
            return false;
        }
        // El agente cargado con la fila (historial, estado) o, si no, una lectura.
        $agente = $fila->relationLoaded('agent') ? $fila->agent : StudioVoiceAgent::query()->find($fila->agent_id);
        $agente?->loadMissing(['version:id,uuid', 'profile:id,uuid']);

        return $agente !== null
            && (int) $agente->id === (int) $fila->agent_id
            && $agente->provider_agent_id === $m['provider_agent_id']
            && $agente->spec_hash === $m['spec_hash']
            && $agente->payload_hash === $m['agent_config_hash']
            && $agente->version?->uuid === $m['version_uuid']
            && $agente->profile?->uuid === $m['profile_uuid'];
    }

    private static function validPublishedAt(string $valor): bool
    {
        if (preg_match(self::PUBLISHED_AT_PATTERN, $valor) !== 1) {
            return false;
        }
        $fecha = DateTimeImmutable::createFromFormat('!'.self::PUBLISHED_AT_FORMAT, $valor, new DateTimeZone('UTC'));

        return $fecha !== false && $fecha->format(self::PUBLISHED_AT_FORMAT) === $valor;
    }
}
