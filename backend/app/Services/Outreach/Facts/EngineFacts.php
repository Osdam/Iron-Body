<?php

namespace App\Services\Outreach\Facts;

use App\Services\Commercial\CommercialVocabulary;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA del motor comercial (contrato H9, D9-1, D9-3
 * `crm_fact`, D9-4 `nba.open_goal` y transiciones, D9-7 N6). Tablas:
 * `commercial_opportunities` y `commercial_events`, NUNCA escritas (D9-1:
 * una fila del H9 en `commercial_opportunities` la secuestraría el motor y un
 * evento en `commercial_events` dispararía sus decisiones).
 *
 *  - `nba.open_goal`: el objetivo ABIERTO del motor para la persona
 *    (`CommercialVocabulary::OPEN_STATUSES`), por su ficha de socio o por sus
 *    prospectos; si hubiera varios, el de mayor `priority` y después el más
 *    reciente. Sin ninguno, null (DF-T7 del catálogo).
 *  - LOS EVENTOS, con la forma de `Core\FactSnapshot` (`{key, at, attrs}`): los
 *    tipos pedidos, del más reciente al más antiguo, en la ventana máxima de la
 *    gramática (730 días de Bogotá hasta ahora), como mucho `MAX_COUNT + 1` por
 *    tipo (basta para cualquier recuento con un literal ≤ 100) y
 *    `Core\FactSnapshot::MAX_ROWS` en total. `attrs` solo lleva el `plan_id` de
 *    los eventos de pago (`OutreachCatalog::PAYMENT_EVENTS`, el único atributo
 *    filtrable); de la carga NUNCA salen importes ni referencias.
 *  - LAS TRANSICIONES que prueban los eventos ({@see transitionsFrom()}).
 *
 * FUENTE SIN DATOS (DF-C4: ausencia no probada no es ausencia): con
 * `COMMERCIAL_EVENTS_ENABLED` apagada (hoy en producción, R9-8) el motor no
 * registra eventos nuevos, así que la lista es null (desconocido), no vacía.
 */
final class EngineFacts
{
    public const TABLES = ['commercial_opportunities', 'commercial_events'];

    public const FACTS = ['nba.open_goal'];

    /** Un evento de más que el mayor literal de un recuento (D9-5): basta para comparar. */
    public const MAX_EVENTS_PER_TYPE = OutreachCatalog::MAX_COUNT + 1;

    /** El tope de filas de una lista de `Core\FactSnapshot`. */
    public const MAX_EVENTS = 500;

    /** Los eventos que prueban una transición de las máquinas del catálogo. */
    public const TRANSITION_EVENTS = [
        'membership_expired' => ['membership', 'active', 'expired'],
        'membership_renewed' => ['membership', 'expired', 'active'],
        'membership_activated' => ['membership', 'expired', 'active'],
        'payment_approved' => ['payment', 'pending', 'approved'],
        'payment_failed' => ['payment', 'pending', 'declined'],
        'payment_expired' => ['payment', 'pending', 'expired'],
    ];

    /** @return array<string, array<string, ?string>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $socios = $b->memberIds();
        $leads = $b->leadIds();
        $abiertas = [];
        if ($socios !== [] || $leads !== []) {
            $abiertas = $this->ofPeople(DB::table('commercial_opportunities'), $socios, $leads)
                ->whereIn('status', CommercialVocabulary::OPEN_STATUSES)
                ->orderByDesc('priority')->orderByDesc('id')
                ->get(['id', 'goal', 'member_id', 'marketing_lead_id'])->all();
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $objetivo = null;
            if ($s->resolved()) {
                foreach ($abiertas as $o) {
                    if ($this->belongs($o, $s)) {
                        // La primera que casa es la de mayor prioridad (y la más reciente).
                        $objetivo = in_array($o->goal, OutreachCatalog::GOALS, true) ? (string) $o->goal : null;

                        break;
                    }
                }
            }
            $salida[$clave] = ['nba.open_goal' => $objetivo];
        }

        return $salida;
    }

    /** ¿Registra el motor eventos? (`commercial.events_enabled`, solo `true` cuenta). */
    public function sourceEnabled(): bool
    {
        return config('commercial.events_enabled') === true;
    }

    /**
     * Los eventos pedidos de cada persona, con la forma de `Core\FactSnapshot`.
     *
     * @param  list<string>  $types  claves de `OutreachCatalog::CRM_EVENTS` (las demás se ignoran)
     * @return array<string, list<array{at: string, attrs: array<string, int>, key: string}>|null> por clave pedida; null = fuente sin datos o persona sin resolver
     */
    public function events(FactPass $b, array $types): array
    {
        $tipos = array_values(array_intersect(array_keys(OutreachCatalog::CRM_EVENTS), $types));
        sort($tipos, SORT_STRING);

        $salida = [];
        $habilitada = $this->sourceEnabled();
        foreach ($b->subjects as $clave => $s) {
            $salida[$clave] = $s->resolved() && $habilitada ? [] : null;
        }
        $socios = $b->memberIds();
        $leads = $b->leadIds();
        if (! $habilitada || $tipos === [] || ($socios === [] && $leads === [])) {
            return $salida;
        }

        $conPlan = array_intersect($tipos, OutreachCatalog::PAYMENT_EVENTS) !== [];
        $columnas = ['id', 'event', 'member_id', 'marketing_lead_id', 'occurred_at', ...($conPlan ? ['payload'] : [])];
        $filas = $this->ofPeople(DB::table('commercial_events'), $socios, $leads)
            ->whereIn('event', $tipos)
            ->where('occurred_at', '>=', FactValues::sql(RelativeTime::windowStart($b->now, OutreachCatalog::MAX_WINDOW_DAYS, 'days')))
            ->where('occurred_at', '<=', FactValues::sql($b->now))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->get($columnas);

        $vistos = [];
        $porTipo = [];
        foreach ($filas as $f) {
            $cuando = FactValues::instant($f->occurred_at);
            if ($cuando === null) {
                continue;
            }
            $claves = [];
            if ($f->member_id !== null) {
                $claves = [...$claves, ...$b->keysForMember((int) $f->member_id)];
            }
            if ($f->marketing_lead_id !== null) {
                $claves = [...$claves, ...$b->keysForLead((int) $f->marketing_lead_id)];
            }
            foreach (array_unique($claves) as $clave) {
                // Un evento con socio Y prospecto de la misma persona cuenta una vez.
                if ($salida[$clave] === null || isset($vistos[$clave][(int) $f->id])
                    || ($porTipo[$clave][$f->event] ?? 0) >= self::MAX_EVENTS_PER_TYPE || count($salida[$clave]) >= self::MAX_EVENTS) {
                    continue;
                }
                $vistos[$clave][(int) $f->id] = true;
                $porTipo[$clave][$f->event] = ($porTipo[$clave][$f->event] ?? 0) + 1;
                $salida[$clave][] = ['at' => RelativeTime::format($cuando), 'attrs' => self::attrs((string) $f->event, $f->payload ?? null), 'key' => (string) $f->event];
            }
        }

        return $salida;
    }

    /**
     * Las transiciones que PRUEBAN los eventos (`{entity, from, to, at}`).
     *
     * @param  list<array{at: string, attrs: array<string, int>, key: string}>  $events
     * @return list<array{at: string, entity: string, from: string, to: string}>
     */
    public static function transitionsFrom(array $events): array
    {
        $salida = [];
        foreach ($events as $e) {
            $t = self::TRANSITION_EVENTS[$e['key']] ?? null;
            if ($t !== null) {
                $salida[] = ['at' => $e['at'], 'entity' => $t[0], 'from' => $t[1], 'to' => $t[2]];
            }
        }

        return $salida;
    }

    /** @return array<string, int> solo el `plan_id` de los eventos de pago */
    private static function attrs(string $evento, mixed $payload): array
    {
        if (! in_array($evento, OutreachCatalog::PAYMENT_EVENTS, true)) {
            return [];
        }
        if (is_string($payload) && $payload !== '') {
            $payload = json_decode($payload, true);
        }
        $plan = is_array($payload) ? ($payload['plan_id'] ?? null) : null;
        if (is_string($plan) && ctype_digit($plan)) {
            $plan = (int) $plan;
        }

        return is_int($plan) && $plan >= 1 ? ['plan_id' => $plan] : [];
    }

    /**
     * @param  list<int>  $socios
     * @param  list<int>  $leads
     */
    private function ofPeople(Builder $q, array $socios, array $leads): Builder
    {
        return $q->where(function ($w) use ($socios, $leads): void {
            if ($socios !== []) {
                $w->orWhereIn('member_id', $socios);
            }
            if ($leads !== []) {
                $w->orWhereIn('marketing_lead_id', $leads);
            }
        });
    }

    private function belongs(object $fila, Subject $s): bool
    {
        return ($fila->member_id !== null && in_array((int) $fila->member_id, $s->memberIds, true))
            || ($fila->marketing_lead_id !== null && in_array((int) $fila->marketing_lead_id, $s->leadIds, true));
    }
}
