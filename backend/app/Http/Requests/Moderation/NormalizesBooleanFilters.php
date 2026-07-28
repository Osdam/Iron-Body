<?php

namespace App\Http\Requests\Moderation;

/**
 * Normaliza filtros booleanos que llegan por query string.
 *
 * El problema que resuelve: la regla `boolean` de Laravel acepta
 * `true, false, 1, 0, "1", "0"` pero NO las cadenas `"true"` / `"false"`. Un
 * cliente HTTP que serialice un booleano con `String(value)` —lo que hace
 * `HttpParams` de Angular por defecto— envía `open_only=true` y recibe un 422
 * `validation.boolean` que, desde fuera, parece un fallo del servidor.
 *
 * En vez de exigir un formato concreto al cliente, el contrato se hace
 * ROBUSTO: se aceptan las representaciones habituales de un booleano en una
 * URL y se convierten al tipo real antes de validar.
 *
 * Lo que NO se hace: adivinar. Un valor fuera de la lista (`maybe`, `2`, `si`)
 * se deja intacto a propósito para que la regla `boolean` lo rechace con un
 * 422 explícito. Interpretar silenciosamente un valor desconocido como `false`
 * escondería un error del cliente y haría que la bandeja mostrara datos
 * distintos a los pedidos.
 */
trait NormalizesBooleanFilters
{
    /** Representaciones aceptadas, en minúsculas. */
    private const TRUTHY = ['1', 'true', 'on', 'yes'];

    private const FALSY = ['0', 'false', 'off', 'no'];

    /**
     * Convierte a booleano real los parámetros indicados por
     * {@see booleanFilters()}. Los ausentes no se tocan (siguen siendo
     * `nullable`); los desconocidos tampoco, para que fallen la validación.
     */
    protected function normalizeBooleanFilters(): void
    {
        $patch = [];

        foreach ($this->booleanFilters() as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $raw = $this->input($key);

            // Ya es booleano (p. ej. cuerpo JSON): nada que normalizar.
            if (is_bool($raw)) {
                continue;
            }

            // Una cadena vacía significa "sin filtro", no `false`: se descarta
            // para que el parámetro quede como ausente.
            if ($raw === null || $raw === '') {
                $patch[$key] = null;

                continue;
            }

            if (is_string($raw) || is_int($raw)) {
                $normalized = strtolower(trim((string) $raw));

                if (in_array($normalized, self::TRUTHY, true)) {
                    $patch[$key] = true;

                    continue;
                }

                if (in_array($normalized, self::FALSY, true)) {
                    $patch[$key] = false;

                    continue;
                }
            }

            // Valor no reconocido: se deja tal cual → la regla `boolean` lo
            // rechaza con 422. No se adivina.
        }

        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    /**
     * Nombres de los filtros booleanos del endpoint.
     *
     * @return list<string>
     */
    abstract protected function booleanFilters(): array;

    /** Mensajes claros para un booleano realmente inválido. */
    protected function booleanFilterMessages(): array
    {
        $messages = [];

        foreach ($this->booleanFilters() as $key) {
            $messages[$key.'.boolean'] =
                "El filtro «{$key}» admite 1/0 (o true/false); se recibió un valor no válido.";
        }

        return $messages;
    }
}
