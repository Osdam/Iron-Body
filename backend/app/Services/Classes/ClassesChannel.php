<?php

namespace App\Services\Classes;

use Closure;

/**
 * Lo que decide, latido a latido, qué recibe una conexión del canal de clases
 * del CRM. El stream SSE solo lo escribe; así se puede probar sin abrir uno.
 *
 *   1. Los hechos tipados del registro, con id, sin repetir y sin perder los
 *      que confirman tarde ({@see ClassEventsFeed::pending()}).
 *   2. La huella `classes`: red de seguridad para lo que no pasa por el
 *      registro (borrados en cascada, cambios directos en la base, un registro
 *      que no se pudo escribir) y para las pestañas del CRM anterior, que solo
 *      escuchan `classes`.
 *
 * `proto=2` es el CRM que entiende los hechos. A ese no se le manda la huella
 * de un cambio que ya le llegó como hecho en el mismo latido: la tomaría por
 * «todas las clases» y releería de más. La huella se lee DESPUÉS de los hechos
 * y el hecho se confirma junto con su cambio, así que lo que la huella ve en un
 * latido con hechos ya se avisó. Queda una ventana de milisegundos entre las
 * dos lecturas: un cambio que se confirme justo ahí puede avisarse dos veces o,
 * si no tiene hecho y coincide con otro, quedar sin huella hasta el siguiente.
 *
 * Por el mismo canal viajan los hechos de personal, jornadas, biometría y
 * dispositivos (`class_events.audience`). Cada conexión recibe SOLO los que su
 * permiso le deja ver; los demás avanzan el cursor sin emitirse, para que no
 * se queden ocupando la ventana de lectura de cada latido.
 */
final class ClassesChannel
{
    private int $cursor;

    private ?string $huella = null;

    /** @var array<int, true> ids ya emitidos en esta conexión */
    private array $enviados = [];

    /**
     * @param  int  $suelo  cursor con el que abrió la conexión
     * @param  Closure(): string  $firma  la huella del módulo de clases
     * @param  (Closure(string): bool)|null  $puedeVer  ¿esta conexión tiene el permiso? null = todo (clases de siempre)
     */
    public function __construct(
        private readonly int $suelo,
        private readonly bool $soloNoCubiertos,
        private readonly Closure $firma,
        private readonly ?Closure $puedeVer = null,
    ) {
        $this->cursor = $suelo;
    }

    /** Permiso que exige un hecho: el suyo o, si no declara, el de clases. */
    private function permite(?string $audience): bool
    {
        return $this->puedeVer === null || ($this->puedeVer)($audience ?? 'classes.view');
    }

    /**
     * Lo que hay que emitir en este latido, en orden.
     *
     * @return list<array{event: string, data: array<string, mixed>, id: int|null}>
     */
    public function tick(): array
    {
        $salida = [];
        $hechos = 0;
        foreach (ClassEventsFeed::pending($this->suelo, $this->cursor, $this->enviados) as $evento) {
            $id = (int) $evento->id;
            $this->enviados[$id] = true;
            $this->cursor = max($this->cursor, $id);
            if (! $this->permite($evento->audience)) {
                continue;
            }
            $salida[] = ['event' => $evento->type, 'data' => ClassEventsFeed::payload($evento), 'id' => $id];
            if ($evento->audience === null) {
                $hechos++;
            }
        }

        // Sin permiso de clases, la huella de clases no le dice nada.
        if (! $this->permite(null)) {
            return $salida;
        }

        $ahora = ($this->firma)();
        if ($this->huella === null) {
            $this->huella = $ahora; // primer latido: línea base, no dispara

            return $salida;
        }
        if ($ahora !== $this->huella) {
            $this->huella = $ahora;
            if (! $this->soloNoCubiertos || $hechos === 0) {
                $salida[] = ['event' => 'classes', 'data' => ['sig' => $ahora], 'id' => null];
            }
        }

        return $salida;
    }
}
