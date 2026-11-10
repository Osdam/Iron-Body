<?php

namespace App\Services\Outreach\Core;

use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * El CONTENIDO de una versión de estrategia, `strategy-content/1` (contrato
 * H9, D9-6; fixture `strategy-content-cases.json`): el LECTOR ESTRICTO de su
 * JSON. PURO. Lo usa el validador del Studio (S9) al congelar una versión y el
 * planificador (B9-run) al leerla.
 *
 * Exactamente estas claves (DF-E1): `format` (= `strategy-content/1`), `goal`,
 * `purpose`, `priority`, `trigger`, `audience`, `exclusions?`, `success`,
 * `sequence`, `limits`, `schedule?`, `canary` y `studio`; `exclusions` y
 * `schedule` pueden faltar o ser null (no hay). Cualquier otra, `unexpected_key`.
 *
 *  - `trigger` (DF-E3): un evento del catálogo disparable y disponible
 *    (`trigger_not_allowed`) cuya finalidad admita la de la estrategia
 *    (`purpose_mismatch`); sus parámetros con el esquema del catálogo
 *    (`constraint_violated` si min > max);
 *  - `audience`, `exclusions`, `success` y las `guard`: la gramática de
 *    condiciones bajo su clave (`content.audience…`); un hecho que no admite
 *    la finalidad (cartera fuera de cobranza) es `fact_purpose_not_allowed` (DF-E4);
 *  - cada paso (DF-E5, DF-E6): `id` único, `delay` ≤ 60 días, 1–3 canales sin
 *    repetir, EXACTAMENTE una plantilla `{uuid, hash}` por canal, `on` con
 *    transiciones permitidas para cada resultado y `waitFor` (≤ 14 días) si
 *    alguna lleva a `wait`;
 *  - `limits` (DF-E2): las cinco, obligatorias y en rango, más
 *    `commercialBlockOverdueDays` (0–365), opcional y solo en `commercial`
 *    (L9-20; en las demás finalidades, `unexpected_key`);
 *  - `schedule` (DF-E7): días ISO 1–6 sin repetir (ordenados en la forma
 *    canónica) y 1–4 franjas en minutos; una franja que no toca la ventana
 *    legal es `schedule_outside_legal_window` (bloqueante de D9-12);
 *  - `canary` y `studio` (DF-E8).
 *
 * Los errores son pares `{key, code}` con TODAS las claves a la vez (raíz
 * `content`). La huella (`content_hash`) es el sha256 del JSON canónico, que
 * cabe en 32 KB.
 */
final class StrategyContent
{
    public const FORMAT = 'strategy-content/1';

    public const ROOT = 'content';

    public const MAX_BYTES = 32768;

    public const MAX_STEPS = 6;

    public const MAX_CHANNELS = 3;

    public const MAX_WAIT_DAYS = 14;

    public const MAX_DELAY_DAYS = 60;

    public const MAX_SCHEDULE_WINDOWS = 4;

    /** DF-E2: los cinco límites, obligatorios, con su rango. */
    public const LIMITS = [
        'contactsPerDay' => [1, 500],
        'cooldownDays' => [0, 365],
        'enrollmentDays' => [1, 60],
        'maxTouches' => [1, 6],
        'newEnrollmentsPerDay' => [1, 500],
    ];

    /** L9-20: opcional y SOLO en `commercial`: endurece el umbral del bloqueo por cartera vencida (el efectivo es el menor). */
    public const LIMITS_OPTIONAL = ['commercialBlockOverdueDays' => [0, 365]];

    public const REQUIRED = ['audience', 'canary', 'format', 'goal', 'limits', 'priority', 'purpose', 'sequence', 'studio', 'success', 'trigger'];

    public const OPTIONAL = ['exclusions', 'schedule'];

    public const STEP_REQUIRED = ['channels', 'delay', 'id', 'templates'];

    public const STEP_OPTIONAL = ['guard', 'on', 'waitFor'];

    /** Una versión guardada que el catálogo vigente ya no admite: se niega, no se reinterpreta (D9-3). */
    public const CATALOG_INCOMPATIBLE = 'catalog_incompatible';

    private const STEP_ID = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

    private const STUDIO_CATALOG_VERSION = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[0-9]{1,3}$/D';

    private const SAFE_KEY = '/^[A-Za-z0-9_:-]{1,64}$/D';

    /** @var list<array{key: string, code: string}> */
    private array $errors = [];

    /** @param  array<string, list<int|string>>  $dynamic */
    private function __construct(private readonly array $dynamic) {}

    /**
     * Lee y valida.
     *
     * @param  array<string, list<int|string>>  $dynamicDomains  la instantánea de `plans` y `class_types`
     * @return array{canonical: array<string,mixed>|null, json: string|null, contentHash: string|null, errors: list<array{key: string, code: string}>}
     */
    public static function fromArray(mixed $content, array $dynamicDomains = []): array
    {
        $yo = new self($dynamicDomains);
        $canonico = $yo->read($content);
        $json = null;
        if ($yo->errors === [] && $canonico !== null) {
            $json = CanonicalJson::encode($canonico);
            if (strlen($json) > self::MAX_BYTES) {
                $yo->fail(self::ROOT, 'too_large');
            }
        }
        if ($yo->errors !== [] || $canonico === null) {
            return ['canonical' => null, 'json' => null, 'contentHash' => null, 'errors' => ConditionCanonicalizer::sortErrors($yo->errors)];
        }

        return ['canonical' => $canonico, 'json' => $json, 'contentHash' => hash('sha256', $json), 'errors' => []];
    }

    /**
     * Lo mismo, pero lo que no vale es un 422 de Laravel con todas las claves.
     *
     * @param  array<string, list<int|string>>  $dynamicDomains
     * @return array{canonical: array<string,mixed>, json: string, contentHash: string}
     *
     * @throws ValidationException
     */
    public static function parse(mixed $content, array $dynamicDomains = []): array
    {
        $r = self::fromArray($content, $dynamicDomains);
        if ($r['canonical'] === null) {
            throw ValidationException::withMessages(ConditionCanonicalizer::messages($r['errors']));
        }

        return ['canonical' => $r['canonical'], 'json' => $r['json'], 'contentHash' => $r['contentHash']];
    }

    /**
     * Relee una versión GUARDADA (los bytes exactos de `content_json`). Nunca
     * lanza: si no cuadra con su huella, no decodifica, no es canónica o el
     * catálogo vigente ya no la admite, devuelve el motivo (D9-3: denegada,
     * no reinterpretada).
     *
     * @param  array<string, list<int|string>>  $dynamicDomains
     * @return array{canonical: array<string,mixed>|null, reason: string|null}
     */
    public static function fromStored(string $json, string $storedHash, array $dynamicDomains = []): array
    {
        if (! hash_equals(hash('sha256', $json), $storedHash)) {
            return ['canonical' => null, 'reason' => 'content_hash_mismatch'];
        }
        try {
            $datos = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Tragado a propósito: unos bytes que no son JSON se niegan, no se reinterpretan.
            return ['canonical' => null, 'reason' => 'malformed'];
        }
        $r = self::fromArray($datos, $dynamicDomains);
        if ($r['canonical'] === null) {
            return ['canonical' => null, 'reason' => self::CATALOG_INCOMPATIBLE];
        }

        return $r['json'] === $json ? ['canonical' => $r['canonical'], 'reason' => null] : ['canonical' => null, 'reason' => 'not_canonical'];
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function read(mixed $c): ?array
    {
        $r = self::ROOT;
        if (! is_array($c) || ($c !== [] && array_is_list($c))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        foreach (array_keys($c) as $k) {
            if (! in_array((string) $k, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                $this->fail($this->child($r, (string) $k), 'unexpected_key');
            }
        }
        foreach (self::REQUIRED as $k) {
            if (! array_key_exists($k, $c)) {
                $this->fail("{$r}.{$k}", 'missing_key');
            }
        }

        $out = [];
        if (array_key_exists('format', $c)) {
            $c['format'] === self::FORMAT ? $out['format'] = self::FORMAT : $this->fail("{$r}.format", is_string($c['format']) ? 'value_not_in_domain' : 'invalid_type');
        }
        if (array_key_exists('goal', $c)) {
            $this->domain($c['goal'], OutreachCatalog::GOALS, "{$r}.goal") && $out['goal'] = $c['goal'];
        }
        $finalidad = null;
        if (array_key_exists('purpose', $c) && $this->domain($c['purpose'], OutreachCatalog::PURPOSES, "{$r}.purpose")) {
            $finalidad = $out['purpose'] = $c['purpose'];
        }
        if (array_key_exists('priority', $c)) {
            $p = $this->integer($c['priority'], "{$r}.priority", 1, 100);
            $p !== null && $out['priority'] = $p;
        }
        if (array_key_exists('trigger', $c)) {
            $t = $this->trigger($c['trigger'], $finalidad);
            $t !== null && $out['trigger'] = $t;
        }
        foreach (['audience' => true, 'success' => true, 'exclusions' => false] as $clave => $obligatoria) {
            if (! array_key_exists($clave, $c) || (! $obligatoria && $c[$clave] === null)) {
                continue;
            }
            $cond = $this->condition($c[$clave], "{$r}.{$clave}", $finalidad);
            $cond !== null && $out[$clave] = $cond;
        }
        if (array_key_exists('sequence', $c)) {
            $s = $this->sequence($c['sequence'], $finalidad);
            $s !== null && $out['sequence'] = $s;
        }
        if (array_key_exists('limits', $c)) {
            $l = $this->limits($c['limits'], $finalidad);
            $l !== null && $out['limits'] = $l;
        }
        if (array_key_exists('schedule', $c) && $c['schedule'] !== null) {
            $h = $this->schedule($c['schedule']);
            $h !== null && $out['schedule'] = $h;
        }
        if (array_key_exists('canary', $c)) {
            $k = $this->canary($c['canary']);
            $k !== null && $out['canary'] = $k;
        }
        if (array_key_exists('studio', $c)) {
            $s = $this->studio($c['studio']);
            $s !== null && $out['studio'] = $s;
        }

        return $this->errors === [] ? $out : null;
    }

    /** @return array{event: string, params?: array<string,int>}|null */
    private function trigger(mixed $t, ?string $finalidad): ?array
    {
        $r = self::ROOT.'.trigger';
        if (! $this->object($t, $r, ['event'], ['params'])) {
            return null;
        }
        $evento = is_string($t['event'] ?? null) ? OutreachCatalog::event($t['event']) : null;
        if ($evento === null || ! $evento['triggerable'] || ! $evento['available']) {
            $this->fail("{$r}.event", 'trigger_not_allowed');

            return null;
        }
        $bien = true;
        if ($finalidad !== null && ! in_array($finalidad, $evento['purposes'], true)) {
            $bien = $this->failFalse("{$r}.event", 'purpose_mismatch');
        }
        $params = $t['params'] ?? [];
        if (! is_array($params) || ($params !== [] && array_is_list($params))) {
            $this->fail("{$r}.params", 'invalid_type');

            return null;
        }
        $esquema = array_column($evento['params'], null, 'name');
        foreach (array_keys($params) as $k) {
            if (! isset($esquema[(string) $k])) {
                $bien = $this->failFalse($this->child("{$r}.params", (string) $k), 'unexpected_key');
            }
        }
        $canonicos = [];
        foreach ($esquema as $nombre => $p) {
            if (! array_key_exists($nombre, $params)) {
                $bien = $this->failFalse("{$r}.params.{$nombre}", 'missing_key');

                continue;
            }
            $v = $this->integer($params[$nombre], "{$r}.params.{$nombre}", $p['min'], $p['max']);
            $v === null ? $bien = false : $canonicos[$nombre] = $v;
        }
        foreach ($evento['paramConstraints'] as $k) {
            if (isset($canonicos[$k['left']], $canonicos[$k['right']]) && $k['op'] === 'lte' && $canonicos[$k['left']] > $canonicos[$k['right']]) {
                $bien = $this->failFalse("{$r}.params", 'constraint_violated');
            }
        }

        if (! $bien) {
            return null;
        }

        // Sin parámetros, la forma canónica no lleva `params` (un objeto vacío sería `[]` en el JSON).
        return $canonicos === [] ? ['event' => $t['event']] : ['event' => $t['event'], 'params' => $canonicos];
    }

    /** @return array<string,mixed>|null */
    private function condition(mixed $arbol, string $raiz, ?string $finalidad): ?array
    {
        $r = ConditionCanonicalizer::check($arbol, $this->dynamic, $raiz);
        foreach ($r['errors'] as $e) {
            $this->fail($e['key'], $e['code']);
        }
        $antes = count($this->errors);
        if ($finalidad !== null) {
            $this->purposeOfFacts($arbol, $raiz, $finalidad);
        }

        return $r['canonical'] !== null && count($this->errors) === $antes ? $r['canonical'] : null;
    }

    /** DF-E4: cada hecho del árbol RECIBIDO cuya lista `purposes` no incluye la finalidad, en su ruta. */
    private function purposeOfFacts(mixed $nodo, string $ruta, string $finalidad): void
    {
        if (! is_array($nodo) || array_is_list($nodo)) {
            return;
        }
        foreach (['all', 'any'] as $grupo) {
            if (isset($nodo[$grupo]) && is_array($nodo[$grupo]) && array_is_list($nodo[$grupo])) {
                foreach ($nodo[$grupo] as $i => $hijo) {
                    $this->purposeOfFacts($hijo, "{$ruta}.{$grupo}.{$i}", $finalidad);
                }
            }
        }
        if (isset($nodo['not'])) {
            $this->purposeOfFacts($nodo['not'], "{$ruta}.not", $finalidad);
        }
        $hecho = is_string($nodo['fact'] ?? null) ? OutreachCatalog::fact($nodo['fact']) : null;
        if ($hecho !== null && ! in_array($finalidad, $hecho['purposes'], true)) {
            $this->fail("{$ruta}.fact", 'fact_purpose_not_allowed');
        }
    }

    /** @return list<array<string,mixed>>|null */
    private function sequence(mixed $pasos, ?string $finalidad): ?array
    {
        $r = self::ROOT.'.sequence';
        if (! is_array($pasos) || ($pasos !== [] && ! array_is_list($pasos))) {
            $this->fail($r, 'invalid_type');

            return null;
        }
        if ($pasos === []) {
            $this->fail($r, 'empty_values');

            return null;
        }
        $bien = true;
        if (count($pasos) > self::MAX_STEPS) {
            $bien = $this->failFalse($r, 'too_many_values');
        }
        $ids = [];
        $out = [];
        foreach ($pasos as $i => $paso) {
            $s = $this->step($paso, "{$r}.{$i}", $finalidad, $ids);
            $s === null ? $bien = false : $out[] = $s;
        }

        return $bien ? $out : null;
    }

    /**
     * @param  array<string, true>  $ids
     * @return array<string,mixed>|null
     */
    private function step(mixed $p, string $r, ?string $finalidad, array &$ids): ?array
    {
        if (! is_array($p) || ($p !== [] && array_is_list($p))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        $bien = $this->keys($p, $r, self::STEP_REQUIRED, self::STEP_OPTIONAL);
        $out = [];

        if (array_key_exists('id', $p)) {
            if (! is_string($p['id']) || preg_match(self::STEP_ID, $p['id']) !== 1) {
                $bien = $this->failFalse("{$r}.id", 'invalid_format');
            } elseif (isset($ids[$p['id']])) {
                $bien = $this->failFalse("{$r}.id", 'duplicate_value');
            } else {
                $ids[$p['id']] = true;
                $out['id'] = $p['id'];
            }
        }
        if (array_key_exists('delay', $p)) {
            $d = $this->duration($p['delay'], "{$r}.delay", 0, self::MAX_DELAY_DAYS, true);
            $d === null ? $bien = false : $out['delay'] = $d;
        }
        $canales = [];
        if (array_key_exists('channels', $p)) {
            $canales = $this->channels($p['channels'], "{$r}.channels");
            $canales === null ? $bien = false : $out['channels'] = $canales;
            $canales ??= $this->validChannels($p['channels']);
        }
        if (array_key_exists('templates', $p)) {
            $t = $this->templates($p['templates'], "{$r}.templates", $canales);
            $t === null ? $bien = false : $out['templates'] = $t;
        }
        if (array_key_exists('guard', $p) && $p['guard'] !== null) {
            $g = $this->condition($p['guard'], "{$r}.guard", $finalidad);
            $g === null ? $bien = false : $out['guard'] = $g;
        }
        $hayEspera = false;
        if (array_key_exists('on', $p) && $p['on'] !== null) {
            $on = $this->onMap($p['on'], "{$r}.on", $hayEspera);
            $on === null ? $bien = false : ($on !== [] && $out['on'] = $on);
        }
        if (array_key_exists('waitFor', $p) && $p['waitFor'] !== null) {
            $w = $this->duration($p['waitFor'], "{$r}.waitFor", 1, self::MAX_WAIT_DAYS, false);
            $w === null ? $bien = false : $out['waitFor'] = $w;
        } elseif ($hayEspera) {
            $bien = $this->failFalse("{$r}.waitFor", 'missing_key');
        }

        return $bien ? $out : null;
    }

    /**
     * 1–3 canales del catálogo, sin repetir (se comprueban todos aunque sobren).
     *
     * @return list<string>|null
     */
    private function channels(mixed $c, string $r): ?array
    {
        if (! is_array($c) || ($c !== [] && ! array_is_list($c))) {
            $this->fail($r, 'invalid_type');

            return null;
        }
        if ($c === []) {
            $this->fail($r, 'empty_values');

            return null;
        }
        $bien = true;
        if (count($c) > self::MAX_CHANNELS) {
            $bien = $this->failFalse($r, 'too_many_values');
        }
        $vistos = [];
        foreach ($c as $i => $canal) {
            if (! is_string($canal) || ! isset(OutreachCatalog::STEP_CHANNELS[$canal])) {
                $bien = $this->failFalse("{$r}.{$i}", is_string($canal) ? 'value_not_in_domain' : 'invalid_type');
            } elseif (in_array($canal, $vistos, true)) {
                $bien = $this->failFalse("{$r}.{$i}", 'duplicate_value');
            } else {
                $vistos[] = $canal;
            }
        }

        return $bien ? $vistos : null;
    }

    /** @return list<string> los canales válidos y distintos de una lista (para comprobar las plantillas aunque la lista falle) */
    private function validChannels(mixed $c): array
    {
        return is_array($c) ? array_values(array_unique(array_filter($c, static fn ($x): bool => is_string($x) && isset(OutreachCatalog::STEP_CHANNELS[$x])))) : [];
    }

    /**
     * @param  list<string>  $canales
     * @return array<string, array{hash: string, uuid: string}>|null
     */
    private function templates(mixed $t, string $r, array $canales): ?array
    {
        if (! is_array($t) || ($t !== [] && array_is_list($t))) {
            $this->fail($r, 'invalid_type');

            return null;
        }
        $bien = true;
        $out = [];
        foreach ($t as $canal => $ref) {
            $canal = (string) $canal;
            if (! in_array($canal, $canales, true)) {
                $bien = $this->failFalse($this->child($r, $canal), 'unexpected_key');

                continue;
            }
            if (! $this->object($ref, "{$r}.{$canal}", ['hash', 'uuid'], [])) {
                $bien = false;

                continue;
            }
            $forma = true;
            if (! is_string($ref['uuid']) || preg_match(OutreachCatalog::UUID_PATTERN, $ref['uuid']) !== 1) {
                $forma = $this->failFalse("{$r}.{$canal}.uuid", 'invalid_format');
            }
            if (! is_string($ref['hash']) || preg_match(OutreachCatalog::HEX64_PATTERN, $ref['hash']) !== 1) {
                $forma = $this->failFalse("{$r}.{$canal}.hash", 'invalid_format');
            }
            $forma ? $out[$canal] = ['hash' => $ref['hash'], 'uuid' => $ref['uuid']] : $bien = false;
        }
        foreach ($canales as $canal) {
            if (! array_key_exists($canal, $t)) {
                $bien = $this->failFalse("{$r}.{$canal}", 'template_missing');
            }
        }

        return $bien ? $out : null;
    }

    /**
     * DF-E6: `{resultado: transición}`, solo transiciones permitidas.
     *
     * @return array<string,string>|null
     */
    private function onMap(mixed $on, string $r, bool &$hayEspera): ?array
    {
        if (! is_array($on) || ($on !== [] && array_is_list($on))) {
            $this->fail($r, 'invalid_type');

            return null;
        }
        $bien = true;
        $out = [];
        foreach ($on as $resultado => $transicion) {
            $resultado = (string) $resultado;
            $ruta = $this->child($r, $resultado);
            if (! OutcomeTable::isOutcome($resultado)) {
                $bien = $this->failFalse($ruta, 'unexpected_key');

                continue;
            }
            if (! is_string($transicion) || ! in_array($transicion, OutcomeTable::TRANSITIONS, true)) {
                $bien = $this->failFalse($ruta, is_string($transicion) ? 'value_not_in_domain' : 'invalid_type');

                continue;
            }
            if (! OutcomeTable::check($resultado, $transicion)['allowed']) {
                $bien = $this->failFalse($ruta, 'transition_not_allowed');

                continue;
            }
            $hayEspera = $hayEspera || $transicion === OutcomeTable::WAIT;
            $out[$resultado] = $transicion;
        }

        return $bien ? $out : null;
    }

    /**
     * Una duración `{n, unit}` (y `from` en un retraso): n entero ≥ mínimo y como
     * mucho `$maxDias` días (`window_out_of_range` en la clave de la duración).
     *
     * @return array<string, int|string>|null
     */
    private function duration(mixed $d, string $r, int $minimo, int $maxDias, bool $conFrom): ?array
    {
        $claves = $conFrom ? ['from', 'n', 'unit'] : ['n', 'unit'];
        if (! is_array($d) || ($d !== [] && array_is_list($d))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        $bien = $this->keys($d, $r, $claves, []);
        $n = array_key_exists('n', $d) ? $this->integer($d['n'], "{$r}.n", $minimo, null) : null;
        $n === null && $bien = false;
        $unidad = $d['unit'] ?? null;
        if (array_key_exists('unit', $d) && (! is_string($unidad) || ! in_array($unidad, OutreachCatalog::UNITS, true))) {
            $bien = $this->failFalse("{$r}.unit", is_string($unidad) ? 'value_not_in_domain' : 'invalid_type');
        }
        $desde = $d['from'] ?? null;
        if ($conFrom && array_key_exists('from', $d) && ! in_array($desde, [FollowUpMachine::DELAY_FROM_ENROLLMENT, FollowUpMachine::DELAY_FROM_PREVIOUS], true)) {
            $bien = $this->failFalse("{$r}.from", is_string($desde) ? 'value_not_in_domain' : 'invalid_type');
        }
        if (! $bien) {
            return null;
        }
        if ($n * OutreachCatalog::UNIT_HOURS[$d['unit']] > $maxDias * 24) {
            $this->fail($r, 'window_out_of_range');

            return null;
        }

        return $conFrom ? ['from' => $d['from'], 'n' => $n, 'unit' => $d['unit']] : ['n' => $n, 'unit' => $d['unit']];
    }

    /** @return array<string,int>|null */
    private function limits(mixed $l, ?string $finalidad): ?array
    {
        $r = self::ROOT.'.limits';
        if (! is_array($l) || ($l !== [] && array_is_list($l))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        $opcionales = $finalidad === OutreachCatalog::PURPOSE_COMMERCIAL ? self::LIMITS_OPTIONAL : [];
        $bien = $this->keys($l, $r, array_keys(self::LIMITS), array_keys($opcionales));
        $out = [];
        foreach (self::LIMITS + $opcionales as $clave => [$min, $max]) {
            if (! array_key_exists($clave, $l)) {
                continue;
            }
            $v = $this->integer($l[$clave], "{$r}.{$clave}", $min, $max);
            $v === null ? $bien = false : $out[$clave] = $v;
        }

        return $bien ? $out : null;
    }

    /** @return array{days: list<int>, windows: list<array{from: int, to: int}>}|null */
    private function schedule(mixed $h): ?array
    {
        $r = self::ROOT.'.schedule';
        if (! is_array($h) || ($h !== [] && array_is_list($h))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        $bien = $this->keys($h, $r, ['days', 'windows'], []);
        if (! $bien && (! array_key_exists('days', $h) || ! array_key_exists('windows', $h))) {
            return null;
        }
        $dias = [];
        if (! is_array($h['days']) || ! array_is_list($h['days']) || $h['days'] === []) {
            $bien = $this->failFalse("{$r}.days", is_array($h['days']) && $h['days'] === [] ? 'empty_values' : 'invalid_type');
        } else {
            foreach ($h['days'] as $i => $d) {
                if (! is_int($d) || $d < 1 || $d > 6) {
                    $bien = $this->failFalse("{$r}.days.{$i}", is_int($d) ? 'value_not_in_domain' : 'invalid_type');
                } elseif (in_array($d, $dias, true)) {
                    $bien = $this->failFalse("{$r}.days.{$i}", 'duplicate_value');
                } else {
                    $dias[] = $d;
                }
            }
        }
        $franjas = [];
        $w = $h['windows'];
        if (! is_array($w) || ! array_is_list($w) || $w === []) {
            $bien = $this->failFalse("{$r}.windows", is_array($w) && $w === [] ? 'empty_values' : 'invalid_type');
        } elseif (count($w) > self::MAX_SCHEDULE_WINDOWS) {
            $bien = $this->failFalse("{$r}.windows", 'too_many_values');
        } else {
            foreach ($w as $i => $f) {
                $ruta = "{$r}.windows.{$i}";
                if (! $this->object($f, $ruta, ['from', 'to'], [])) {
                    $bien = false;

                    continue;
                }
                $desde = $this->integer($f['from'], "{$ruta}.from", 0, 1440);
                $hasta = $this->integer($f['to'], "{$ruta}.to", 0, 1440);
                if ($desde === null || $hasta === null) {
                    $bien = false;

                    continue;
                }
                if ($desde >= $hasta) {
                    $bien = $this->failFalse($ruta, 'invalid_between');

                    continue;
                }
                [$abre, $cierra] = ContactCalendar::N1_WINDOWS[1];
                if ($hasta <= $abre || $desde >= $cierra) {
                    $bien = $this->failFalse($ruta, 'schedule_outside_legal_window');

                    continue;
                }
                $franjas[] = ['from' => $desde, 'to' => $hasta];
            }
        }
        sort($dias);

        return $bien ? ['days' => $dias, 'windows' => $franjas] : null;
    }

    /** @return array{channel: string}|null */
    private function canary(mixed $k): ?array
    {
        $r = self::ROOT.'.canary';
        if (! $this->object($k, $r, ['channel'], [])) {
            return null;
        }

        return $this->domain($k['channel'], OutreachCatalog::MEDIUMS, "{$r}.channel") ? ['channel' => $k['channel']] : null;
    }

    /** @return array<string,string>|null */
    private function studio(mixed $s): ?array
    {
        $r = self::ROOT.'.studio';
        if (! is_array($s) || ($s !== [] && array_is_list($s))) {
            $this->fail($r, 'not_an_object');

            return null;
        }
        if (! array_key_exists('kind', $s)) {
            $this->fail("{$r}.kind", 'missing_key');

            return null;
        }
        $formas = [
            'version' => ['uuid' => OutreachCatalog::UUID_PATTERN, 'contentHash' => OutreachCatalog::HEX64_PATTERN],
            'baseline' => ['catalogVersion' => self::STUDIO_CATALOG_VERSION, 'catalogHash' => OutreachCatalog::HEX64_PATTERN],
        ];
        if (! is_string($s['kind']) || ! isset($formas[$s['kind']])) {
            $this->fail("{$r}.kind", 'value_not_in_domain');

            return null;
        }
        $bien = $this->keys($s, $r, ['kind', ...array_keys($formas[$s['kind']])], []);
        $out = ['kind' => $s['kind']];
        foreach ($formas[$s['kind']] as $clave => $patron) {
            if (! array_key_exists($clave, $s)) {
                continue;
            }
            if (! is_string($s[$clave]) || preg_match($patron, $s[$clave]) !== 1) {
                $bien = $this->failFalse("{$r}.{$clave}", 'invalid_format');

                continue;
            }
            $out[$clave] = $s[$clave];
        }

        return $bien ? $out : null;
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** Un objeto con sus claves obligatorias y opcionales (las demás, `unexpected_key`; las que faltan, `missing_key`). */
    private function object(mixed $v, string $r, array $obligatorias, array $opcionales): bool
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->fail($r, 'not_an_object');

            return false;
        }

        return $this->keys($v, $r, $obligatorias, $opcionales);
    }

    private function keys(array $v, string $r, array $obligatorias, array $opcionales): bool
    {
        $bien = true;
        foreach (array_keys($v) as $k) {
            if (! in_array((string) $k, [...$obligatorias, ...$opcionales], true)) {
                $bien = $this->failFalse($this->child($r, (string) $k), 'unexpected_key');
            }
        }
        foreach ($obligatorias as $k) {
            if (! array_key_exists($k, $v)) {
                $bien = $this->failFalse("{$r}.{$k}", 'missing_key');
            }
        }

        return $bien;
    }

    private function domain(mixed $v, array $dominio, string $r): bool
    {
        if (is_string($v) && in_array($v, $dominio, true)) {
            return true;
        }

        return $this->failFalse($r, is_string($v) ? 'value_not_in_domain' : 'invalid_type');
    }

    private function integer(mixed $v, string $r, ?int $min, ?int $max): ?int
    {
        [$estado, $n] = is_bool($v) ? [CanonicalJson::NUMBER_NOT_NUMBER, null] : CanonicalJson::normalizeNumber($v);
        $codigo = match ($estado) {
            CanonicalJson::NUMBER_NOT_NUMBER => 'invalid_type',
            CanonicalJson::NUMBER_NOT_FINITE => 'value_not_finite',
            CanonicalJson::NUMBER_NOT_INTEGER => 'value_not_integer',
            default => null,
        };
        if ($codigo !== null) {
            $this->fail($r, $codigo);

            return null;
        }
        if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
            $this->fail($r, 'value_out_of_range');

            return null;
        }

        return $n;
    }

    private function fail(string $key, string $code): void
    {
        $this->errors[] = ['key' => $key, 'code' => $code];
    }

    private function failFalse(string $key, string $code): false
    {
        $this->fail($key, $code);

        return false;
    }

    private function child(string $path, string $key): string
    {
        return $path.'.'.(preg_match(self::SAFE_KEY, $key) === 1 ? $key : '_invalid_key');
    }
}
