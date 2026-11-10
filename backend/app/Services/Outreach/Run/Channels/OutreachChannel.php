<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Run\LiveStrategy;
use DateTimeImmutable;

/**
 * UN CANAL de la ejecución (contrato H9, D9-8 etapa 7, D9-9.1): el adaptador
 * que ejecuta UN trabajo ya reevaluado y en `dispatching`, con UNA petición
 * y sin reintentos automáticos. Devuelve a qué estado final pasa el trabajo
 * (`started`, `simulated`, `failed_to_start` o `ambiguous`; un `ambiguous` no
 * se reintenta: se concilia) y, si lo hay, el código de detalle del proveedor
 * (nunca su texto) y la referencia que la base guarda una vez.
 *
 * Los cuatro canales del conjunto cerrado: `simulated` (nada sale), `assisted`
 * (la tarea para una persona), `whatsapp_template` (W9: su puerta es
 * `MarketingMessageDispatcher::dispatchWhatsappTemplate`, que todavía no
 * existe; aquí solo la puerta VACÍA) y `voice_call` (V9:
 * `OutboundGate::requestForStrategy`, ídem). Ningún adaptador de este espacio
 * nombra a los proveedores (protección 4).
 */
interface OutreachChannel
{
    /** El canal de trabajo (`outreach_jobs.channel`). */
    public function name(): string;

    /** Ejecuta el trabajo (ya `dispatching`). Nunca lanza por un fallo del proveedor: lo devuelve. */
    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt;
}
