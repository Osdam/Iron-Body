<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Ultron\MembershipFactsProvider;
use App\Services\Marketing\Ultron\UltronCommitService;

/**
 * Quién decide que una conversación quede marcada para el equipo.
 *
 * No el modelo. Esto existe por el mismo motivo que {@see HumanHandoffAuthority},
 * y se descubrió igual: en el canario físico, un turno LIMPIO —una pregunta
 * normal, contestada bien, sin queja, sin lesión, sin nadie pidiendo un
 * humano— dejó la conversación marcada para revisión. Nadie la había pedido:
 * bastó con que el modelo escribiera `staff_review` en `tools_requested` para
 * que {@see UltronCommitService::execStaffReview()}
 * levantara la bandera sin mirar si había causa.
 *
 * El prompt lo pedía de buena fe («si te falta un hecho, marca staff_review»,
 * «una pregunta que no sabes responder, la marcas con staff_review»), y esas
 * dos frases convierten cualquier turno en un aviso. Una bandeja donde todo
 * está marcado es una bandeja donde no se mira nada: el coste de marcar de más
 * no es una fila, es que la queja de verdad se pierda entre el ruido.
 *
 * Se separan las dos cosas que estaban confundidas:
 *
 *   INTENCIÓN  el modelo cree que aquí hace falta alguien — lo propone.
 *   ACCIÓN     la bandera se levanta — la decide Laravel, y sólo con causa.
 *
 * La causa puede venir de dos sitios, y sólo de esos dos:
 *
 *  1. DEL BACKEND. Ya la calculó {@see SalesAgentDecisionValidator}: una
 *     intención sensible de {@see SalesIntents::STAFF_REVIEW_INTENTS}, un
 *     intento de acción prohibida o una afirmación insegura. Ahí no se
 *     pregunta nada: el backend lo sabe y lo afirma.
 *  2. DEL MODELO, y sólo un motivo: una operación que el asistente no ejecuta
 *     jamás ({@see MembershipFactsProvider::TEAM_ONLY}:
 *     pausar, cancelar el cobro, devolución, factura, cambio de plan a mitad
 *     de periodo, traspaso). Es el único caso donde de verdad tiene que actuar
 *     una persona y el backend no tiene ninguna otra señal para saberlo.
 *
 * Todo lo demás que proponga el modelo se registra y se descarta. Que no se
 * levante la bandera no es que el turno se pierda: la fila de la acción, sus
 * `risk_flags` y el log siguen contando lo que pasó.
 */
final class StaffReviewAuthority
{
    /** Una operación sobre la cuenta que el asistente no ejecuta nunca. */
    public const TEAM_ONLY_OPERATION = 'team_only_operation';

    /**
     * Motivos que el MODELO puede proponer. Sólo uno.
     *
     * Los demás motivos describen hechos sobre la persona o sobre el turno
     * —que se quejó, que mencionó una lesión, que reclamó un pago, que pidió
     * un humano—, y todos ésos los calcula el backend leyendo el mensaje. Si
     * el modelo pudiera afirmarlos, volveríamos al cheque en blanco: bastaba
     * escribir la cadena para marcar la conversación.
     */
    public const MODEL_PROPOSABLE = [self::TEAM_ONLY_OPERATION];

    /**
     * El motivo que le corresponde a cada intención sensible.
     *
     * Vive aquí y no repartido en un `match` porque hay DOS sitios que lo
     * necesitan: {@see SalesAgentDecisionValidator}, cuando ensambla la
     * decisión normal, y {@see UltronCommitService::safeFallback()},
     * cuando el turno se rinde y el texto curado sale en lugar del borrador.
     * Dos copias de esta tabla es una copia de más: la primera vez que se
     * separen, una de las dos marcará con el motivo equivocado.
     *
     * Las claves son, y tienen que seguir siendo, {@see SalesIntents::STAFF_REVIEW_INTENTS}.
     */
    public const POR_INTENCION = [
        SalesIntents::MEDICAL_RISK_ESCALATION => 'medical_case',
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM => 'payment_or_fraud_claim',
        SalesIntents::HUMAN_REQUEST => 'human_requested',
        SalesIntents::COMPLAINT => 'complaint',
        SalesIntents::INVOICE_REQUEST => 'invoice_request',
    ];

    /** El motivo de una intención sensible, o null si la intención no lo es. */
    public static function motivoDeIntencion(?string $intent): ?string
    {
        return is_string($intent) ? (self::POR_INTENCION[$intent] ?? null) : null;
    }

    /**
     * Cuando el backend no da un motivo propio pero sí afirma que hace falta
     * revisión, la bandera se levanta igual con esta etiqueta genérica. No
     * perder una escalada real por una discrepancia de vocabulario importa
     * más que la pulcritud del nombre.
     */
    public const MOTIVO_GENERICO = 'escalation';

    /**
     * ¿Se marca esta conversación para el equipo?
     *
     * @param  bool  $causaDelBackend  si el backend ya afirmó que hace falta revisión
     * @param  ?string  $motivoDelBackend  el motivo que afirmó, si lo dijo
     * @param  ?string  $motivoPropuesto  lo que pide el modelo, cuando el backend no pide nada
     * @return array{allowed:bool, reason:?string, source:?string, refusal:?string}
     */
    public function decide(bool $causaDelBackend, ?string $motivoDelBackend, ?string $motivoPropuesto): array
    {
        if ($causaDelBackend) {
            $motivo = is_string($motivoDelBackend) ? trim($motivoDelBackend) : '';

            return [
                'allowed' => true,
                'reason' => $motivo === '' ? self::MOTIVO_GENERICO : $motivo,
                'source' => 'backend',
                'refusal' => null,
            ];
        }

        return $this->decideProposal($motivoPropuesto);
    }

    /**
     * Lo mismo, pero para un motivo que PROPONE el modelo.
     *
     * La diferencia con {@see decide()} es quién habla: aquí no hay ningún
     * hecho del backend detrás, sólo una etiqueta que llegó desde n8n. Por eso
     * la lista es de uno.
     *
     * @return array{allowed:bool, reason:?string, source:?string, refusal:?string}
     */
    public function decideProposal(?string $motivoPropuesto): array
    {
        $motivo = is_string($motivoPropuesto) ? trim(strtolower($motivoPropuesto)) : '';

        if ($motivo === '') {
            return $this->no('staff_review_reason_missing');
        }

        if (! in_array($motivo, self::MODEL_PROPOSABLE, true)) {
            return $this->no('staff_review_reason_not_proposable_by_model');
        }

        return ['allowed' => true, 'reason' => $motivo, 'source' => 'model', 'refusal' => null];
    }

    /** @return array{allowed:bool, reason:?string, source:?string, refusal:string} */
    private function no(string $motivo): array
    {
        return ['allowed' => false, 'reason' => null, 'source' => null, 'refusal' => $motivo];
    }
}
