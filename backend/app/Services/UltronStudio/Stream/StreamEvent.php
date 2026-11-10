<?php

namespace App\Services\UltronStudio\Stream;

use InvalidArgumentException;

/**
 * Un evento del canal: el cursor del tema tras él y su `data`, acotada a una
 * LISTA BLANCA de claves ({@see KEYS}) con valores escalares cortos.
 *
 * La lista blanca se impone aquí, en el constructor, y no en cada tema: así
 * ningún adaptador —tampoco los que vengan después— puede colar un nombre,
 * un teléfono, un cuerpo de mensaje o un importe aunque quiera. `type`, `id`
 * y `at` son obligatorios; `state` puede ser null; `hash` y `ref` solo cuando
 * el módulo los tiene (una huella sha256 o una referencia en código).
 */
final class StreamEvent
{
    /** Las únicas claves que viajan en `data` (contrato: {type, id, state, at} más `hash` o `ref`). */
    public const KEYS = ['type', 'id', 'state', 'at', 'hash', 'ref'];

    /** Tope de cada valor: una huella son 64 caracteres; un uuid, 36; un código, menos. */
    public const MAX_VALUE_LENGTH = 128;

    /** @var array<string, string|int|null> */
    public readonly array $data;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException si `data` trae una clave fuera de la lista blanca, un valor que no es escalar corto o le falta una obligatoria.
     */
    public function __construct(public readonly string $cursor, array $data)
    {
        if (preg_match(StreamCursors::CURSOR, $cursor) !== 1) {
            throw new InvalidArgumentException('ultron_studio stream: cursor inválido.');
        }

        $limpia = [];
        foreach ($data as $clave => $valor) {
            if (! in_array($clave, self::KEYS, true)) {
                throw new InvalidArgumentException("ultron_studio stream: la clave «{$clave}» no está en la lista blanca del evento.");
            }
            if ($valor === null) {
                $limpia[$clave] = null;

                continue;
            }
            if (is_int($valor)) {
                $limpia[$clave] = $valor;

                continue;
            }
            if (! is_string($valor) || $valor === '' || mb_strlen($valor) > self::MAX_VALUE_LENGTH || preg_match('/[\r\n]/', $valor) === 1) {
                throw new InvalidArgumentException("ultron_studio stream: el valor de «{$clave}» no es un escalar corto.");
            }
            $limpia[$clave] = $valor;
        }

        foreach (['type', 'id', 'at'] as $obligatoria) {
            if (! isset($limpia[$obligatoria])) {
                throw new InvalidArgumentException("ultron_studio stream: al evento le falta «{$obligatoria}».");
            }
        }

        // Orden fijo de las claves: la forma del evento es parte del contrato.
        $ordenada = [];
        foreach (self::KEYS as $clave) {
            if (array_key_exists($clave, $limpia)) {
                $ordenada[$clave] = $limpia[$clave];
            }
        }
        $this->data = $ordenada;
    }
}
