<?php

namespace App\Services\Marketing;

/**
 * Quién puede volver a abrir un contacto que la persona cerró.
 *
 * `do_not_contact = true` era, hasta ahora, una puerta sin manilla por dentro:
 * se escribía en dos sitios del código y ninguno lo ponía nunca en false. No
 * había endpoint, ni servicio, ni comando. Marcar a alguien por equivocación
 * no era cerrarle una puerta que alguien pudiera reabrir; era cerrarla y tirar
 * la llave, y la única salida era un UPDATE a mano contra producción.
 *
 * Esta clase es la manilla, y es deliberadamente dura de girar. La asimetría
 * es a propósito y va en el sentido que protege a la persona:
 *
 *   CERRAR   basta con que lo pida. Es un derecho que ejerce sobre la máquina.
 *   ABRIR    hace falta que lo pida ELLA con sus palabras, o que una persona
 *            del equipo lo autorice con un motivo escrito. Nunca se deduce, y
 *            nunca ocurre como efecto secundario de otra cosa.
 *
 * No escribe nada: decide. Quien escribe es {@see MarketingConsentService}, y
 * es el único sitio del producto por donde se reabre un contacto.
 */
final class ConsentAuthority
{
    /** Lo pidió la propia persona, con sus palabras, y la cita queda en el acta. */
    public const SOURCE_LEAD_RECONSENT = 'lead_explicit_reconsent';

    /** Lo autorizó alguien del equipo, con su motivo escrito. */
    public const SOURCE_ADMIN = 'admin_action';

    public const SOURCES = [self::SOURCE_LEAD_RECONSENT, self::SOURCE_ADMIN];

    /**
     * Cómo pide alguien, de verdad, que le vuelvan a escribir.
     *
     * Van sin tildes porque el texto se normaliza antes de comparar, y son
     * pocas a propósito. Los dos errores no cuestan lo mismo: no reconocer un
     * re-consentimiento deja a alguien sin respuesta hasta que lo diga de otra
     * forma —molesto, y recuperable en el mensaje siguiente—; reconocer uno que
     * no existe reabre el contacto de quien pidió que lo cerraran, que es
     * exactamente el daño que el opt-out existe para impedir.
     *
     * Por eso TODAS exigen un verbo de intención —querer, poder, aceptar,
     * autorizar, volver— pegado a la acción de escribir o contactar. «Me
     * escribieron ayer» o «escriban al otro numero» no son permisos.
     */
    private const REABRE = [
        // «si quiero que me escriban», «quiero que me contacten»
        '/\b(quiero|acepto|autorizo)\s+(que\s+)?(me\s+)?(vuelvan\s+a\s+)?(escrib\w+|contact\w+|llam\w+|mandar\w*\s+informacion)\b/u',
        // «pueden escribirme», «ya me pueden contactar», «si me pueden escribir»
        '/\b(me\s+)?pued(en|es)\s+(volver\s+a\s+)?(escribir|contactar|llamar)(me)?\b/u',
        // «vuelvan a escribirme», «vuelva a contactarme»
        '/\bvuelv\w+\s+a\s+(escribir|contactar|llamar)(me)?\b/u',
        // «quiero recibir informacion», «si acepto recibir promociones»
        '/\b(quiero|acepto|autorizo)\s+recibir\s+(informacion|info|mensajes?|promociones)\b/u',
        // «reactiven mi contacto», «reactivar las notificaciones»
        '/\breactiv\w+\s+(mi\s+)?(contacto|suscripcion|notificaciones)\b/u',
    ];

    /**
     * Lo que ANULA la petición si aparece justo antes.
     *
     * La trampa de este guard es que la frase contraria CONTIENE a la frase
     * buena: «ya no quiero que me escriban» lleva dentro «quiero que me
     * escriban». Sin esto, el mensaje que cierra el contacto lo abriría.
     */
    private const NIEGA = [
        'no ', 'ni ', 'nunca ', 'sin ', 'tampoco ', 'jamas ', 'deje de ', 'dejen de ',
        'paren de ', 'prefiero no ', 'ya no ',
    ];

    /** Cuántos caracteres antes de la petición se miran buscando la negación. */
    private const VENTANA_NEGACION = 32;

    /**
     * El fragmento donde la persona pide que le vuelvan a escribir, o null.
     *
     * Devuelve el trozo citable y no un booleano por la misma razón que el
     * traspaso: una reapertura sin prueba en el expediente es indistinguible de
     * una reapertura inventada.
     */
    public function reconsentimientoEn(?string $texto): ?string
    {
        $t = $this->normalizar((string) $texto);
        if ($t === '') {
            return null;
        }

        foreach (self::REABRE as $patron) {
            if (preg_match($patron, $t, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            if ($this->estaNegada($t, (int) $m[0][1])) {
                continue;
            }

            /*
             * Y lo que va DETRÁS también cuenta, que es el agujero que ya nos
             * costó tres rondas en el guard de pagos: mirar sólo hacia atrás.
             *
             * «Me pueden escribir al correo, por WhatsApp no» reabría
             * justamente el canal excluido. «Quiero que me escriban sólo por
             * correo» igual. La restricción llega después de la petición
             * porque así se habla: primero se concede, luego se acota.
             *
             * Aquí no se intenta entender la restricción —qué canal, qué
             * horario, qué condición—: eso es adivinar. Se hace lo único
             * honesto con una petición que uno no entiende del todo, que es NO
             * ejecutarla sola. La vía administrativa sigue abierta, y ahí una
             * persona lee la frase entera y firma.
             */
            if ($this->restringidaDespues($t, (int) $m[0][1] + strlen($m[0][0]))) {
                continue;
            }

            return trim($m[0][0]);
        }

        return null;
    }

    /**
     * ¿Se puede reabrir este contacto, y con qué constancia?
     *
     * @param  string  $source  una de {@see SOURCES}
     * @param  ?string  $reason  el motivo escrito; obligatorio siempre
     * @param  ?string  $texto  lo que la persona escribió, cuando la vía es suya
     * @return array{allowed:bool, source:?string, evidence:?string, refusal:?string}
     */
    public function decideReopen(string $source, ?string $reason, ?string $texto = null): array
    {
        if (! in_array($source, self::SOURCES, true)) {
            return $this->no('consent_source_not_allowed');
        }

        if (trim((string) $reason) === '') {
            return $this->no('consent_reason_missing');
        }

        if ($source === self::SOURCE_ADMIN) {
            // El backend no puede corroborar nada aquí: la prueba es que una
            // persona firma el motivo. Por eso el actor es obligatorio en el
            // servicio, no aquí.
            return ['allowed' => true, 'source' => $source, 'evidence' => null, 'refusal' => null];
        }

        $prueba = $this->reconsentimientoEn($texto);
        if ($prueba === null) {
            return $this->no('reconsent_not_corroborated');
        }

        return ['allowed' => true, 'source' => $source, 'evidence' => $prueba, 'refusal' => null];
    }

    /**
     * Restricciones que acotan lo que la persona acaba de conceder.
     *
     * `solo` y `unicamente` no niegan: limitan. Y una petición limitada a algo
     * que no sabemos leer no se ejecuta automáticamente.
     */
    private const RESTRINGE = ['no', 'nunca', 'jamas', 'tampoco', 'solo', 'unicamente', 'salvo', 'excepto', 'menos'];

    /** ¿La misma frase acota, después, lo que se acaba de conceder? */
    private function restringidaDespues(string $texto, int $desde): bool
    {
        $resto = substr($texto, $desde);

        // Sólo la misma frase: lo que venga tras un punto es otra cosa.
        foreach (['.', '!', '?', ';'] as $fin) {
            $corte = strpos($resto, $fin);
            if ($corte !== false) {
                $resto = substr($resto, 0, $corte);
            }
        }

        foreach (self::RESTRINGE as $marca) {
            if (preg_match('/\b'.preg_quote($marca, '/').'\b/u', $resto) === 1) {
                return true;
            }
        }

        return false;
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

    /** @return array{allowed:bool, source:?string, evidence:?string, refusal:string} */
    private function no(string $motivo): array
    {
        return ['allowed' => false, 'source' => null, 'evidence' => null, 'refusal' => $motivo];
    }
}
