<?php

namespace App\Services\Marketing\Ultron;

use App\Jobs\Wompi\ReconcileWompiTransaction;
use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use App\Services\Wompi\WompiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * El estado del pago que el modelo puede afirmar. Sale de las transacciones que
 * el propio CRM creó para este lead (clave de idempotencia lead+plan), nunca de
 * lo que la persona diga ni de una captura. Si dice que pagó y hay una
 * transacción abierta en Wompi, se consulta a Wompi en ese momento: la respuesta
 * es lo que dice la pasarela. Si no hay nada abierto, no hay nada que consultar
 * y la verdad es «no veo un pago iniciado». Sin identidad de socio también vale:
 * la transacción la creó el CRM para este lead, no se adivina por teléfono.
 *
 * ESTO LEE Y NO ESCRIBE. La distinción no es de estilo:
 *
 * `decide` es un endpoint de LECTURA que dispara el texto de un tercero. La
 * primera versión llamaba a `WompiReconciliationService::refresh()`, y esa
 * llamada aplica el estado: transición → activación de membresía → mensaje al
 * cliente → factura electrónica a la DIAN. Es decir, escribir «ya pagué» movía
 * dinero, mandaba un WhatsApp y encolaba un documento fiscal, sin el cerrojo de
 * conversación y con n8n libre de reintentar el turno. Aquí se pregunta el
 * estado y se le cuenta al modelo; SELLAR la transacción sigue siendo del
 * webhook de Wompi y del cron `payments:wompi-reconcile`, que corre cada cinco
 * minutos y es quien lleva `retry_count` y `last_reconciled_at`.
 *
 * Por eso esta clase usa {@see WompiClient::getTransaction()} —un GET— y jamás
 * `applyWompiTransaction`, `transitionTo` ni `PaymentMembershipActivator`.
 */
final class PaymentStatusProvider
{
    private const RECLAMO = '/\b(ya (pague|pago|hice el pago|transferi|consigne|lo pague|quedo pago|realice el pago|hice la transferencia)|te (mando|envio|paso|adjunto) (el|la|mi) (comprobante|captura|soporte)|(ahi|aqui) (esta|va|te va) (el pago|el comprobante|la captura)|listo el pago|pago (hecho|realizado|listo)|acabo de pagar|ya esta pago)\b/u';

    /** Una consulta a Wompi por transacción y minuto; la respuesta vive lo mismo. */
    private const FRENO_CLAVE = 'ultron:wompi-live-check:';

    private const FRENO_SEGUNDOS = 60;

    /** No se pudo saber el estado real. @var array{status:?string,cached:bool} */
    private const SIN_RESPUESTA = ['status' => null, 'cached' => false];

    /** @return array{state:string,plan_id:?int,expires_at:?string,claimed_by_lead:bool,checked_live:bool,live_cached:bool,can_regenerate:bool} */
    public function forLead(?MarketingLead $lead, string $inboundText): array
    {
        $claimed = preg_match(self::RECLAMO, SalesAgentDecisionSchema::normalize($inboundText)) === 1;
        $none = ['state' => 'none', 'plan_id' => null, 'expires_at' => null, 'claimed_by_lead' => $claimed, 'checked_live' => false, 'live_cached' => false, 'can_regenerate' => true];

        if ($lead === null || ! Schema::hasTable('payment_transactions')) {
            return $none;
        }
        $tx = $this->transactionFor($lead, $claimed);
        if ($tx === null) {
            return $none;
        }

        // «Ya pagué» con una transacción abierta en Wompi: se pregunta a Wompi, no
        // se cree. Lo que responda es lo que ve el modelo en ESTE turno; la fila
        // se queda como está hasta que la mueva el webhook o el cron.
        $state = $this->state((string) $tx->status);
        $checked = false;
        $reutilizada = false;

        if ($claimed && in_array((string) $tx->status, SM::IN_FLIGHT, true) && $tx->wompi_transaction_id) {
            $live = $this->liveState($tx);
            if ($live['status'] !== null) {
                $state = $this->state($live['status']);
                $checked = true;
                $reutilizada = $live['cached'];

                // Wompi ya la dio por terminada. La fila se entrega al camino
                // AUDITADO (reconcileOne, con retry_count y last_reconciled_at)
                // en vez de esperar a la pasada del cron: durante esa ventana de
                // hasta cinco minutos la fila sigue en vuelo y el cerrojo
                // `already_paid` de la generación de links no protege.
                //
                // Solo tras una consulta NUEVA: el turno repetido dentro del
                // minuto habla del mismo hecho y ya lo encoló el primero.
                if (! $live['cached'] && (new SM)->isTerminal($live['status'])) {
                    ReconcileWompiTransaction::dispatch((int) $tx->id);
                }
            }
        }

        return [
            'state' => $state,
            'plan_id' => $tx->plan_id ? (int) $tx->plan_id : null,
            'expires_at' => $tx->expires_at?->toIso8601String(),
            'claimed_by_lead' => $claimed,
            'checked_live' => $checked,
            'live_cached' => $reutilizada,
            'can_regenerate' => in_array($state, ['none', 'expired', 'declined'], true),
        ];
    }

    /**
     * La transacción de la que hablar, entre las que el CRM creó para ESTE lead.
     *
     * LO QUE SE ROMPIÓ ANTES. La versión anterior elegía por CATEGORÍA fija —en
     * vuelo, luego aprobada, luego la última— y con eso una intención vieja
     * tapaba un hecho nuevo. El caso real: el lead pide el link del plan A y no
     * lo paga; días después pide el del plan B y LO PAGA. El link de A sigue en
     * vuelo, porque el cerrojo `already_paid` de `WompiPaymentLinkService` es
     * por lead+PLAN y no toca la fila del otro plan. El lead escribe «ya
     * pagué» y el sistema contestaba `pending` con el `plan_id` de A: a quien
     * acababa de pagar se le decía que no consta.
     *
     * EL CRITERIO. Se comparan solo dos candidatas —el último intento en vuelo
     * y el último pago aprobado— y entre ellas:
     *
     *  1. Si la persona RECLAMA que pagó, gana el pago aprobado, sin mirar
     *     fechas. Es la única regla que garantiza lo que no puede fallar: a
     *     quien pagó no se le dice que no. Un intento en vuelo es una
     *     intención y puede quedarse en vuelo para siempre; un `approved` es
     *     un hecho consumado y no se sale de él (ver {@see SM}).
     *  2. Sin reclamo, gana la MÁS RECIENTE, y el aprobado gana los empates.
     *     Sin reclamo la conversación va de lo que está pasando ahora: quien
     *     ya pagó y acaba de pedir un link nuevo está preguntando por el link
     *     nuevo, no por el recibo de la semana pasada.
     *
     * «Más reciente» es por `id`, o sea por orden de CREACIÓN del intento. No
     * por `paid_at`: comparar cuándo se pagó una con cuándo se creó la otra es
     * comparar dos cosas distintas, y el intento abandonado no tiene fecha de
     * desenlace con la que competir.
     *
     * EL PRECIO DE (1), dicho para que nadie lo descubra depurando: si el lead
     * tiene un aprobado viejo y acaba de pagar un intento MÁS NUEVO que el
     * webhook todavía no ha sellado, el reclamo elige el aprobado viejo y el
     * `plan_id` sale desfasado (el `state` no: sigue siendo `approved`). Se
     * acepta a sabiendas —un plan equivocado se corrige en la conversación
     * siguiente, un «no me consta tu pago» se pierde al cliente— y la fila
     * nueva la sella igual el cron `payments:wompi-reconcile`, que pasa cada
     * cinco minutos.
     */
    private function transactionFor(MarketingLead $lead, bool $claimed): ?PaymentTransaction
    {
        $delLead = fn (): Builder => PaymentTransaction::query()
            ->where('idempotency_key', 'like', 'mkt-lead-'.$lead->id.'-plan-%')
            ->orderByDesc('id');

        $enVuelo = $delLead()->whereIn('status', SM::IN_FLIGHT)->first();
        $aprobada = $delLead()->where('status', SM::APPROVED)->first();

        if ($aprobada === null) {
            // Sin pago aprobado no hay nada que proteger: el intento vivo, y si
            // tampoco lo hay, la última fila (vencida o rechazada) para poder
            // decir qué pasó con ella.
            return $enVuelo ?? $delLead()->first();
        }

        if ($enVuelo === null || $claimed) {
            return $aprobada;
        }

        return (int) $enVuelo->id > (int) $aprobada->id ? $enVuelo : $aprobada;
    }

    /**
     * Estado REAL en la pasarela —el de la máquina de estados, no la etiqueta
     * pública—, o null si no se pudo saber (y entonces vale el de la base). No
     * escribe la fila.
     *
     * El freno es por transacción y no por ruta: el throttle del endpoint lo
     * comparte todo el workflow de n8n, así que no dice nada sobre cuántas veces
     * se consulta ESTA transacción. Un turno reintentado no son dos consultas.
     *
     * Y la ventana GUARDA la respuesta en vez de tirarla. Frenar sin guardar
     * hacía que el segundo «ya pagué» del mismo minuto contestara «pendiente»
     * sobre el mismo hecho que un momento antes estaba «aprobado»: el modelo se
     * contradecía por haberse ahorrado una llamada HTTP. Lo que se guarda es un
     * estado y una marca de tiempo, nada de la persona.
     *
     * @return array{status:?string,cached:bool}
     */
    private function liveState(PaymentTransaction $tx): array
    {
        $clave = self::FRENO_CLAVE.$tx->id;

        // `add` es el freno Y es atómico: si dos turnos entran a la vez, uno
        // solo consulta. Quien lo pierde lee lo que dejó el ganador (y mientras
        // no haya dejado nada, el marcador vale null: sin respuesta en vivo se
        // sigue con el estado de la BD, que es la lectura conservadora).
        if (! Cache::add($clave, ['state' => null, 'checked_at' => now()->toIso8601String()], self::FRENO_SEGUNDOS)) {
            $guardado = Cache::get($clave);
            $estado = is_array($guardado) ? ($guardado['state'] ?? null) : null;

            return is_string($estado) ? ['status' => $estado, 'cached' => true] : self::SIN_RESPUESTA;
        }

        try {
            $res = WompiClient::fromConfig()->getTransaction((string) $tx->wompi_transaction_id);
        } catch (Throwable $e) {
            ChannelLog::warning('ultron.payment_status.live_check_failed', [
                'transaction_id' => $tx->id,
                'exception' => class_basename($e),
            ]);

            // El marcador se queda puesto: si Wompi falla, insistir cada turno
            // durante el minuto siguiente no lo arregla y multiplica la carga
            // contra una pasarela que ya está mal.
            return self::SIN_RESPUESTA;
        }

        $status = $res['data']['status'] ?? null;
        if (! ($res['ok'] ?? false) || empty($res['data']['id']) || ! is_string($status)) {
            // Wompi contestó mal o no contestó: se sigue con el estado de la BD.
            // Sin `error` ni cuerpo en el log: el código del fallo basta para
            // diagnosticar y no arrastra datos de nadie.
            ChannelLog::warning('ultron.payment_status.live_check_failed', [
                'transaction_id' => $tx->id,
                'http_status' => (int) ($res['status'] ?? 0),
                'error_code' => $res['error_code'] ?? null,
            ]);

            return self::SIN_RESPUESTA;
        }

        // Mismo mapa que usa la reconciliación (desconocido → pending: una
        // lectura jamás inventa una aprobación).
        $real = (new SM)->mapWompiStatus($status);
        Cache::put($clave, ['state' => $real, 'checked_at' => now()->toIso8601String()], self::FRENO_SEGUNDOS);

        return ['status' => $real, 'cached' => false];
    }

    private function state(string $status): string
    {
        return match (true) {
            $status === SM::APPROVED => 'approved',
            $status === SM::EXPIRED => 'expired',
            in_array($status, [SM::DECLINED, SM::VOIDED, SM::ERROR], true) => 'declined',
            default => 'pending',
        };
    }
}
