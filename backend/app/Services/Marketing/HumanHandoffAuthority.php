<?php

namespace App\Services\Marketing;

/**
 * Quién decide que una conversación pase a una persona.
 *
 * No el modelo. Esto existe porque en una conversación real el asesor derivó
 * cuatro veces seguidas: cuando le preguntaron por los planes, cuando el
 * prospecto dijo «sí por favor» a una explicación que el propio asesor había
 * ofrecido, cuando preguntaron cuántos entrenadores hay —dato que el CRM
 * tiene— y, lo más grave, justo después de que la persona escribiera «no
 * quiero alguien del equipo». Bastaba con que el modelo escribiera la cadena
 * `human_request` para que el sistema escalara: nadie miraba el mensaje.
 *
 * Aquí se separan dos cosas que estaban confundidas:
 *
 *   INTENCIÓN  lo que parece querer la persona — la propone el modelo.
 *   ACCIÓN     lo que el sistema hace — la decide Laravel, y sólo con causa.
 *
 * Una intención no autoriza nada. Para derivar hacen falta un motivo de la
 * lista y, cuando el motivo es «lo pidió», la prueba de que lo pidió de verdad.
 *
 * Derivar no es gratis: rompe la conversación, obliga a esperar a alguien y le
 * dice al prospecto que el sistema no puede ayudarle. Por eso es la excepción.
 */
final class HumanHandoffAuthority
{
    /** Lo pidió con sus palabras. Exige corroboración en el mensaje. */
    public const EXPLICIT_HUMAN_REQUEST = 'explicit_human_request';

    /** Un pago que el sistema no puede resolver ni consultar. */
    public const UNRESOLVABLE_PAYMENT_INCIDENT = 'unresolvable_payment_incident';

    /** Una operación sobre la cuenta que el asesor no puede ejecutar. */
    public const UNRESOLVABLE_ACCOUNT_OPERATION = 'unresolvable_account_operation';

    /** Una queja formal que necesita respuesta de una persona. */
    public const FORMAL_COMPLAINT_REQUIRING_HUMAN = 'formal_complaint_requiring_human';

    /** Salud, menores, datos personales: la política obliga. */
    public const POLICY_REQUIRED_ESCALATION = 'policy_required_escalation';

    /** Seguir solo implicaría inventarse algo que el negocio no tiene. */
    public const CRITICAL_CAPABILITY_NOT_AVAILABLE = 'critical_capability_not_available';

    public const ALLOWED_REASONS = [
        self::EXPLICIT_HUMAN_REQUEST,
        self::UNRESOLVABLE_PAYMENT_INCIDENT,
        self::UNRESOLVABLE_ACCOUNT_OPERATION,
        self::FORMAL_COMPLAINT_REQUIRING_HUMAN,
        self::POLICY_REQUIRED_ESCALATION,
        self::CRITICAL_CAPABILITY_NOT_AVAILABLE,
    ];

    /**
     * Motivos que no se creen sin ver el mensaje.
     *
     * Sólo uno: «lo pidió» es el único motivo que afirma algo sobre lo que la
     * persona dijo, y por tanto el único comprobable leyendo lo que dijo. Los
     * demás describen límites del sistema, y esos los conoce el backend.
     */
    public const NEEDS_CORROBORATION = [self::EXPLICIT_HUMAN_REQUEST];

    /**
     * Pedir hablar con una persona, dicho como se dice de verdad por WhatsApp.
     *
     * Van sin tildes a propósito: el texto se normaliza antes de comparar,
     * porque nadie acentúa escribiendo desde el móvil.
     */
    private const PIDE_HUMANO = [
        '/\bhablar\s+con\s+(un[ao]?\s+)?(persona|asesor|humano|agente|alguien|recepcion|encargad)/u',
        '/\b(pasame|pasarme|pasame|paseme|pasenme|pasar)\s+(con|a)\s+(un[ao]?\s+)?(persona|asesor|humano|agente|alguien|recepcion)/u',
        '/\b(comunicame|comunicarme|comuniqueme)\s+con\b/u',
        '/\bque\s+me\s+(atienda|contacte|llame|escriba)\s+(un[ao]?\s+)?(persona|asesor|humano|agente|alguien)/u',
        '/\b(quiero|necesito|deseo|prefiero)\s+(hablar\s+con\s+)?(un[ao]?\s+)?(asesor|humano|persona real|alguien\s+del\s+equipo|agente)/u',
        '/\batencion\s+(humana|personalizada\s+de\s+una\s+persona)/u',
        '/\bcon\s+(un[ao]?\s+)?(persona|humano)\s+real\b/u',
    ];

    /**
     * Lo que convierte la petición en su contrario.
     *
     * Se buscan sólo en el tramo que precede a la petición, no en todo el
     * mensaje: «no me interesa el plan pero quiero hablar con un asesor» sí es
     * una petición, y una negación suelta al principio no debería anularla.
     */
    private const NIEGA = [
        'no ', 'ni ', 'nunca ', 'sin ', 'tampoco ', 'evitar ', 'prefiero no ', 'jamas ',
    ];

    /** Cuántos caracteres antes de la petición se miran buscando la negación. */
    private const VENTANA_NEGACION = 32;

    /**
     * ¿Puede esta conversación pasar a una persona?
     *
     * @param  ?string  $motivoPropuesto  lo que pide el modelo
     * @param  ?string  $mensajeEntrante  lo que la persona escribió DE VERDAD
     * @return array{allowed:bool, reason:?string, evidence:?string, refusal:?string}
     */
    public function decide(?string $motivoPropuesto, ?string $mensajeEntrante): array
    {
        $motivo = is_string($motivoPropuesto) ? trim(strtolower($motivoPropuesto)) : '';

        if ($motivo === '') {
            return $this->no('handoff_reason_missing');
        }

        if (! in_array($motivo, self::ALLOWED_REASONS, true)) {
            return $this->no('handoff_reason_not_allowed');
        }

        if (! in_array($motivo, self::NEEDS_CORROBORATION, true)) {
            // Describe un límite del sistema, no una afirmación sobre la
            // persona: quien lo sabe es el backend, y ya lo ha dicho.
            return ['allowed' => true, 'reason' => $motivo, 'evidence' => null, 'refusal' => null];
        }

        $prueba = $this->peticionDeHumanoEn((string) $mensajeEntrante);

        if ($prueba === null) {
            return $this->no('handoff_not_corroborated');
        }

        return ['allowed' => true, 'reason' => $motivo, 'evidence' => $prueba, 'refusal' => null];
    }

    /**
     * El fragmento donde la persona pide un humano, o null.
     *
     * Devuelve el trozo citable en vez de un booleano para que quede constancia
     * de POR QUÉ se derivó: un escalado sin prueba en el expediente es
     * indistinguible de un escalado inventado.
     */
    public function peticionDeHumanoEn(?string $texto): ?string
    {
        $t = $this->normalizar((string) $texto);
        if ($t === '') {
            return null;
        }

        foreach (self::PIDE_HUMANO as $patron) {
            if (preg_match($patron, $t, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $posicion = (int) $m[0][1];
            if ($this->estaNegada($t, $posicion)) {
                continue; // «no quiero hablar con nadie» no es pedir hablar con alguien.
            }

            return trim($m[0][0]);
        }

        return null;
    }

    /** ¿Hay una negación justo antes de la petición? */
    private function estaNegada(string $texto, int $posicion): bool
    {
        $desde = max(0, $posicion - self::VENTANA_NEGACION);
        $antes = substr($texto, $desde, $posicion - $desde);

        foreach (self::NIEGA as $marca) {
            if (str_contains($antes, $marca)) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculas y sin tildes: nadie acentúa escribiendo por WhatsApp. */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** @return array{allowed:bool, reason:?string, evidence:?string, refusal:string} */
    private function no(string $motivo): array
    {
        return ['allowed' => false, 'reason' => null, 'evidence' => null, 'refusal' => $motivo];
    }
}
