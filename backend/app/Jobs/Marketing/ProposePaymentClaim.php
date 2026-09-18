<?php

namespace App\Jobs\Marketing;

use App\Models\Member;
use App\Services\Marketing\ApprovedPaymentClaimer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Saca de la petición de registro la propuesta de enlace del pago.
 *
 * Proponer termina en una salida por WhatsApp hacia Meta (hasta 20 s de
 * timeout) y en escrituras de alerta interna; nada de eso puede colgar del
 * `POST /members/register` que hace la app, cuyo cliente tiene su propio
 * timeout. En producción la cola es `database` y corre en los workers; en
 * tests es `sync` y el comportamiento observable es el mismo.
 *
 * Un solo intento: la propuesta es accesoria y el propio claimer no lanza.
 */
class ProposePaymentClaim implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $memberId) {}

    public function handle(ApprovedPaymentClaimer $claimer): void
    {
        $member = Member::query()->find($this->memberId);
        if ($member === null) {
            return;
        }

        $claimer->proposeFor($member);
    }
}
