<?php

namespace App\Services\Marketing\Analytics;

/**
 * De dónde saldría el gasto publicitario, el día que lo haya.
 *
 * Hoy NO hay ninguna fuente de gasto en el sistema. Podría haberse resuelto
 * poniendo un cero y calculando un ROAS, y eso sería una mentira con formato de
 * número: un ROAS calculado sobre gasto cero sale infinito o cero según por
 * dónde se mire, y en los dos casos alguien tomaría una decisión de presupuesto
 * con él.
 *
 * Así que el contrato admite explícitamente «no disponible», y el panel enseña
 * «Gasto no disponible» en vez de una cifra. Cuando exista la integración con
 * Meta Ads —que requiere permisos administrativos que hoy no tenemos— bastará
 * con una implementación nueva de esta interfaz: el dominio comercial no se
 * entera.
 */
interface AdvertisingSpendProvider
{
    /**
     * Gasto por dimensión en un período.
     *
     * La clave del array es el identificador de la dimensión (id de campaña, de
     * anuncio…). Devolver un array vacío significa «no hay dato», que NO es lo
     * mismo que «gastó cero».
     *
     * @param  string  $dimension  campaign|adset|ad
     * @return array<string, SpendRecord>
     */
    public function spendFor(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): array;

    /** ¿Hay una fuente de gasto configurada y confiable ahora mismo? */
    public function isAvailable(): bool;

    /** Nombre de la fuente, para poder auditar de dónde salió una cifra. */
    public function sourceName(): string;
}
