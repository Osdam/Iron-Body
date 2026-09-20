<?php

namespace App\Services\Marketing\Ultron;

use App\Models\UltronAbort;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;

/**
 * El freno de emergencia, y las dos reglas que lo hacen fiable.
 *
 * REGLA 1 — sólo apaga. Nada de lo que hay aquí puede ENCENDER ULTRON: si el
 * entorno lo tiene apagado, soltar el freno no arranca nada. Por eso puede
 * accionarlo un detector automático sin que eso sea un riesgo: lo peor que
 * puede hacer un fallo de esta clase es dejar el sistema quieto.
 *
 * REGLA 2 — soltarlo es humano. `release()` exige un nombre y no hay ningún
 * camino que lo llame solo. Un freno que se suelta a sí mismo cuando el síntoma
 * deja de verse es un freno que se suelta justo cuando el problema se vuelve
 * intermitente, que es cuando más daño hace.
 *
 * Vive en la base y no en caché a propósito: un `cache:clear` no puede volver a
 * poner en marcha un canario que alguien paró, y los doce workers ven la misma
 * fila sin tener que enterarse de nada.
 */
class UltronAbortLatch
{
    /** ¿Está parado? */
    public function engaged(): bool
    {
        return $this->current() !== null;
    }

    /** La parada viva, si la hay. */
    public function current(): ?UltronAbort
    {
        try {
            return UltronAbort::open()->latest('id')->first();
        } catch (QueryException $e) {
            /*
             * Si la tabla no existe todavía —despliegue a medias, migración sin
             * correr— esto NO puede tumbar el canal. Se falla del lado de
             * seguir funcionando igual que antes de que el freno existiera, y
             * se deja constancia ruidosa de que el freno no está operativo.
             */
            ChannelLog::warning('ultron.abort.unavailable', ['error_class' => class_basename($e)]);

            return null;
        }
    }

    /**
     * Acciona el freno. Idempotente: si ya estaba parado devuelve esa parada y
     * no abre otra, porque dos detectores no son dos incidentes.
     *
     * @param  array<string,mixed>  $evidence  Ids, nunca contenido de conversación.
     */
    public function engage(string $reason, string $by, array $evidence = []): UltronAbort
    {
        if ($vigente = $this->current()) {
            return $vigente;
        }

        $abort = UltronAbort::create([
            'reason' => $reason,
            'engaged_by' => $by,
            'engaged_at' => now(),
            'evidence' => $evidence ?: null,
        ]);

        ChannelLog::warning('ultron.abort.engaged', [
            'abort_id' => $abort->id,
            'reason' => $reason,
            'engaged_by' => $by,
        ]);

        return $abort;
    }

    /**
     * Suelta el freno. Devuelve la parada soltada, o null si no había ninguna.
     *
     * @throws \InvalidArgumentException si nadie firma
     */
    public function release(string $by, ?string $note = null): ?UltronAbort
    {
        $by = trim($by);

        if ($by === '') {
            throw new \InvalidArgumentException('Soltar el freno de ULTRON exige un nombre: no se suelta «automáticamente».');
        }

        $abort = $this->current();

        if ($abort === null) {
            return null;
        }

        $abort->forceFill([
            'released_at' => now(),
            'released_by' => $by,
            'release_note' => $note,
        ])->save();

        ChannelLog::warning('ultron.abort.released', [
            'abort_id' => $abort->id,
            'released_by' => $by,
        ]);

        return $abort;
    }
}
