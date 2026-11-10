<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * EL HISTORIAL UNIFICADO de una persona (contrato H9, D9-9.6 y L9-3): un
 * MODELO DE LECTURA, sin tabla propia. Compone en orden temporal las
 * DECISIONES, los TRABAJOS y los RESULTADOS del H9 de una `subject_key`, con
 * canal, estrategia, paso, motivo, resultado y próxima acción. SOLO LECTURA.
 *
 * Lee las tablas de DB9 (`outreach_decisions`, `outreach_jobs`,
 * `outreach_outcomes`, `outreach_enrollments`, `ultron_studio_strategies` y
 * `ultron_studio_strategy_versions`; migraciones `2026_11_12_1000{01,02}`).
 * Si faltan (el código desplegado antes que sus migraciones),
 * {@see available()} es false y el historial sale vacío y lo dice.
 *
 * Cada entrada: `{at, kind, channel, medium, direction, strategy, step,
 * reason, result, nextAction, nextAt}`, solo códigos, instantes y el uuid de
 * la estrategia: nunca textos, teléfonos ni cuerpos. `nextAction` es `retry`
 * en una decisión aplazada (con su `retry_at`), `dispatch` en un trabajo
 * planificado o reclamado (con su `not_before`) y, en un resultado, la
 * transición de D9-10 que quedó registrada (`next_step`, `retry_step`, `wait`,
 * `end_success`, `end_exit`, `handoff_human`) con su hora (L9-3). Paginado
 * (≤ 50) por un cursor opaco estable (instante desc, clase, id desc), con una
 * consulta por fuente filtrada por SU `subject_key` (índices `odec_subject_idx`,
 * `ojob_subject_idx` y `oout_subject_idx`).
 */
final class SubjectTimeline
{
    public const TABLES = [
        'outreach_decisions', 'outreach_jobs', 'outreach_outcomes', 'outreach_enrollments',
        'ultron_studio_strategies', 'ultron_studio_strategy_versions',
    ];

    public const MAX_PAGE = 50;

    /** El orden dentro de un mismo instante: la decisión, después el trabajo, después el resultado. */
    private const RANK = ['decision' => 1, 'job' => 2, 'outcome' => 3];

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available === null) {
            $this->available = true;
            foreach (self::TABLES as $tabla) {
                if (! Schema::hasTable($tabla)) {
                    $this->available = false;

                    break;
                }
            }
        }

        return $this->available;
    }

    /**
     * @return array{entries: list<array<string, int|string|null>>, next: ?string, available: bool}
     *
     * @throws InvalidArgumentException con una clave o un cursor sin forma
     */
    public function forSubject(string $subjectKey, int $limit = self::MAX_PAGE, ?string $cursor = null): array
    {
        if (! SubjectKey::isValid($subjectKey)) {
            throw new InvalidArgumentException('La clave de persona no tiene forma.');
        }
        $limite = max(1, min(self::MAX_PAGE, $limit));
        $desde = $cursor === null ? null : self::decodeCursor($cursor);
        if (! $this->available()) {
            return ['entries' => [], 'next' => null, 'available' => false];
        }

        $filas = [
            ...$this->decisions($subjectKey, $desde, $limite + 1),
            ...$this->jobs($subjectKey, $desde, $limite + 1),
            ...$this->outcomes($subjectKey, $desde, $limite + 1),
        ];
        usort($filas, static fn (array $a, array $b): int => [$b['_at'], self::RANK[$a['kind']], $b['_id']] <=> [$a['_at'], self::RANK[$b['kind']], $a['_id']]);

        $pagina = array_slice($filas, 0, $limite);
        $siguiente = count($filas) > $limite && $pagina !== []
            ? self::encodeCursor(end($pagina))
            : null;

        return [
            'entries' => array_map(static function (array $f): array {
                unset($f['_at'], $f['_id']);
                ksort($f, SORT_STRING);

                return $f;
            }, $pagina),
            'next' => $siguiente,
            'available' => true,
        ];
    }

    /** @return list<array<string, int|string|null>> */
    private function decisions(string $clave, ?array $desde, int $n): array
    {
        $q = DB::table('outreach_decisions as d')
            ->leftJoin('ultron_studio_strategy_versions as v', 'v.id', '=', 'd.strategy_version_id')
            ->leftJoin('ultron_studio_strategies as s', 's.id', '=', 'v.strategy_id')
            ->where('d.subject_key', $clave);
        self::after($q, 'd.evaluated_at', 'd.id', self::RANK['decision'], $desde);
        $salida = [];
        foreach ($q->orderByDesc('d.evaluated_at')->orderByDesc('d.id')->limit($n)
            ->get(['d.id', 'd.kind', 'd.level', 'd.reasons', 'd.retry_at', 'd.evaluated_at', 'd.mode', 's.uuid as strategy']) as $d) {
            $motivos = is_string($d->reasons) ? json_decode($d->reasons, true) : $d->reasons;
            $primero = is_array($motivos) && isset($motivos[0]) && is_string($motivos[0]) ? $motivos[0] : null;
            $reintento = FactValues::instant($d->retry_at);
            $salida[] = self::entry('decision', $d->evaluated_at, (int) $d->id, [
                'channel' => null, 'medium' => null, 'direction' => null, 'strategy' => $d->strategy,
                'step' => null, 'reason' => $primero, 'result' => (string) $d->kind,
                'level' => $d->level !== null ? (string) $d->level : null, 'mode' => (string) $d->mode,
                'nextAction' => $reintento !== null ? 'retry' : null, 'nextAt' => $reintento !== null ? RelativeTime::format($reintento) : null,
            ]);
        }

        return $salida;
    }

    /** @return list<array<string, int|string|null>> */
    private function jobs(string $clave, ?array $desde, int $n): array
    {
        $q = DB::table('outreach_jobs as j')
            ->leftJoin('outreach_enrollments as e', 'e.id', '=', 'j.enrollment_id')
            ->leftJoin('ultron_studio_strategies as s', 's.id', '=', 'e.strategy_id')
            ->where('j.subject_key', $clave);
        self::after($q, 'j.created_at', 'j.id', self::RANK['job'], $desde);
        $salida = [];
        foreach ($q->orderByDesc('j.created_at')->orderByDesc('j.id')->limit($n)
            ->get(['j.id', 'j.channel', 'j.medium', 'j.status', 'j.step_index', 'j.not_before', 'j.cancel_reason', 'j.created_at', 'j.mode', 's.uuid as strategy']) as $j) {
            $planificado = in_array($j->status, ['planned', 'claimed'], true);
            $cuando = FactValues::instant($j->not_before);
            $salida[] = self::entry('job', $j->created_at, (int) $j->id, [
                'channel' => (string) $j->channel, 'medium' => $j->medium !== null ? (string) $j->medium : null,
                'direction' => 'outbound', 'strategy' => $j->strategy, 'step' => (int) $j->step_index,
                'reason' => $j->cancel_reason !== null ? (string) $j->cancel_reason : null, 'result' => (string) $j->status,
                'level' => null, 'mode' => (string) $j->mode,
                'nextAction' => $planificado ? 'dispatch' : null, 'nextAt' => $planificado && $cuando !== null ? RelativeTime::format($cuando) : null,
            ]);
        }

        return $salida;
    }

    /**
     * L9-3: el resultado lleva su persona, su estrategia, su canal y su
     * próxima acción; `oout_subject_idx (subject_key, occurred_at, id)` sirve
     * el filtro y el orden sin pasar por la inscripción, que solo aporta el
     * modo; el trabajo, el medio y el paso.
     *
     * @return list<array<string, int|string|null>>
     */
    private function outcomes(string $clave, ?array $desde, int $n): array
    {
        $q = DB::table('outreach_outcomes as o')
            ->join('outreach_enrollments as e', 'e.id', '=', 'o.enrollment_id')
            ->leftJoin('outreach_jobs as j', 'j.id', '=', 'o.job_id')
            ->leftJoin('ultron_studio_strategies as s', 's.id', '=', 'o.strategy_id')
            ->where('o.subject_key', $clave);
        self::after($q, 'o.occurred_at', 'o.id', self::RANK['outcome'], $desde);
        $salida = [];
        foreach ($q->orderByDesc('o.occurred_at')->orderByDesc('o.id')->limit($n)
            ->get(['o.id', 'o.outcome', 'o.source', 'o.detail_code', 'o.occurred_at', 'o.channel', 'o.next_action', 'o.next_action_at',
                'j.medium', 'j.step_index', 'e.mode', 's.uuid as strategy']) as $o) {
            $proximo = FactValues::instant($o->next_action_at);
            $salida[] = self::entry('outcome', $o->occurred_at, (int) $o->id, [
                'channel' => $o->channel !== null ? (string) $o->channel : null, 'medium' => $o->medium !== null ? (string) $o->medium : null,
                'direction' => $o->source === 'inbound_message' ? 'inbound' : 'outbound', 'strategy' => $o->strategy,
                'step' => $o->step_index !== null ? (int) $o->step_index : null,
                'reason' => $o->detail_code !== null ? (string) $o->detail_code : null, 'result' => (string) $o->outcome,
                'level' => null, 'mode' => (string) $o->mode,
                'nextAction' => $o->next_action !== null ? (string) $o->next_action : null, 'nextAt' => $proximo !== null ? RelativeTime::format($proximo) : null,
            ]);
        }

        return $salida;
    }

    /**
     * Las filas DESPUÉS del cursor en el orden (instante desc, clase, id desc).
     *
     * @param  array{at: string, rank: int, id: int}|null  $desde
     */
    private static function after(Builder $q, string $columnaAt, string $columnaId, int $rango, ?array $desde): void
    {
        if ($desde === null) {
            return;
        }
        $at = $desde['at'];
        if ($rango > $desde['rank']) {
            $q->where($columnaAt, '<=', $at);
        } elseif ($rango === $desde['rank']) {
            $q->where(fn ($w) => $w->where($columnaAt, '<', $at)->orWhere(fn ($x) => $x->where($columnaAt, '=', $at)->where($columnaId, '<', $desde['id'])));
        } else {
            $q->where($columnaAt, '<', $at);
        }
    }

    /**
     * @param  array<string, int|string|null>  $campos
     * @return array<string, int|string|null>
     */
    private static function entry(string $tipo, mixed $at, int $id, array $campos): array
    {
        $instante = FactValues::instant($at);

        return ['kind' => $tipo, 'at' => $instante !== null ? RelativeTime::format($instante) : null,
            '_at' => $instante !== null ? FactValues::sql($instante) : '', '_id' => $id] + $campos;
    }

    /** @param  array<string, int|string|null>  $fila */
    private static function encodeCursor(array $fila): string
    {
        return rtrim(strtr(base64_encode($fila['_at'].'|'.self::RANK[$fila['kind']].'|'.$fila['_id']), '+/', '-_'), '=');
    }

    /**
     * @return array{at: string, rank: int, id: int}
     *
     * @throws InvalidArgumentException
     */
    private static function decodeCursor(string $cursor): array
    {
        $claro = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($claro) || preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})\|([123])\|([1-9][0-9]{0,18})$/D', $claro, $m) !== 1) {
            throw new InvalidArgumentException('Cursor sin forma.');
        }

        return ['at' => $m[1], 'rank' => (int) $m[2], 'id' => (int) $m[3]];
    }
}
