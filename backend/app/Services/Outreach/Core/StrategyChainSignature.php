<?php

namespace App\Services\Outreach\Core;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * La FIRMA de la cadena de publicaciones de estrategias (contrato H9, D9-11.3;
 * fixture `strategy-chain-vectors.json`). PURA y ÚNICA: el Studio (S9) la
 * recibe como `Closure` ({@see signer()}) y el lector de la ejecución
 * (`OutreachPublishedStrategies`, B9-run) la usa para verificar, como en el H5
 * y el H8. La `APP_KEY` la lee quien llama y entra como argumento (el núcleo no
 * lee configuración).
 *
 * EL MENSAJE es el JSON CANÓNICO ({@see CanonicalJson}: claves ordenadas, sin
 * espacios, UTF-8) de exactamente nueve claves:
 *
 *     {action, actedByPerson, approvedByPerson, contract, issuedAt, prevHash,
 *      seq, state {canary, rest, status}, strategy {key, purpose, uuid}}
 *
 *  - `contract` = `ultron-strategy-config/1`; `seq` ≥ 1 (la cabeza global + 1);
 *  - `prevHash` = sha256 del MENSAJE canónico anterior de la cadena (no de su
 *    firma), o 64 ceros en la primera entrada;
 *  - `issuedAt`: UTC al segundo, `Y-m-d\TH:i:s\Z`;
 *  - `state.canary` y `state.rest`: `{contentHash, version}` o null;
 *    `state.status`: `active`, `paused` u `off` (`off` ⇔ las dos partes null);
 *  - personas (DF-V3): el uuid de la identidad; `actedByPerson` nunca es null;
 *    `approvedByPerson` es quien aprobó la versión en `publish` y `promote`, y
 *    null en `pause`, `resume`, `rollback` y `retire` (no hay aprobación);
 *    `publish` exige `canary`.
 *
 * LA FIRMA es hex(HMAC-SHA256(k, mensaje)) con k = HMAC-SHA256(APP_KEY cruda,
 * `ultron-studio-strategy-chain`): distinta de la del H5
 * (`ultron-studio-publication`) y de la voz (`ultron-studio-voice-chain`); una
 * firma no vale en otro dominio.
 *
 * LA VERIFICACIÓN ({@see verifyEntry()}) sigue el orden de la del H8: forma
 * (`malformed`) → bytes canónicos (`not_canonical`) → forma de la firma
 * (`signature_malformed`) → firma (`signature_mismatch`) → `seq` = anterior + 1
 * (`seq_gap`) → `prevHash` = huella anterior (`chain_broken`). El primer fallo
 * deniega. NUNCA LANZA.
 */
final class StrategyChainSignature
{
    public const SUBKEY_LABEL = 'ultron-studio-strategy-chain';

    public const CONTRACT = 'ultron-strategy-config/1';

    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Las nueve claves del mensaje, en orden de bytes. */
    public const MESSAGE_KEYS = ['actedByPerson', 'action', 'approvedByPerson', 'contract', 'issuedAt', 'prevHash', 'seq', 'state', 'strategy'];

    public const ACTIONS = ['publish', 'promote', 'pause', 'resume', 'rollback', 'retire'];

    /** Las que llevan la persona que aprobó la versión (DF-V3); en las demás es null. */
    public const APPROVAL_ACTIONS = ['publish', 'promote'];

    public const STATUSES = ['active', 'paused', 'off'];

    public const MAX_MESSAGE = 16384;

    public const RESULT_OK = 'ok';

    public const RESULTS = ['ok', 'malformed', 'not_canonical', 'signature_malformed', 'signature_mismatch', 'seq_gap', 'chain_broken'];

    private const ISSUED_AT = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D';

    // ── La firma ────────────────────────────────────────────────────────────

    /** k = HMAC-SHA256(APP_KEY cruda, etiqueta), 32 bytes. */
    public static function subkey(#[\SensitiveParameter] string $appKeyRaw): string
    {
        return hash_hmac('sha256', self::SUBKEY_LABEL, $appKeyRaw, true);
    }

    /** hex(HMAC-SHA256(k, mensaje)). */
    public static function sign(string $message, #[\SensitiveParameter] string $appKeyRaw): string
    {
        return hash_hmac('sha256', $message, self::subkey($appKeyRaw));
    }

    /** La firma como `Closure` para el Studio: `fn (string $mensaje): string`. */
    public static function signer(#[\SensitiveParameter] string $appKeyRaw): Closure
    {
        $k = self::subkey($appKeyRaw);

        return static fn (string $mensaje): string => hash_hmac('sha256', $mensaje, $k);
    }

    /**
     * La `APP_KEY` cruda a partir de la escrita (`base64:…` decodificada, como
     * `EncryptionServiceProvider::parseKey`).
     *
     * @throws InvalidArgumentException si está vacía o el base64 no es válido
     */
    public static function appKeyRaw(#[\SensitiveParameter] string $appKey): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $crudo = base64_decode(substr($appKey, 7), true);
            if ($crudo === false || $crudo === '') {
                throw new InvalidArgumentException('La APP_KEY base64 no es válida.');
            }

            return $crudo;
        }
        if ($appKey === '') {
            throw new InvalidArgumentException('Sin APP_KEY no se firma nada.');
        }

        return $appKey;
    }

    /** sha256 del mensaje: el `prevHash` de la entrada siguiente y el `effective_hash` de la fila. */
    public static function entryHash(string $message): string
    {
        return hash('sha256', $message);
    }

    // ── El mensaje ──────────────────────────────────────────────────────────

    /**
     * Los bytes del mensaje de una entrada: exige su forma (la misma que
     * comprueba {@see decode()}) y devuelve su JSON canónico.
     *
     * @param  array<string,mixed>  $entry
     *
     * @throws InvalidArgumentException si la entrada no tiene la forma de la cadena
     */
    public static function message(array $entry): string
    {
        $mensaje = CanonicalJson::encode($entry);
        if (self::decode($mensaje) === null) {
            throw new InvalidArgumentException('La entrada no tiene la forma de la cadena de estrategias.');
        }

        return $mensaje;
    }

    /**
     * La decodificación estricta: exactamente las nueve claves, con su tipo y
     * su forma, o null.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $message): ?array
    {
        if ($message === '' || strlen($message) > self::MAX_MESSAGE) {
            return null;
        }
        try {
            $e = json_decode($message, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Tragado a propósito: un mensaje que no es JSON es `malformed` (lo devuelve quien llama).
            return null;
        }
        if (! is_array($e) || array_is_list($e)) {
            return null;
        }
        $claves = array_map('strval', array_keys($e));
        sort($claves, SORT_STRING);
        if ($claves !== self::MESSAGE_KEYS) {
            return null;
        }
        if (! in_array($e['action'], self::ACTIONS, true) || $e['contract'] !== self::CONTRACT
            || ! is_int($e['seq']) || $e['seq'] < 1
            || ! is_string($e['prevHash']) || preg_match(OutreachCatalog::HEX64_PATTERN, $e['prevHash']) !== 1
            || ! self::issuedAt($e['issuedAt'])) {
            return null;
        }
        if (! self::uuid($e['actedByPerson'])) {
            return null;
        }
        $conAprobacion = in_array($e['action'], self::APPROVAL_ACTIONS, true);
        if ($conAprobacion ? ! self::uuid($e['approvedByPerson']) : $e['approvedByPerson'] !== null) {
            return null;
        }
        if (! self::strategy($e['strategy']) || ! self::state($e['state'], $e['action'])) {
            return null;
        }

        return $e;
    }

    // ── La verificación (pura; nunca lanza) ─────────────────────────────────

    /**
     * @param  array{seq: int, entryHash: string}|null  $previous  la entrada anterior de la cadena (global), o null en la génesis
     */
    public static function verifyEntry(string $message, string $signature, ?array $previous, #[\SensitiveParameter] string $appKeyRaw): string
    {
        $e = self::decode($message);
        if ($e === null) {
            return 'malformed';
        }
        try {
            $canonico = CanonicalJson::encode($e);
        } catch (InvalidArgumentException) {
            // Tragado a propósito: lo que no se puede volver a escribir canónico no lo es.
            return 'not_canonical';
        }
        if ($canonico !== $message) {
            return 'not_canonical';
        }
        if (preg_match(OutreachCatalog::HEX64_PATTERN, $signature) !== 1) {
            return 'signature_malformed';
        }
        if (! hash_equals(self::sign($message, $appKeyRaw), $signature)) {
            return 'signature_mismatch';
        }
        if ($e['seq'] !== ($previous === null ? 1 : $previous['seq'] + 1)) {
            return 'seq_gap';
        }
        if (! hash_equals($previous === null ? self::GENESIS_PREV_HASH : (string) $previous['entryHash'], $e['prevHash'])) {
            return 'chain_broken';
        }

        return self::RESULT_OK;
    }

    /**
     * {@see verifyEntry()} con lo que necesita el lector (DF-V6): el resultado,
     * si deniega y el motivo de N5 (`chain_unreadable`).
     *
     * @param  array{seq: int, entryHash: string}|null  $previous
     * @return array{result: string, denies: bool, n5Reason: string|null}
     */
    public static function verify(string $message, string $signature, ?array $previous, #[\SensitiveParameter] string $appKeyRaw): array
    {
        $r = self::verifyEntry($message, $signature, $previous, $appKeyRaw);

        return ['result' => $r, 'denies' => $r !== self::RESULT_OK, 'n5Reason' => $r === self::RESULT_OK ? null : 'chain_unreadable'];
    }

    /**
     * La cadena ENTERA desde la génesis (lo que hace `outreach:monitor`): el
     * primer eslabón que falla y por qué, o `ok`.
     *
     * @param  list<array{message: string, signature: string}>  $entries  en orden de `seq`
     * @return array{result: string, seq: int|null, verified: int}
     */
    public static function verifyChain(array $entries, #[\SensitiveParameter] string $appKeyRaw): array
    {
        $anterior = null;
        foreach ($entries as $i => $fila) {
            $mensaje = is_array($fila) && is_string($fila['message'] ?? null) ? $fila['message'] : '';
            $firma = is_array($fila) && is_string($fila['signature'] ?? null) ? $fila['signature'] : '';
            $r = self::verifyEntry($mensaje, $firma, $anterior, $appKeyRaw);
            if ($r !== self::RESULT_OK) {
                return ['result' => $r, 'seq' => $i + 1, 'verified' => $i];
            }
            $anterior = ['seq' => $i + 1, 'entryHash' => self::entryHash($mensaje)];
        }

        return ['result' => self::RESULT_OK, 'seq' => null, 'verified' => count($entries)];
    }

    // ── Formas ──────────────────────────────────────────────────────────────

    private static function issuedAt(mixed $v): bool
    {
        return is_string($v) && preg_match(self::ISSUED_AT, $v) === 1 && RelativeTime::isInstant($v);
    }

    private static function strategy(mixed $s): bool
    {
        if (! is_array($s) || array_is_list($s)) {
            return false;
        }
        $claves = array_map('strval', array_keys($s));
        sort($claves, SORT_STRING);

        return $claves === ['key', 'purpose', 'uuid']
            && is_string($s['key']) && preg_match(OutreachCatalog::STRATEGY_KEY_PATTERN, $s['key']) === 1
            && in_array($s['purpose'], OutreachCatalog::PURPOSES, true)
            && is_string($s['uuid']) && preg_match(OutreachCatalog::UUID_PATTERN, $s['uuid']) === 1;
    }

    private static function state(mixed $s, string $accion): bool
    {
        if (! is_array($s) || array_is_list($s)) {
            return false;
        }
        $claves = array_map('strval', array_keys($s));
        sort($claves, SORT_STRING);
        if ($claves !== ['canary', 'rest', 'status'] || ! in_array($s['status'], self::STATUSES, true)) {
            return false;
        }
        foreach (['canary', 'rest'] as $parte) {
            if ($s[$parte] !== null && ! self::part($s[$parte])) {
                return false;
            }
        }
        $vacia = $s['canary'] === null && $s['rest'] === null;
        if (($s['status'] === 'off') !== $vacia) {
            return false;
        }

        return $accion !== 'publish' || $s['canary'] !== null;
    }

    private static function uuid(mixed $v): bool
    {
        return is_string($v) && preg_match(OutreachCatalog::UUID_PATTERN, $v) === 1;
    }

    private static function part(mixed $p): bool
    {
        if (! is_array($p) || array_is_list($p)) {
            return false;
        }
        $claves = array_map('strval', array_keys($p));
        sort($claves, SORT_STRING);

        return $claves === ['contentHash', 'version']
            && is_string($p['contentHash']) && preg_match(OutreachCatalog::HEX64_PATTERN, $p['contentHash']) === 1
            && is_string($p['version']) && preg_match(OutreachCatalog::UUID_PATTERN, $p['version']) === 1;
    }
}
