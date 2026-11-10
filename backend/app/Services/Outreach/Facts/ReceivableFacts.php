<?php

namespace App\Services\Outreach\Facts;

use App\Models\Receivable;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de la cartera (contrato H9, D9-4 «Cartera», solo
 * finalidad `collections`). Tablas: `receivables`, `receivable_payments`.
 * NUNCA importes ni saldos: solo días, recuentos y el booleano; la traza
 * enseña tramos (`OutreachCatalog::receivableBucket()`).
 *
 * VENCIDA es `Receivable::scopeOverdue()`: viva por ESTADO (`pending` o
 * `partially_paid`, que el servicio recalcula en la misma transacción que
 * mueve el dinero: decir eso es decir «queda saldo»), con `due_at` y con el
 * día pactado YA PASADO en Bogotá (`due_at` = hoy todavía no ha vencido).
 * Los días de mora son días de calendario (`Receivable::daysOverdue()`).
 *
 * LA GENERACIÓN de la clave de ciclo (`rcv:<id>:<gen>`) es el número de abonos
 * de la cuenta (aplicados o revertidos): sube al abonar y no baja al revertir,
 * así que vencida → saldada → reverso → vencida otra vez es un ciclo nuevo, y
 * repetir la pasada no lo es (como `DetectOverdueReceivables::generacion()`,
 * reducido a un entero por la forma que fija el catálogo).
 */
final class ReceivableFacts
{
    /** Las suyas; el prefiltro resuelve a la persona con {@see SubjectResolver}. */
    public const TABLES = ['receivables', 'receivable_payments'];

    public const FACTS = ['receivable.has_overdue', 'receivable.overdue_days_max', 'receivable.count_overdue'];

    public const EVENT_OVERDUE = 'receivable.overdue';

    /** @return array<string, array<string, bool|int|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $porSocio = [];
        $socios = $b->memberIds();
        if ($socios !== []) {
            $filas = DB::table('receivables')
                ->whereIn('member_id', $socios)
                ->whereIn('status', [Receivable::STATUS_PENDING, Receivable::STATUS_PARTIALLY_PAID])
                ->whereNotNull('due_at')
                ->where('due_at', '<', $b->today)
                ->orderBy('id')
                ->get(['id', 'member_id', 'due_at']);
            foreach ($filas as $f) {
                $vence = FactValues::date($f->due_at);
                if ($vence !== null && $vence < $b->today) {
                    $porSocio[(int) $f->member_id][] = -RelativeTime::daysUntilDate($vence, $b->now);
                }
            }
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $hechos = array_fill_keys(self::FACTS, null);
            if ($s->resolved()) {
                // Un prospecto sin ficha de socio no puede deber (`receivables` cuelga
                // de `members`): se SABE que no tiene cartera vencida.
                $dias = [];
                foreach ($s->memberIds as $m) {
                    $dias = [...$dias, ...($porSocio[$m] ?? [])];
                }
                $hechos['receivable.has_overdue'] = $dias !== [];
                $hechos['receivable.count_overdue'] = count($dias);
                $hechos['receivable.overdue_days_max'] = $dias === [] ? null : max($dias);
            }
            $salida[$clave] = $hechos;
        }

        return $salida;
    }

    /**
     * Cuentas vivas vencidas hace entre `$daysMin` y `$daysMax` días (días de
     * Bogotá, los dos extremos dentro), de un socio. Para `receivable.overdue`.
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function overdueBetween(DateTimeInterface $now, int $daysMin, int $daysMax, int $limit = 500, int $afterId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $hoy = RelativeTime::bogotaDate($now);
        $min = max(1, $daysMin);
        if ($min > $daysMax) {
            return ['candidates' => [], 'next' => null];
        }
        // due_at ∈ [hoy − max, hoy − min], como intervalo semiabierto de fechas.
        $filas = DB::table('receivables')
            ->where('id', '>', $afterId)
            ->whereIn('status', [Receivable::STATUS_PENDING, Receivable::STATUS_PARTIALLY_PAID])
            ->whereNotNull('member_id')
            ->where('due_at', '>=', FactValues::addDays($hoy, -$daysMax))
            ->where('due_at', '<', FactValues::addDays($hoy, -$min + 1))
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'member_id']);

        $generaciones = [];
        if ($filas->isNotEmpty()) {
            $generaciones = DB::table('receivable_payments')
                ->whereIn('receivable_id', $filas->pluck('id')->map(fn ($id) => (int) $id)->all())
                ->groupBy('receivable_id')
                ->selectRaw('receivable_id, COUNT(*) AS n')
                ->pluck('n', 'receivable_id')
                ->all();
        }

        $sujetos = (new SubjectResolver)->forMemberIds($filas->pluck('member_id')->map(fn ($id) => (int) $id)->all());
        $candidatos = [];
        foreach ($filas as $f) {
            $s = $sujetos[(int) $f->member_id] ?? null;
            if ($s !== null) {
                $candidatos[] = new Candidate((string) $s->key, self::EVENT_OVERDUE, [
                    'receivable_id' => (int) $f->id,
                    'generation' => (int) ($generaciones[(int) $f->id] ?? $generaciones[(string) $f->id] ?? 0),
                ], (int) $f->id);
            }
        }

        return ['candidates' => $candidatos, 'next' => $filas->count() === $limite ? (int) $filas->last()->id : null];
    }
}
