<?php

namespace App\Services\Outreach\Facts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C9-2 · Adaptador de SOLO LECTURA del LIBRO DE AUTORIZACIONES DE CONTACTO
 * (`outreach_consents`, C9-1; contrato H9, D9-15, Enmienda C9). Da sus
 * propias entradas de política ({@see FactNames::C9_POLICY}), NUNCA
 * `consent.whatsapp.*`: la foto mezcla la política de los adaptadores con
 * `[...$a, ...$b]` y ganaría el último. La combinación con el prospecto la
 * hace {@see CoreProjection::consentFor()}.
 *
 * LA TANDA: UNA consulta por lote (en tramos de {@see MAX_KEYS_PER_QUERY}
 * claves), la de referencia de database-specialist
 * (`OutreachConsentFixtures::CONSENT_BATCH_SQL`, informe-db-consent §4) escrita
 * con subconsultas en vez de `WITH` (lo que lee la guarda de solo lectura
 * empieza por SELECT y solo nombra tablas reales). Cada persona va con TODAS
 * sus claves (su `m:` y sus `l:`: la base no sabe que son la misma). Por
 * persona y finalidad gana el ÚLTIMO evento, con estas reglas:
 *  - un `granted` cuenta en la posición de SU PETICIÓN: lo que se pidió antes
 *    de una retirada no revive por confirmarse después (tampoco con la
 *    retirada en la otra clave de la persona);
 *  - a igual posición, gana `revoked`;
 *  - lo redactado (1581) no casa: su clave es `x:<id>`;
 *  - sin filas, ninguna autorización ni retirada.
 *
 * EL NÚMERO: una autorización es para UN número (su `phone_ref`). Solo vale si
 * coincide con el número al que se escribiría HOY por WhatsApp
 * ({@see whatsappDestination()}: la regla de `LeadFacts` y de
 * `WhatsappRecipient::leadFor()`). Un número cambiado deja la autorización sin
 * efecto (ni concedida ni retirada: falta). Tener el teléfono no autoriza nada.
 *
 *  - `c9.whatsapp.<finalidad>.revoked` ⇔ el último evento es una retirada.
 *  - `c9.whatsapp.<finalidad>.granted` ⇔ el último evento es una confirmación
 *    y su número es el de destino de hoy.
 *  - Los dos false = ninguna (no hay filas, o el número no coincide). Nunca los dos true.
 *
 * Dos booleanos y no un enumerado: la política de la foto es `array<string,
 * bool>` (`BatchSnapshot::fromArray()` lo exige) y `SubjectFacts::policy()`
 * devuelve `?bool`, que convertiría «revoked» en true.
 *
 * SIN LA TABLA (código desplegado antes que la migración C9-1): no hay ningún
 * evento que leer, así que todo es false y la decisión es la de antes. Se
 * detecta una vez por proceso. Un fallo al LEER la tabla no se traga: la foto
 * falla entera (falla cerrado).
 */
final class OutreachConsentFacts
{
    public const TABLES = ['outreach_consents', 'marketing_conversations'];

    /** No da hechos escalares de la foto: solo política. */
    public const FACTS = [];

    public const POLICY = FactNames::C9_POLICY;

    public const TABLE = 'outreach_consents';

    /** El único canal del libro (la voz sigue en `voice_consents`, D8-18). */
    public const CHANNEL = 'whatsapp';

    /** Las finalidades del libro (`ocon_purpose_chk`), las de D9-15. */
    public const PURPOSES = ['collections', 'commercial', 'service'];

    /** Claves (dos parámetros cada una) por sentencia: un lote normal (≤ 500 personas) cabe en una. */
    public const MAX_KEYS_PER_QUERY = 1000;

    /** @var array<string, bool> por conexión */
    private static array $ledger = [];

    /**
     * @return array<string, array<string, bool>> por clave pedida, todas las claves de {@see POLICY}
     */
    public function facts(FactPass $b): array
    {
        $salida = [];
        $personas = [];
        foreach ($b->subjects as $clave => $s) {
            $salida[$clave] = array_fill_keys(self::POLICY, false);
            if ($s->resolved()) {
                $personas[(string) $clave] = self::keysOf($s);
            }
        }
        if ($personas === [] || ! $this->hasLedger()) {
            return $salida;
        }

        $ganadores = self::batch($personas);
        if ($ganadores === []) {
            return $salida;
        }

        // El número de destino, solo de quien tiene alguna confirmación ganadora (una sentencia más, por lote).
        $conConfirmacion = [];
        foreach ($ganadores as $persona => $porFinalidad) {
            foreach ($porFinalidad as $g) {
                if ($g['kind'] === 'granted') {
                    $conConfirmacion[$persona] = $b->subjects[$persona];
                }
            }
        }
        $destino = $conConfirmacion === [] ? [] : self::destinationRefs($b, $conConfirmacion);

        foreach ($ganadores as $persona => $porFinalidad) {
            foreach ($porFinalidad as $finalidad => $g) {
                if ($g['kind'] === 'revoked') {
                    $salida[$persona]["c9.whatsapp.{$finalidad}.revoked"] = true;
                } elseif ($g['kind'] === 'granted' && $g['phone_ref'] !== null && ($destino[$persona] ?? null) !== null
                    && hash_equals((string) $destino[$persona], $g['phone_ref'])) {
                    $salida[$persona]["c9.whatsapp.{$finalidad}.granted"] = true;
                }
            }
        }

        return $salida;
    }

    /**
     * Todas las claves de una persona resuelta: sus fichas de socio y sus prospectos.
     *
     * @return list<string>
     */
    public static function keysOf(Subject $s): array
    {
        $claves = [];
        foreach ($s->memberIds as $id) {
            $claves[] = SubjectKey::member((int) $id);
        }
        foreach ($s->leadIds as $id) {
            $claves[] = SubjectKey::lead((int) $id);
        }

        return array_values(array_unique($claves));
    }

    /**
     * EL ÚLTIMO EVENTO por persona y finalidad (la consulta de la tanda).
     *
     * @param  array<string, list<string>>  $personas  persona → sus claves
     * @return array<string, array<string, array{kind: string, phone_ref: ?string, id: int}>> persona → finalidad → ganador
     */
    public static function batch(array $personas): array
    {
        $tramos = [];
        $tramo = [];
        $enTramo = 0;
        foreach ($personas as $persona => $claves) {
            if ($claves === []) {
                continue;
            }
            if ($tramo !== [] && $enTramo + count($claves) > self::MAX_KEYS_PER_QUERY) {
                $tramos[] = $tramo;
                $tramo = [];
                $enTramo = 0;
            }
            $tramo[(string) $persona] = $claves;
            $enTramo += count($claves);
        }
        if ($tramo !== []) {
            $tramos[] = $tramo;
        }

        $salida = [];
        foreach ($tramos as $t) {
            $valores = [];
            $bindings = [];
            foreach ($t as $persona => $claves) {
                foreach ($claves as $clave) {
                    $valores[] = '(?, ?)';
                    array_push($bindings, (string) $persona, (string) $clave);
                }
            }
            foreach (DB::select(str_replace('{values}', implode(', ', $valores), self::BATCH_SQL), $bindings) as $f) {
                $salida[(string) $f->person][(string) $f->purpose] = [
                    'kind' => (string) $f->kind,
                    'phone_ref' => $f->phone_ref === null ? null : trim((string) $f->phone_ref),
                    'id' => (int) $f->id,
                ];
            }
        }

        return $salida;
    }

    /**
     * El número al que se escribiría HOY por WhatsApp, normalizado: el del
     * prospecto MÁS RECIENTE de la persona con conversación de WhatsApp y un
     * número que la puerta sabe normalizar (la regla de `LeadFacts` para
     * `subject.has_whatsapp_identity` y de `WhatsappRecipient::leadFor()`).
     * null si no tiene identidad de WhatsApp.
     *
     * @param  array<int, object>  $leadRows  filas de `marketing_leads` (`FactPass::leadRows()`)
     * @param  array<int, true>  $whatsappLeads  prospectos con alguna conversación de WhatsApp
     */
    public static function whatsappDestination(Subject $s, array $leadRows, array $whatsappLeads): ?string
    {
        $ids = array_map('intval', $s->leadIds);
        sort($ids);
        $elegido = null;
        foreach ($ids as $id) {
            $n = isset($leadRows[$id], $whatsappLeads[$id]) ? PhoneKey::normalize($leadRows[$id]->phone) : null;
            if ($n !== null) {
                $elegido = $n;
            }
        }

        return $elegido;
    }

    /**
     * Los prospectos (de esta lista) con alguna conversación de WhatsApp. Una sentencia.
     *
     * @param  list<int>  $leadIds
     * @return array<int, true>
     */
    public static function whatsappLeads(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        return array_fill_keys(DB::table('marketing_conversations')->whereIn('lead_id', $leadIds)->where('channel', self::CHANNEL)
            ->distinct()->pluck('lead_id')->map(static fn ($id): int => (int) $id)->all(), true);
    }

    /** ¿Existe el libro en esta conexión? Una vez por proceso. */
    public function hasLedger(): bool
    {
        return self::$ledger[DB::connection()->getName()] ??= Schema::hasTable(self::TABLE);
    }

    /** Olvida la detección (pruebas que crean o quitan la tabla dentro del mismo proceso). */
    public static function forgetLedger(): void
    {
        self::$ledger = [];
    }

    /**
     * El seudónimo del número de destino de cada persona (o null).
     *
     * @param  array<string, Subject>  $personas
     * @return array<string, ?string>
     */
    private static function destinationRefs(FactPass $b, array $personas): array
    {
        $leads = [];
        foreach ($personas as $s) {
            foreach ($s->leadIds as $id) {
                $leads[(int) $id] = true;
            }
        }
        $filas = $b->leadRows();
        $conWhatsapp = self::whatsappLeads(array_keys($leads));
        $clave = SubjectRef::appKey();
        $salida = [];
        foreach ($personas as $persona => $s) {
            $numero = self::whatsappDestination($s, $filas, $conWhatsapp);
            $salida[$persona] = $numero === null ? null : PhoneRef::withKey($numero, $clave);
        }

        return $salida;
    }

    /**
     * La consulta de la tanda (informe-db-consent §4), en los dos motores:
     * VALUES (columnas `column1` = persona y `column2` = clave en PG y en
     * SQLite) y ventana; sin `WITH`, DISTINCT ON ni unnest. `{values}` =
     * `(?, ?), (?, ?), …`. Usa `ocon_subject_idx` (cubridor en PG).
     */
    private const BATCH_SQL = <<<'SQL'
        SELECT person, purpose, channel, kind, phone_ref, id
          FROM (
            SELECT ev.person, ev.purpose, ev.channel, ev.kind, ev.phone_ref, ev.id,
                   ROW_NUMBER() OVER (PARTITION BY ev.person, ev.purpose, ev.channel
                                      ORDER BY ev.pos DESC, CASE WHEN ev.kind = 'revoked' THEN 0 ELSE 1 END) AS n
              FROM (
                SELECT s.column1 AS person, c.purpose, c.channel, c.kind, c.phone_ref, c.id,
                       CASE WHEN c.kind = 'granted' THEN r.id ELSE c.id END AS pos
                  FROM (VALUES {values}) AS s
                  JOIN outreach_consents c ON c.subject_key = s.column2
                  LEFT JOIN outreach_consents r ON c.kind = 'granted' AND r.uuid = c.request_uuid
                 WHERE c.channel = 'whatsapp' AND c.kind IN ('granted', 'revoked')
              ) AS ev
          ) AS ranked
         WHERE n = 1
         ORDER BY person, purpose, channel
        SQL;
}
