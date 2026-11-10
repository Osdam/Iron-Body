<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use App\Services\Wompi\PaymentStateMachine as SM;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA del dinero en vuelo (contrato H9, D9-4 «Dinero»).
 * Tablas: `payment_transactions` y `membership_subscriptions` (por
 * {@see FactPass::subscriptions()}). NUNCA importes: solo booleanos,
 * recuentos y horas.
 *
 *  - Una transacción es de la persona por `member_id` o por `user_id` (nunca
 *    por teléfono: el teléfono no es identidad).
 *  - ENLACE ABIERTO = transacción en vuelo (`PaymentStateMachine::IN_FLIGHT`)
 *    con `checkout_url` y sin caducar (`expires_at` nulo o futuro), como
 *    `CommercialSubject::moneyFacts()`: perseguir un enlace caducado es peor
 *    que no perseguirlo. `payment.pending_link_age_hours`: horas completas del
 *    más reciente.
 *  - Los estados se comparan en minúsculas y en mayúsculas (`CommercialSubject`
 *    admite los dos), sin `LOWER()` para no perder el índice de `status`.
 *  - `payment.declined_last_7d`: CUÁNTOS rechazos (`declined`/`error`) en la
 *    ventana de 7 días de Bogotá (un recuento en el catálogo).
 *  - Suscripción: la más reciente; `subscription.active` ⇔ `status = active`.
 */
final class PaymentFacts
{
    /** Las suyas; el prefiltro resuelve a la persona con {@see SubjectResolver} (sus propias tablas). */
    public const TABLES = ['payment_transactions', 'membership_subscriptions'];

    public const FACTS = [
        'payment.has_pending_link', 'payment.pending_link_age_hours', 'payment.declined_last_7d', 'payment.approved_count',
        'subscription.active', 'subscription.failed_attempts',
    ];

    public const EVENT_ABANDONED = 'payment_link.abandoned';

    /** Lo que se mira hacia atrás después del plazo de cortesía (`ScanCommercialFacts`: 7 días). */
    public const ABANDONED_LOOKBACK_HOURS = 168;

    /** @return array<string, array<string, bool|int|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $socios = $b->memberIds();
        $usuarios = $b->userIds();
        $ahora = FactValues::sql($b->now);

        $agregados = [];
        $abiertos = [];
        if ($socios !== [] || $usuarios !== []) {
            $hace7 = FactValues::sql(RelativeTime::windowStart($b->now, 7, 'days'));
            $aprobados = self::cases([SM::APPROVED]);
            $rechazados = self::cases([SM::DECLINED, SM::ERROR]);
            $marcas = implode(',', array_fill(0, count($aprobados), '?'));
            $marcasR = implode(',', array_fill(0, count($rechazados), '?'));

            $agregados = $this->ofPeople(DB::table('payment_transactions'), $socios, $usuarios)
                ->where('created_at', '<=', $ahora)
                ->groupBy('member_id', 'user_id')
                ->selectRaw(
                    "member_id, user_id, SUM(CASE WHEN status IN ({$marcas}) THEN 1 ELSE 0 END) AS approved, "
                    ."SUM(CASE WHEN status IN ({$marcasR}) AND created_at >= ? THEN 1 ELSE 0 END) AS declined7",
                    [...$aprobados, ...$rechazados, $hace7],
                )
                ->get()->all();

            $abiertos = $this->ofPeople(DB::table('payment_transactions'), $socios, $usuarios)
                ->whereIn('status', self::cases(SM::IN_FLIGHT))
                ->whereNotNull('checkout_url')
                ->where('created_at', '<=', $ahora)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $ahora))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'member_id', 'user_id', 'created_at'])->all();
        }

        $suscripciones = $b->subscriptions();

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $hechos = array_fill_keys(self::FACTS, null);
            if (! $s->resolved() || ($s->memberIds === [] && $s->userIds === [])) {
                $salida[$clave] = $hechos;

                continue;
            }
            $aprobadas = 0;
            $rechazos = 0;
            foreach ($agregados as $g) {
                if ($this->belongs($g, $s)) {
                    $aprobadas += (int) $g->approved;
                    $rechazos += (int) $g->declined7;
                }
            }
            $enlace = null;
            foreach ($abiertos as $t) {
                if ($this->belongs($t, $s)) {
                    $enlace = $t;

                    break;
                }
            }
            $hechos['payment.approved_count'] = $aprobadas;
            $hechos['payment.declined_last_7d'] = $rechazos;
            $hechos['payment.has_pending_link'] = $enlace !== null;
            $creado = $enlace !== null ? FactValues::instant($enlace->created_at) : null;
            $hechos['payment.pending_link_age_hours'] = $creado !== null ? RelativeTime::hoursSince($creado, $b->now) : null;

            $sus = $suscripciones[$clave] ?? null;
            $hechos['subscription.active'] = $sus !== null && $sus->status === 'active';
            $hechos['subscription.failed_attempts'] = $sus !== null ? max(0, (int) $sus->failed_attempts) : null;
            $salida[$clave] = $hechos;
        }

        return $salida;
    }

    /**
     * Enlaces de pago abiertos que nadie usó pasado el plazo de cortesía: en
     * vuelo, con `checkout_url`, sin caducar, creados en
     * [ahora − (`$graceHours` + 7 d), ahora − `$graceHours`] y con persona
     * (`member_id` o `user_id`). Para `payment_link.abandoned`; la clave de
     * ciclo es la transacción (`tx:<id>`).
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function abandonedLinks(DateTimeInterface $now, int $graceHours, int $limit = 500, int $afterId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $ahora = RelativeTime::utc($now);
        $gracia = max(1, $graceHours);
        $filas = DB::table('payment_transactions')
            ->where('id', '>', $afterId)
            ->whereIn('status', self::cases(SM::IN_FLIGHT))
            ->whereNotNull('checkout_url')
            ->where('created_at', '<=', FactValues::sql($ahora->modify("-{$gracia} hours")))
            ->where('created_at', '>=', FactValues::sql($ahora->modify('-'.($gracia + self::ABANDONED_LOOKBACK_HOURS).' hours')))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', FactValues::sql($ahora)))
            ->where(fn ($q) => $q->whereNotNull('member_id')->orWhereNotNull('user_id'))
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'member_id', 'user_id']);

        $resolutor = new SubjectResolver;
        $porSocio = $resolutor->forMemberIds($filas->whereNotNull('member_id')->pluck('member_id')->map(fn ($id) => (int) $id)->all());
        $sinSocio = $filas->whereNull('member_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $porUsuario = $sinSocio === [] ? [] : $resolutor->forUserIds($sinSocio);

        $candidatos = [];
        foreach ($filas as $f) {
            $s = $f->member_id !== null ? ($porSocio[(int) $f->member_id] ?? null) : ($porUsuario[(int) $f->user_id] ?? null);
            if ($s !== null) {
                $candidatos[] = new Candidate((string) $s->key, self::EVENT_ABANDONED, ['payment_transaction_id' => (int) $f->id], (int) $f->id);
            }
        }

        return ['candidates' => $candidatos, 'next' => $filas->count() === $limite ? (int) $filas->last()->id : null];
    }

    /**
     * @param  list<int>  $socios
     * @param  list<int>  $usuarios
     */
    private function ofPeople(Builder $q, array $socios, array $usuarios): Builder
    {
        return $q->where(function ($w) use ($socios, $usuarios): void {
            if ($socios !== []) {
                $w->orWhereIn('member_id', $socios);
            }
            if ($usuarios !== []) {
                $w->orWhereIn('user_id', $usuarios);
            }
        });
    }

    private function belongs(object $fila, Subject $s): bool
    {
        return ($fila->member_id !== null && in_array((int) $fila->member_id, $s->memberIds, true))
            || ($fila->user_id !== null && in_array((int) $fila->user_id, $s->userIds, true));
    }

    /**
     * Los estados en minúsculas y en mayúsculas.
     *
     * @param  list<string>  $estados
     * @return list<string>
     */
    private static function cases(array $estados): array
    {
        $todos = [];
        foreach ($estados as $e) {
            $todos[] = strtolower($e);
            $todos[] = strtoupper($e);
        }

        return array_values(array_unique($todos));
    }
}
