<?php

namespace App\Services\UltronStudio;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * LA EVIDENCIA DEL CANARIO PARA PROMOVER (contrato H5 §A6 y §A5.2): la parte
 * PURA. Recibe los recuentos ya hechos (sin ids de conversación ni textos), los
 * umbrales en vigor y el instante de la lectura, y decide: las horas, qué filas
 * fallan, si pasa y la huella que tiene que devolver quien promueve. Sin base de
 * datos, sin HTTP, sin configuración y sin reloj.
 *
 * `tests/fixtures/ultron-studio/publication-cases.json` (sección `evidence`;
 * README V-1 a V-10, congeladas por la Enmienda 1) manda caso a caso.
 *
 *  - `hours` = floor(segundos desde `since` / 3600) (V-1): 23 h 59 min 59 s no
 *    son 24. Un reloj por detrás de `since` da 0, nunca un negativo.
 *  - Deciden, en el orden de la tabla de A6 (V-7): horas ≥ `minHours`, turnos ≥
 *    `minTurns`, `stamped` = `turns` (con 0 turnos solo falla `turns`, V-2),
 *    ningún freno desde la entrada y el de ahora suelto (V-3), el acta con
 *    salida 0 y total 0 (V-4), sin deriva (V-6) y la firma de la cabeza buena.
 *    `byState` y `recoveries` solo informan (V-10).
 *  - `evidenceHash` = `StudioContentHasher::hash` de `{since, turns, stamped,
 *    byState, brakes, acta, recoveries, drift, signatureOk, thresholds}` (V-8):
 *    ni el reloj ni lo que sale de él (horas, `passes`, `failing`). La huella no
 *    cambia con el paso del tiempo y sí con cualquier recuento, también los
 *    informativos, o con un umbral: quien promueve tiene que haber visto todo
 *    lo que no es el reloj.
 *
 * LO QUE NO ESTÁ AQUÍ: LAS CONSULTAS (paso 3). Contar en la base los turnos de
 * la conversación canaria del motor (`marketing_ai_actions` con `source_type`
 * del mensaje entrante, desde la entrada que puso la versión en CANARY) y su
 * reparto por sello (`metadata.studio.state`; `stamped`, además, con
 * `metadata.studio.version` igual a la versión), los frenos (`ultron_aborts`
 * accionados desde la entrada y el de ahora), el acta (`ultron:canary-report
 * <canario> --since=<entrada> --turns=… --json` por `Artisan::call`, de la que
 * solo se leen la salida, el total y los recuentos, y nunca el texto), las
 * recuperaciones (`metadata.recovery`), la deriva
 * ({@see StudioPublicationDeclaration::drift()}) y la firma de la cabeza
 * (`describe()` del lector). Las añade el paso 3 en esta misma clase o en una
 * compañera; aquí llegan hechas.
 *
 * Una forma inesperada es un error del llamador: InvalidArgumentException,
 * nunca un recuento por defecto. Así una evidencia incoherente (un reparto que
 * no suma los turnos, más sellados que aplicados, un acta cuyo total no es la
 * suma de sus recuentos) no puede promover nada.
 */
final class StudioCanaryEvidence
{
    /** Lo que llega contado (la tabla de A6 sin las horas, que salen del reloj) y `since`, la marca de inicio (V-9). */
    public const MEASURES = ['since', 'turns', 'stamped', 'byState', 'brakes', 'acta', 'recoveries', 'drift', 'signatureOk'];

    /** Las filas que deciden, en el orden de la tabla de A6 (V-7): es el orden de `failing`. */
    public const GATES = ['hours', 'turns', 'stamped', 'brakes', 'acta', 'drift', 'signatureOk'];

    /**
     * Las diez claves de `byState` (V-5), por orden de bytes: los estados del
     * sello del motor (§A4.4; cada `fallback` con su motivo) y `unstamped`, las
     * filas sin sello. Siempre las diez, también a cero; reparten los turnos.
     */
    public const BY_STATE = [
        'applied',
        'fallback/contract_unknown',
        'fallback/hash_mismatch',
        'fallback/malformed',
        'fallback/read_failed',
        'fallback/signature_invalid',
        'fallback/table_missing',
        'legacy',
        'out_of_scope',
        'unstamped',
    ];

    /** Los topes de los umbrales (§A13; decisión 3 del lead: mínimos duros de 1 h y 1 turno). */
    public const MIN_HOURS = 1;

    public const MAX_HOURS = 168;

    public const MIN_TURNS = 1;

    public const MAX_TURNS = 100;

    /** Los dos pasos de la evidencia al promover (§A5.2), en su orden. */
    public const CODE_INSUFFICIENT = 'canary_evidence_insufficient';

    public const CODE_CHANGED = 'evidence_changed';

    private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * La decisión sobre unos recuentos (§A6).
     *
     * @param  array<string,mixed>  $measures  las claves de {@see MEASURES}; `since` es un instante o su texto UTC (`AAAA-MM-DDTHH:MM:SSZ`)
     * @param  array{minHours:int,minTurns:int}  $thresholds  los umbrales en vigor
     * @param  DateTimeInterface  $now  el instante de la lectura
     * @return array{hours:int,passes:bool,failing:list<string>,evidenceHash:string}
     *
     * @throws InvalidArgumentException si los recuentos o los umbrales no tienen su forma
     */
    public static function evaluate(array $measures, array $thresholds, DateTimeInterface $now): array
    {
        $e = self::evidence($measures, $thresholds);
        $horas = self::hours($e['since'], $now);

        $falla = [
            'hours' => $horas < $e['thresholds']['minHours'],
            'turns' => $e['turns'] < $e['thresholds']['minTurns'],
            'stamped' => $e['stamped'] !== $e['turns'],
            'brakes' => $e['brakes']['count'] !== 0 || $e['brakes']['engaged'],
            'acta' => $e['acta']['exitCode'] !== 0 || $e['acta']['total'] !== 0,
            'drift' => $e['drift'] !== [],
            'signatureOk' => ! $e['signatureOk'],
        ];
        $failing = array_values(array_filter(self::GATES, static fn (string $fila): bool => $falla[$fila]));

        return [
            'hours' => $horas,
            'passes' => $failing === [],
            'failing' => $failing,
            'evidenceHash' => StudioContentHasher::hash($e),
        ];
    }

    /**
     * El objeto que firma la huella (V-8), validado y normalizado: `since` en
     * UTC al segundo, `byState` con sus diez claves en orden y los recuentos del
     * acta por nombre. Es también lo que guarda la cadena al promover (columna
     * `evidence`: recuentos, umbrales y marca de inicio, sin ids ni textos).
     *
     * @param  array<string,mixed>  $measures
     * @param  array{minHours:int,minTurns:int}  $thresholds
     * @return array{since:string,turns:int,stamped:int,byState:array<string,int>,brakes:array{count:int,engaged:bool},acta:array{exitCode:int,total:int|null,counts:array<string,int>|null},recoveries:int,drift:list<array{param:string,declared:bool|string,engine:bool|string|null}>,signatureOk:bool,thresholds:array{minHours:int,minTurns:int}}
     *
     * @throws InvalidArgumentException
     */
    public static function evidence(array $measures, array $thresholds): array
    {
        self::assertExactKeys($measures, self::MEASURES, 'measures');
        self::assertExactKeys($thresholds, ['minHours', 'minTurns'], 'thresholds');

        $since = $measures['since'] instanceof DateTimeInterface ? $measures['since'] : self::instant($measures['since'], 'since');
        $turnos = self::count($measures['turns'], 'turns');
        $sellados = self::count($measures['stamped'], 'stamped');

        return [
            'since' => StudioPublicationDeclaration::utc($since),
            'turns' => $turnos,
            'stamped' => $sellados,
            'byState' => self::byState($measures['byState'], $turnos, $sellados),
            'brakes' => self::brakes($measures['brakes']),
            'acta' => self::acta($measures['acta']),
            'recoveries' => self::count($measures['recoveries'], 'recoveries'),
            'drift' => self::drift($measures['drift']),
            'signatureOk' => self::bool($measures['signatureOk'], 'signatureOk'),
            'thresholds' => [
                'minHours' => self::intBetween($thresholds['minHours'], self::MIN_HOURS, self::MAX_HOURS, 'thresholds.minHours'),
                'minTurns' => self::intBetween($thresholds['minTurns'], self::MIN_TURNS, self::MAX_TURNS, 'thresholds.minTurns'),
            ],
        ];
    }

    /**
     * Los bytes que firma `evidenceHash` (V-8): el JSON canónico de
     * {@see evidence()}.
     *
     * @param  array<string,mixed>  $measures
     * @param  array{minHours:int,minTurns:int}  $thresholds
     */
    public static function evidenceJson(array $measures, array $thresholds): string
    {
        return StudioContentHasher::canonicalJson(self::evidence($measures, $thresholds));
    }

    /**
     * Las horas enteras desde `since` (V-1): floor de los segundos entre 3600,
     * contados al segundo (las fracciones no cuentan, como en `since`). Si el
     * reloj va por detrás de `since`, 0.
     */
    public static function hours(DateTimeInterface|string $since, DateTimeInterface $now): int
    {
        $desde = $since instanceof DateTimeInterface ? $since : self::instant($since, 'since');
        $segundos = $now->getTimestamp() - $desde->getTimestamp();

        return $segundos > 0 ? intdiv($segundos, 3600) : 0;
    }

    /**
     * Los dos pasos de la evidencia al promover (§A5.2), en su orden (fixture
     * P-7): evidencia insuficiente → `canary_evidence_insufficient`; suficiente
     * pero con otra huella → `evidence_changed`. El freno echado AHORA ya falla
     * en `brakes`, así que con él sale la primera y no `engine_braked`. Devuelve
     * el primero que rechaza, o null. El payload es null: lo pone quien llama
     * (la evidencia, en el DTO del contrato).
     *
     * @param  array<string,mixed>  $evaluation  la salida de {@see evaluate()}; bastan `passes` y `evidenceHash`
     * @return array{code:string,payload:null}|null
     */
    public static function refusal(array $evaluation, string $sentEvidenceHash): ?array
    {
        $pasa = $evaluation['passes'] ?? null;
        $huella = $evaluation['evidenceHash'] ?? null;
        if (! is_bool($pasa) || ! is_string($huella)) {
            throw new InvalidArgumentException('La evaluación necesita passes y evidenceHash.');
        }

        if (! $pasa) {
            return ['code' => self::CODE_INSUFFICIENT, 'payload' => null];
        }
        if (! hash_equals($huella, $sentEvidenceHash)) {
            return ['code' => self::CODE_CHANGED, 'payload' => null];
        }

        return null;
    }

    // ── Validación de los recuentos ─────────────────────────────────────────

    /** @return array<string,int> */
    private static function byState(mixed $porEstado, int $turnos, int $sellados): array
    {
        self::assertExactKeys($porEstado, self::BY_STATE, 'byState');

        $out = [];
        foreach (self::BY_STATE as $estado) {
            $out[$estado] = self::count($porEstado[$estado], "byState.{$estado}");
        }
        if (array_sum($out) !== $turnos) {
            throw new InvalidArgumentException('byState reparte los turnos: su suma tiene que ser turns.');
        }
        if ($sellados > $out['applied']) {
            throw new InvalidArgumentException('stamped es la parte de applied con esta versión: no puede superarla.');
        }

        return $out;
    }

    /** @return array{count:int,engaged:bool} */
    private static function brakes(mixed $frenos): array
    {
        self::assertExactKeys($frenos, ['count', 'engaged'], 'brakes');

        return [
            'count' => self::count($frenos['count'], 'brakes.count'),
            'engaged' => self::bool($frenos['engaged'], 'brakes.engaged'),
        ];
    }

    /**
     * El acta (V-4): `{exitCode, total, counts}`. Sin JSON (la conversación no
     * existe, un `--since` ilegible), total y recuentos son null a la vez. Con
     * JSON, los recuentos van por nombre y el total es su suma, como lo calcula
     * `ultron:canary-report`.
     *
     * @return array{exitCode:int,total:int|null,counts:array<string,int>|null}
     */
    private static function acta(mixed $acta): array
    {
        self::assertExactKeys($acta, ['counts', 'exitCode', 'total'], 'acta');
        if (! is_int($acta['exitCode'])) {
            throw new InvalidArgumentException('acta.exitCode tiene que ser el código de salida del comando.');
        }

        if ($acta['total'] === null || $acta['counts'] === null) {
            if ($acta['total'] !== null || $acta['counts'] !== null) {
                throw new InvalidArgumentException('Un acta sin JSON no tiene ni total ni recuentos.');
            }

            return ['exitCode' => $acta['exitCode'], 'total' => null, 'counts' => null];
        }

        $total = self::count($acta['total'], 'acta.total');
        if (! is_array($acta['counts']) || $acta['counts'] === [] || array_is_list($acta['counts'])) {
            throw new InvalidArgumentException('acta.counts son los contadores del acta, por nombre.');
        }
        $recuentos = [];
        foreach ($acta['counts'] as $nombre => $n) {
            if (! is_string($nombre)) {
                throw new InvalidArgumentException('acta.counts son los contadores del acta, por nombre.');
            }
            $recuentos[$nombre] = self::count($n, "acta.counts.{$nombre}");
        }
        ksort($recuentos, SORT_STRING);
        if (array_sum($recuentos) !== $total) {
            throw new InvalidArgumentException('El total del acta es la suma de sus recuentos.');
        }

        return ['exitCode' => $acta['exitCode'], 'total' => $total, 'counts' => $recuentos];
    }

    /**
     * La deriva (V-6): la lista de {@see StudioPublicationDeclaration::drift()},
     * `{param, declared, engine}` con `engine: null` si la lectura cayó (Q-5).
     *
     * @return list<array{param:string,declared:bool|string,engine:bool|string|null}>
     */
    private static function drift(mixed $deriva): array
    {
        if (! is_array($deriva) || ! array_is_list($deriva)) {
            throw new InvalidArgumentException('drift es una lista.');
        }

        $out = [];
        foreach ($deriva as $i => $d) {
            self::assertExactKeys($d, ['declared', 'engine', 'param'], "drift.{$i}");
            if (! is_string($d['param']) || ! array_key_exists($d['param'], StudioPublicationDeclaration::MUST_MATCH)) {
                throw new InvalidArgumentException("drift.{$i}.param tiene que ser uno de los cuatro must_match.");
            }
            if (! is_bool($d['declared']) && ! is_string($d['declared'])) {
                throw new InvalidArgumentException("drift.{$i}.declared es un valor declarado (booleano o texto).");
            }
            if ($d['engine'] !== null && ! is_bool($d['engine']) && ! is_string($d['engine'])) {
                throw new InvalidArgumentException("drift.{$i}.engine es la lectura del motor (booleano, texto o null si cayó).");
            }
            $out[] = ['param' => $d['param'], 'declared' => $d['declared'], 'engine' => $d['engine']];
        }

        return $out;
    }

    /** Un instante UTC escrito como en E-2 (`AAAA-MM-DDTHH:MM:SSZ`), y solo así. */
    private static function instant(mixed $texto, string $campo): DateTimeImmutable
    {
        $instante = is_string($texto) ? DateTimeImmutable::createFromFormat('!'.self::UTC_FORMAT, $texto, new DateTimeZone('UTC')) : false;
        if ($instante === false || $instante->format(self::UTC_FORMAT) !== $texto) {
            throw new InvalidArgumentException("{$campo} tiene que ser un instante UTC AAAA-MM-DDTHH:MM:SSZ.");
        }

        return $instante;
    }

    private static function count(mixed $n, string $campo): int
    {
        if (! is_int($n) || $n < 0) {
            throw new InvalidArgumentException("{$campo} tiene que ser un recuento entero, 0 o más.");
        }

        return $n;
    }

    private static function bool(mixed $valor, string $campo): bool
    {
        if (! is_bool($valor)) {
            throw new InvalidArgumentException("{$campo} tiene que ser verdadero o falso.");
        }

        return $valor;
    }

    private static function intBetween(mixed $n, int $min, int $max, string $campo): int
    {
        if (! is_int($n) || $n < $min || $n > $max) {
            throw new InvalidArgumentException("{$campo} tiene que ser un entero de {$min} a {$max}.");
        }

        return $n;
    }

    /** @param  list<string>  $claves */
    private static function assertExactKeys(mixed $valor, array $claves, string $campo): void
    {
        $tiene = is_array($valor) ? array_keys($valor) : null;
        if ($tiene !== null) {
            sort($tiene, SORT_STRING);
        }
        sort($claves, SORT_STRING);
        if ($tiene !== $claves) {
            throw new InvalidArgumentException("{$campo} lleva exactamente ".implode(', ', $claves).'.');
        }
    }
}
