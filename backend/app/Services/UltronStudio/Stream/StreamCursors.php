<?php

namespace App\Services\UltronStudio\Stream;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * El VECTOR de cursores de una conexión: `ledger:123,voice.calls:9`.
 *
 * Por qué un vector y no un cursor. `EventSource` solo recuerda el ÚLTIMO
 * `id:` que recibió, sea del tema que sea; si cada evento llevara solo su
 * cursor, al caerse la conexión se perdería la posición de los demás temas.
 * Por eso el `id` de cada evento es el vector completo de los temas
 * suscritos (con un solo tema es exactamente `<tema>:<cursor>`, la forma del
 * contrato), y es lo que el cliente devuelve, por `Last-Event-ID` en una
 * reconexión automática del navegador o por `?after=` cuando abre una
 * conexión nueva tras el cierre de los 55 s (ahí el navegador no manda la
 * cabecera).
 */
final class StreamCursors
{
    /** Un nombre de tema: `ledger`, `voice.calls`, `outreach.runs`. */
    public const TOPIC = '/^[a-z]+(?:\.[a-z]+)?$/D';

    /** Un cursor: texto opaco sin los separadores del vector. */
    public const CURSOR = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** Tope del vector entero (11 temas × 64 + separadores cabe de sobra). */
    public const MAX_LENGTH = 1024;

    /**
     * `a:1,b.c:2` → ['a' => '1', 'b.c' => '2'].
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException con cualquier cosa que no tenga esa forma (quien llama decide: 422 en la query, ignorar en la cabecera).
     */
    public static function parse(string $vector): array
    {
        if ($vector === '' || strlen($vector) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('vector de cursores vacío o demasiado largo');
        }
        $cursores = [];
        foreach (explode(',', $vector) as $par) {
            $pos = strpos($par, ':');
            if ($pos === false) {
                throw new InvalidArgumentException('par sin separador');
            }
            $tema = substr($par, 0, $pos);
            $cursor = substr($par, $pos + 1);
            if (preg_match(self::TOPIC, $tema) !== 1 || preg_match(self::CURSOR, $cursor) !== 1 || isset($cursores[$tema])) {
                throw new InvalidArgumentException('par inválido o repetido');
            }
            $cursores[$tema] = $cursor;
        }

        return $cursores;
    }

    /** Un cursor que es un id entero (hasta 18 dígitos), o null si tiene otra forma. */
    public static function id(string $cursor): ?int
    {
        return ctype_digit($cursor) && strlen($cursor) <= 18 ? (int) $cursor : null;
    }

    /**
     * Un instante de la base (texto `Y-m-d H:i:s`, o Carbon) en ISO-8601 UTC.
     * Los temas lo usan para `at`; la base guarda en la zona de la aplicación.
     */
    public static function iso(mixed $instante): string
    {
        $carbon = $instante instanceof \DateTimeInterface ? Carbon::instance($instante) : Carbon::parse((string) $instante, config('app.timezone'));

        return $carbon->utc()->toIso8601String();
    }

    /** @param  array<string, string>  $cursores */
    public static function format(array $cursores): string
    {
        $pares = [];
        foreach ($cursores as $tema => $cursor) {
            $pares[] = $tema.':'.$cursor;
        }

        return implode(',', $pares);
    }
}
