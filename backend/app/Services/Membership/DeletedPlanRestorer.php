<?php

namespace App\Services\Membership;

use App\Exceptions\DeletedPlanRestoreException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\PaymentCreationAudit;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Restaura COMO HISTÓRICO un plan borrado del catálogo y vuelve a enlazar los
 * cobros que el borrado dejó «sin plan». Lo ejecuta `plans:restore-deleted`.
 *
 * EL CASO. El 16-10-2026 se borró el plan 22 «TOTAL ACCESS - ELITE». La llave
 * `payments_plan_id_foreign` era ON DELETE SET NULL y `PlanController::destroy`
 * no tenía guarda: 31 cobros se quedaron con `plan_id` nulo, el CRM los pinta
 * como «Pago sin plan» y la transferencia de membresía no encuentra su origen.
 *
 * QUÉ ES PRUEBA Y QUÉ NO. Un cobro se vuelve a enlazar SOLO si (1) hoy no tiene
 * plan, (2) se creó antes del borrado y (3) su traza de creación (`pago /
 * create`) declara ese plan y ningún otro. Nada por nombre, fecha ni importe:
 * los `MIGR-*` importados y los que no tienen traza se quedan como están.
 *
 * LO QUE ESCRIBE, y nada más (se comprueba con `DB::listen`):
 *   · el plan, con su id y los valores del snapshot, ARCHIVADO (`active`,
 *     `sellable` y `visible_in_app` en false: ni se vende ni se ve);
 *   · un `UPDATE payments SET plan_id` sobre esos ids, con el query builder:
 *     sin observers, facturas, eventos ni tiempo real;
 *   · una traza por cobro y una por el plan, firmadas por una persona.
 * Antes y después compara, dentro de la misma transacción, cobros (salvo ese
 * `plan_id`), deudas, abonos, facturas, socios y el resto del catálogo.
 *
 * EN SECO POR DEFECTO: hace todo y termina en ROLLBACK, para que el informe
 * diga exactamente lo que va a pasar. En PostgreSQL corre en REPEATABLE READ
 * (las dos fotos ven el mismo instante; si alguien toca una fila bloqueada, la
 * base aborta con 40001 y se reintenta) y bajo un cerrojo consultivo, para que
 * dos ejecuciones no se crucen. Volver a correrlo no hace nada.
 */
class DeletedPlanRestorer
{
    public const SOURCE = 'artisan plans:restore-deleted';

    public const EVENT_PLAN = 'plan.restored';

    public const EVENT_PAYMENT = 'payment.plan.relinked';

    /** Archivado: el plan existe para la historia, no para venderse ni enseñarse. */
    public const ARCHIVED = ['active' => false, 'sellable' => false, 'visible_in_app' => false];

    /**
     * Lo que se compara antes y después, entero. `payments` aparte: su
     * `plan_id` es lo único que cambia. Los traslados de plan y los traspasos
     * de membresía no se revierten ni se tocan: el traslado 22 → 8 fue legítimo
     * y la membresía actual de cada socio (`users`) se queda como está.
     */
    private const GUARDED_TABLES = ['receivables', 'receivable_payments', 'electronic_invoices', 'users',
        'plan_transfer_batches', 'plan_transfer_items', 'membership_transfers'];

    /** Columnas del archivo (add_archive_to_plans_table): las pone el comando, no el snapshot. */
    private const ARCHIVE_COLUMNS = ['archived_at', 'archived_by', 'archive_reason'];

    /** Clave del cerrojo consultivo de PostgreSQL (arbitraria, solo de este comando). */
    private const ADVISORY_KEY = 722_031_016;

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly PaymentCreationAudit $creations,
    ) {}

    /**
     * @return array<string, mixed> el informe
     *
     * @throws DeletedPlanRestoreException si falla una precondición (no queda nada escrito)
     */
    public function run(int $planId, string $snapshotPath, int $actorId, string $reason, bool $commit, ?string $expectedSha256 = null): array
    {
        $snapshot = $this->readSnapshot($planId, $snapshotPath, $expectedSha256);
        $actor = $this->actor($actorId);
        $columnas = $this->checkColumns($snapshot['row']);

        $pg = DB::connection()->getDriverName() === 'pgsql';
        $raiz = DB::transactionLevel() === 0;
        if ($pg) {
            DB::select('SELECT pg_advisory_lock(?)', [self::ADVISORY_KEY]);
        }

        try {
            for ($intento = 1; ; $intento++) {
                DB::beginTransaction();
                try {
                    if ($pg && $raiz) {
                        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    }
                    $informe = $this->restore($planId, $snapshot, $columnas, $actor, $reason);
                    $informe['mode'] = $commit ? 'commit' : 'dry-run';
                    $informe['attempts'] = $intento;
                    $commit ? DB::commit() : DB::rollBack();

                    return $informe;
                } catch (Throwable $e) {
                    DB::rollBack();
                    // Solo la transacción raíz se puede repetir: dentro de otra,
                    // un interbloqueo ya abortó la de fuera.
                    if ($raiz && $intento < self::ATTEMPTS && (new ConcurrencyErrorDetector)->causedByConcurrencyError($e)) {
                        continue;
                    }

                    throw $e;
                }
            }
        } finally {
            if ($pg) {
                DB::select('SELECT pg_advisory_unlock(?)', [self::ADVISORY_KEY]);
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Dentro de la transacción
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{missing: list<string>}  $columnas
     * @return array<string, mixed>
     */
    private function restore(int $planId, array $snapshot, array $columnas, Admin $actor, string $reason): array
    {
        $fila = $snapshot['row'];
        $nombre = (string) $fila['name'];

        // ── El plan: no existe, o ya está restaurado (archivado y con ese nombre).
        $actual = DB::table('plans')->where('id', $planId)->lockForUpdate()->first();
        $yaRestaurado = false;
        if ($actual !== null) {
            $archivado = ! (bool) $actual->active && ! (bool) $actual->sellable && ! (bool) $actual->visible_in_app;
            if ((string) $actual->name !== $nombre || ! $archivado) {
                throw new DeletedPlanRestoreException(
                    "Ya existe un plan #{$planId} («{$actual->name}»".($archivado ? ', archivado' : ', activo o visible').
                    "). Solo se restaura si no existe o si ya es «{$nombre}» archivado. No se escribió nada.",
                    'plan_exists',
                );
            }
            $yaRestaurado = true;
        }

        // ── El borrado, en la auditoría: ese id y ese nombre.
        $borrado = $this->deletionAudit($planId, $nombre);
        $momentoBorrado = (string) $borrado->getRawOriginal('created_at');
        $motivoArchivo = self::archiveReason($momentoBorrado);

        $this->checkTaxRate($fila);

        // ── Los cobros demostrados.
        [$candidatos, $excluidos] = $this->candidates($planId, $momentoBorrado);
        $ids = array_keys($candidatos);

        // ── Bloqueos en el orden de la caja: socio → cobro → deuda.
        $usuarios = [];
        $pagos = collect();
        if ($ids !== []) {
            $userIds = array_values(array_unique(array_map(fn (array $c): int => $c['user_id'], $candidatos)));
            sort($userIds);
            $usuarios = DB::table('users')->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()
                ->get(['id', 'name'])->keyBy('id')->all();
            $pagos = DB::table('payments')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            DB::table('receivables')->where('source_type', (new Payment)->getMorphClass())
                ->whereIn('source_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

            // Releído bajo bloqueo: si algo cambió desde la selección, se aborta.
            foreach ($candidatos as $id => $c) {
                $p = $pagos->get($id);
                if ($p === null || $p->plan_id !== null || (int) $p->user_id !== $c['user_id']) {
                    throw new DeletedPlanRestoreException(
                        "El cobro #{$id} cambió mientras se preparaba la restauración. No se escribió nada; vuelve a ejecutarlo.",
                        'payment_changed',
                    );
                }
            }
        }

        // ── Foto ANTES (mismo instante que la de después en PostgreSQL).
        $antes = $this->fingerprints($planId, []);

        // ── Escrituras, contadas.
        $escrituras = [];
        $grabando = true;
        DB::listen(function (QueryExecuted $q) use (&$escrituras, &$grabando): void {
            if ($grabando && ($firma = self::writeSignature($q->sql)) !== null) {
                $escrituras[] = $firma;
            }
        });

        try {
            if (! $yaRestaurado) {
                DB::table('plans')->insert($this->planRow($fila, $actor, $motivoArchivo));
            }

            $actualizados = $ids === [] ? 0 : DB::table('payments')
                ->whereIn('id', $ids)
                ->whereNull('plan_id')
                ->update(['plan_id' => $planId]);
            if ($actualizados !== count($ids)) {
                throw new DeletedPlanRestoreException(
                    'Se esperaba enlazar '.count($ids)." cobros y la base enlazó {$actualizados}. No se escribió nada.",
                    'unexpected_update_count',
                );
            }

            $request = $this->request($actor);
            $evidencia = [
                'file' => $snapshot['path'],
                'sha256' => $snapshot['sha256'],
                'source' => $snapshot['source'],
                'dump_sha256' => $snapshot['dump_sha256'],
            ];
            if (! $yaRestaurado) {
                $this->audit->record($request, [
                    'action' => 'create',
                    'module' => 'Planes',
                    'entity' => 'plan',
                    'entity_id' => $planId,
                    'target_name' => $nombre,
                    'summary' => "Restauró como histórico (archivado) el plan eliminado {$nombre} y volvió a enlazar ".
                        count($ids).' '.(count($ids) === 1 ? 'cobro' : 'cobros'),
                    'metadata' => [
                        'event' => self::EVENT_PLAN,
                        'plan_id' => $planId,
                        'reason' => $reason,
                        'origin' => self::SOURCE,
                        'snapshot' => $evidencia,
                        'deletion_audit_id' => (int) $borrado->id,
                        'deleted_at' => $momentoBorrado,
                        'archived' => self::ARCHIVED,
                        'archive_reason' => $motivoArchivo,
                        'snapshot_flags' => array_map(fn ($v) => (bool) $v, array_intersect_key($fila, self::ARCHIVED)),
                        'relinked_payment_ids' => $ids,
                    ],
                ]);
            }
            foreach ($candidatos as $id => $c) {
                $this->audit->record($request, [
                    'action' => 'update',
                    'module' => 'Pagos',
                    'entity' => 'pago',
                    'entity_id' => $id,
                    'target_name' => $c['reference'],
                    'summary' => "Volvió a enlazar el cobro con su plan «{$nombre}» (#{$planId}), restaurado como histórico",
                    'changes' => [['field' => 'plan_id', 'from' => null, 'to' => (string) $planId]],
                    'metadata' => [
                        'event' => self::EVENT_PAYMENT,
                        'payment_id' => $id,
                        'user_id' => $c['user_id'],
                        'plan_id' => $planId,
                        'reason' => $reason,
                        'origin' => self::SOURCE,
                        'creation_audit_ids' => $c['audit_ids'],
                        'deletion_audit_id' => (int) $borrado->id,
                        'snapshot_sha256' => $snapshot['sha256'],
                    ],
                ]);
            }
        } finally {
            $grabando = false;
        }

        // ── Comprobaciones.
        $esperadas = array_merge(
            $yaRestaurado ? [] : ['insert plans'],
            $ids === [] ? [] : ['update payments'],
            array_fill(0, count($ids) + ($yaRestaurado ? 0 : 1), 'insert audit_logs'),
        );
        if ($escrituras !== $esperadas) {
            throw new DeletedPlanRestoreException(
                'Escrituras inesperadas: '.json_encode($escrituras).' (se esperaban '.json_encode($esperadas).'). No se escribió nada.',
                'unexpected_writes',
            );
        }

        $despues = $this->fingerprints($planId, $ids);
        $distintas = array_keys(array_filter($antes, fn (array $f, string $t): bool => $f !== $despues[$t], ARRAY_FILTER_USE_BOTH));
        if ($distintas !== []) {
            throw new DeletedPlanRestoreException(
                'Cambió algo que no debía cambiar: '.implode(', ', $distintas).'. No se escribió nada.',
                'guarded_data_changed',
            );
        }

        $this->checkRestoredPlan($planId, $fila);
        $enlazados = $ids === [] ? 0 : DB::table('payments')->whereIn('id', $ids)->where('plan_id', $planId)->count();
        if ($enlazados !== count($ids)) {
            throw new DeletedPlanRestoreException('Los cobros no quedaron enlazados como se esperaba. No se escribió nada.', 'relink_check_failed');
        }

        return [
            'outcome' => $yaRestaurado ? ($ids === [] ? 'noop' : 'relinked_only') : 'restored',
            'plan' => [
                'id' => $planId,
                'name' => $nombre,
                'existed_before' => $yaRestaurado,
                'price' => $fila['price'] ?? null,
                'duration_days' => $fila['duration_days'] ?? null,
                'created_at' => $fila['created_at'] ?? null,
                'flags_after' => self::ARCHIVED,
                'archive_reason' => $motivoArchivo,
            ],
            'snapshot' => $evidencia + ['dump_sha256_matches' => $snapshot['dump_sha256_matches']],
            'deletion_audit' => [
                'id' => (int) $borrado->id,
                'created_at' => $momentoBorrado,
                'actor_name' => $borrado->actor_name,
            ],
            'missing_columns' => $columnas['missing'],
            'payments' => array_values(array_map(function (array $c) use ($pagos, $usuarios): array {
                $p = $pagos->get($c['id']);

                return [
                    'id' => $c['id'],
                    'reference' => $p->reference,
                    'user_id' => (int) $p->user_id,
                    'user_name' => $usuarios[(int) $p->user_id]->name ?? null,
                    'member_id' => $p->member_id ?? null,
                    'amount' => (string) $p->amount,
                    'method' => $p->method,
                    'status' => $p->status,
                    'period_start' => $p->period_start ? substr((string) $p->period_start, 0, 10) : null,
                    'period_end' => $p->period_end ? substr((string) $p->period_end, 0, 10) : null,
                    'created_at' => (string) $p->created_at,
                    'creation_audit_ids' => $c['audit_ids'],
                ];
            }, $candidatos)),
            'excluded' => $excluidos,
            'writes' => [
                'plans_insert' => $yaRestaurado ? 0 : 1,
                'payments_update_statements' => $ids === [] ? 0 : 1,
                'payments_rows' => $actualizados,
                'audit_logs_insert' => count($ids) + ($yaRestaurado ? 0 : 1),
                'statements' => $escrituras,
            ],
            'checks' => ['guarded_tables' => array_keys($antes), 'unchanged' => true],
            'sequence' => $this->sequence($planId),
            'users_with_plan_name' => PlanArchive::membersNamed($nombre),
            'transfer_batches_without_plan_id' => DB::table('plan_transfer_batches')
                ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('from_plan_id')->where('from_plan_name', $nombre))
                    ->orWhere(fn ($w) => $w->whereNull('to_plan_id')->where('to_plan_name', $nombre)))
                ->count(),
        ];
    }

    /**
     * Los cobros sin plan, creados antes del borrado, cuya traza de creación
     * declara este plan y solo este. Los demás huérfanos se cuentan por motivo.
     *
     * @return array{0: array<int, array{id: int, user_id: int, reference: ?string, audit_ids: list<int>}>, 1: array<string, mixed>}
     */
    private function candidates(int $planId, string $momentoBorrado): array
    {
        $huerfanos = DB::table('payments')
            ->whereNull('plan_id')
            ->where('created_at', '<', $momentoBorrado)
            ->orderBy('id')
            ->get(['id', 'user_id', 'reference']);
        $declarados = $this->creations->declaredPlans($huerfanos->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $candidatos = [];
        $excluidos = ['migrated' => 0, 'no_creation_audit' => 0, 'audit_without_plan' => 0, 'other_plan' => [], 'ambiguous' => []];
        foreach ($huerfanos as $h) {
            $id = (int) $h->id;
            $planes = $declarados[$id]['plan_ids'] ?? null;
            if ($planes === null) {
                str_starts_with(strtoupper((string) $h->reference), 'MIGR-')
                    ? $excluidos['migrated']++
                    : $excluidos['no_creation_audit']++;

                continue;
            }
            if ($planes === [$planId]) {
                $candidatos[$id] = [
                    'id' => $id,
                    'user_id' => (int) $h->user_id,
                    'reference' => $h->reference,
                    'audit_ids' => $declarados[$id]['audit_ids'],
                ];

                continue;
            }
            if (in_array($planId, $planes, true)) {
                $excluidos['ambiguous'][] = ['payment_id' => $id, 'declared' => $planes];
            } elseif (array_filter($planes, fn ($p) => $p !== null) === []) {
                $excluidos['audit_without_plan']++;
            } else {
                $excluidos['other_plan'][] = ['payment_id' => $id, 'declared' => $planes];
            }
        }
        $excluidos['created_after_deletion'] = DB::table('payments')->whereNull('plan_id')
            ->where('created_at', '>=', $momentoBorrado)->count();

        return [$candidatos, $excluidos];
    }

    private function deletionAudit(int $planId, string $nombre): AuditLog
    {
        $filas = AuditLog::query()
            ->where('action', 'delete')
            ->where('entity', 'plan')
            ->where('entity_id', (string) $planId)
            ->orderBy('id')
            ->get();

        $otros = $filas->filter(fn (AuditLog $f): bool => $f->target_name !== $nombre);
        if ($otros->isNotEmpty()) {
            throw new DeletedPlanRestoreException(
                "La auditoría registra el borrado del plan #{$planId} con otro nombre («{$otros->first()->target_name}»). ".
                'No se puede afirmar que el snapshot sea ese plan. No se escribió nada.',
                'deletion_audit_mismatch',
            );
        }
        if ($filas->count() !== 1) {
            throw new DeletedPlanRestoreException(
                $filas->isEmpty()
                    ? "No hay auditoría del borrado del plan #{$planId} «{$nombre}». Sin ella no hay prueba de qué se borró. No se escribió nada."
                    : "Hay {$filas->count()} auditorías de borrado del plan #{$planId}: ambiguo. No se escribió nada.",
                $filas->isEmpty() ? 'no_deletion_audit' : 'deletion_audit_ambiguous',
            );
        }

        return $filas->first();
    }

    /**
     * Huella de cada tabla vigilada: número de filas y SHA-256 de su contenido
     * entero, por id. En `payments`, el `plan_id` de los cobros que se enlazan
     * se lee como nulo: es lo único que puede cambiar. En `plans`, se excluye el
     * plan que se restaura (se comprueba aparte).
     *
     * @param  list<int>  $enlazados
     * @return array<string, array{rows: int, sha256: string}>
     */
    private function fingerprints(int $planId, array $enlazados): array
    {
        $fuera = array_flip($enlazados);
        $huellas = [
            'payments' => $this->fingerprint('payments', function (array $fila) use ($fuera): array {
                if (isset($fuera[(int) $fila['id']])) {
                    $fila['plan_id'] = null;
                }

                return $fila;
            }),
            'plans' => $this->fingerprint('plans', fn (array $fila): ?array => (int) $fila['id'] === $planId ? null : $fila),
        ];
        foreach (self::GUARDED_TABLES as $tabla) {
            $huellas[$tabla] = $this->fingerprint($tabla);
        }

        return $huellas;
    }

    /** @return array{rows: int, sha256: string} */
    private function fingerprint(string $tabla, ?callable $normalizar = null): array
    {
        $hash = hash_init('sha256');
        $filas = 0;
        foreach (DB::table($tabla)->lazyById(1000) as $fila) {
            $fila = (array) $fila;
            if ($normalizar !== null && ($fila = $normalizar($fila)) === null) {
                continue;
            }
            hash_update($hash, json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
            $filas++;
        }

        return ['rows' => $filas, 'sha256' => hash_final($hash)];
    }

    /**
     * La fila tal como se inserta: los valores del snapshot, ARCHIVADA (las
     * tres banderas apagadas y el archivo firmado por quien ejecuta), y con
     * `updated_at` de hoy (las banderas cambian ahora; `created_at` se conserva).
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function planRow(array $fila, Admin $actor, string $motivo): array
    {
        $insertar = [];
        foreach ($fila as $columna => $valor) {
            $insertar[$columna] = is_array($valor)
                ? json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $valor;
        }
        $ahora = now();

        return array_merge($insertar, self::ARCHIVED, [
            'updated_at' => $ahora,
            'archived_at' => $ahora,
            'archived_by' => $actor->id,
            'archive_reason' => $motivo,
        ]);
    }

    /** «Plan eliminado el 16-10-2026 y restaurado…», con el día del borrado en Neiva. */
    public static function archiveReason(string $momentoBorradoUtc): string
    {
        $dia = CarbonImmutable::parse($momentoBorradoUtc, 'UTC')->setTimezone(MembershipPeriod::TZ)->format('d-m-Y');

        return "Plan eliminado el {$dia} y restaurado como histórico para conservar la trazabilidad de sus cobros";
    }

    /** @param  array<string, mixed>  $fila */
    private function checkRestoredPlan(int $planId, array $fila): void
    {
        $plan = DB::table('plans')->where('id', $planId)->first();
        $json = fn ($v) => is_string($v) ? json_decode($v, true) : $v;
        $ok = $plan !== null
            && (string) $plan->name === (string) $fila['name']
            && ! (bool) $plan->active && ! (bool) $plan->sellable && ! (bool) $plan->visible_in_app
            && $plan->archived_at !== null
            && (! array_key_exists('price', $fila) || (float) $plan->price === (float) $fila['price'])
            && (! array_key_exists('duration_days', $fila) || (int) $plan->duration_days === (int) $fila['duration_days'])
            && (! array_key_exists('features', $fila) || $json($plan->features) == $json($fila['features']));
        if (! $ok) {
            throw new DeletedPlanRestoreException("El plan #{$planId} no quedó como dice el snapshot. No se escribió nada.", 'plan_check_failed');
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Antes de la transacción
    // ────────────────────────────────────────────────────────────────────────

    /** @return array{path: string, sha256: string, source: ?string, dump_sha256: ?string, dump_sha256_matches: ?bool, row: array<string, mixed>} */
    private function readSnapshot(int $planId, string $ruta, ?string $esperado): array
    {
        if ($planId <= 0) {
            throw new DeletedPlanRestoreException('--plan tiene que ser un id positivo.', 'invalid_plan_id');
        }
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new DeletedPlanRestoreException("No se puede leer el snapshot: {$ruta}", 'snapshot_unreadable');
        }
        $contenido = (string) file_get_contents($ruta);
        $sha = hash('sha256', $contenido);
        if ($esperado !== null && ! hash_equals(strtolower($esperado), $sha)) {
            throw new DeletedPlanRestoreException("El SHA-256 del snapshot ({$sha}) no es el esperado. No se escribió nada.", 'snapshot_sha_mismatch');
        }

        $datos = json_decode($contenido, true);
        $fila = is_array($datos) ? ($datos['row'] ?? null) : null;
        if (! is_array($datos) || ($datos['table'] ?? null) !== 'plans' || ! is_array($fila)) {
            throw new DeletedPlanRestoreException('El snapshot no es una fila de `plans` (se espera {table: "plans", row: {...}}).', 'snapshot_invalid');
        }
        if (! isset($fila['id']) || (int) $fila['id'] !== $planId || ! is_int($fila['id'])) {
            throw new DeletedPlanRestoreException(
                'El snapshot es del plan #'.json_encode($fila['id'] ?? null)." y se pidió el #{$planId}. No se escribió nada.",
                'snapshot_id_mismatch',
            );
        }
        if (! is_string($fila['name'] ?? null) || trim($fila['name']) === '') {
            throw new DeletedPlanRestoreException('El snapshot no trae el nombre del plan.', 'snapshot_invalid');
        }

        return [
            'path' => $ruta,
            'sha256' => $sha,
            'source' => is_string($datos['source'] ?? null) ? $datos['source'] : null,
            'dump_sha256' => is_string($datos['dump_sha256'] ?? null) ? $datos['dump_sha256'] : null,
            'dump_sha256_matches' => isset($datos['dump_sha256_matches_SHA256SUMS']) ? (bool) $datos['dump_sha256_matches_SHA256SUMS'] : null,
            'row' => $fila,
        ];
    }

    private function actor(int $actorId): Admin
    {
        $actor = Admin::query()->find($actorId);
        if ($actor === null || ! $actor->isActive()) {
            throw new DeletedPlanRestoreException(
                "--actor={$actorId} no es un administrador activo. La traza necesita una persona real.",
                'invalid_actor',
            );
        }

        return $actor;
    }

    /**
     * Toda columna del snapshot tiene que existir hoy en `plans`. Las que la
     * tabla ganó después del snapshot toman su valor por defecto y se informan.
     *
     * @param  array<string, mixed>  $fila
     * @return array{missing: list<string>}
     */
    private function checkColumns(array $fila): array
    {
        $tabla = Schema::getColumnListing('plans');
        if (array_diff(self::ARCHIVE_COLUMNS, $tabla) !== []) {
            throw new DeletedPlanRestoreException(
                'La tabla `plans` todavía no tiene las columnas del archivo: ejecuta antes la migración '.
                '2026_10_31_100002_add_archive_to_plans_table. No se escribió nada.',
                'schema_outdated',
            );
        }
        $sobran = array_values(array_diff(array_keys($fila), $tabla));
        if ($sobran !== []) {
            throw new DeletedPlanRestoreException(
                'El snapshot trae columnas que `plans` ya no tiene: '.implode(', ', $sobran).'. No se escribió nada.',
                'snapshot_columns_mismatch',
            );
        }

        return ['missing' => array_values(array_diff($tabla, array_keys($fila), self::ARCHIVE_COLUMNS))];
    }

    /** @param  array<string, mixed>  $fila */
    private function checkTaxRate(array $fila): void
    {
        $tarifa = $fila['tax_rate_id'] ?? null;
        if ($tarifa !== null && Schema::hasTable('tax_rates') && ! DB::table('tax_rates')->where('id', $tarifa)->exists()) {
            throw new DeletedPlanRestoreException(
                "El snapshot apunta a la tarifa de IVA #{$tarifa}, que ya no existe. No se escribió nada.",
                'tax_rate_missing',
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Informe
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Dónde va la secuencia de `plans`. Un id explícito no la mueve: si quedara
     * por debajo del restaurado, el próximo plan nuevo chocaría al llegar a él.
     * Se informa y NO se toca.
     *
     * @return array{driver: string, last_value: ?int, below_restored_id: ?bool}
     */
    private function sequence(int $planId): array
    {
        $driver = DB::connection()->getDriverName();
        $valor = null;
        if ($driver === 'pgsql') {
            $fila = DB::selectOne(
                "SELECT s.last_value FROM pg_sequences s WHERE quote_ident(s.schemaname) || '.' || quote_ident(s.sequencename) = pg_get_serial_sequence('plans', 'id')"
            );
            $valor = $fila?->last_value !== null ? (int) $fila->last_value : null;
        } elseif ($driver === 'sqlite' && DB::table('sqlite_master')->where('name', 'sqlite_sequence')->exists()) {
            $seq = DB::table('sqlite_sequence')->where('name', 'plans')->value('seq');
            $valor = $seq !== null ? (int) $seq : null;
        }

        return ['driver' => $driver, 'last_value' => $valor, 'below_restored_id' => $valor === null ? null : $valor < $planId];
    }

    /** La petición con la que firma la traza: la persona de `--actor`, desde la consola. */
    private function request(Admin $actor): Request
    {
        $request = Request::create('/'.self::SOURCE, 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => self::SOURCE,
        ]);
        $request->attributes->set('auth_admin', $actor);

        return $request;
    }

    /** «insert plans», «update payments»…, o null si la sentencia no escribe. */
    public static function writeSignature(string $sql): ?string
    {
        if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+["`]?([a-z0-9_]+)["`]?/i', $sql, $m) === 1) {
            return strtolower(strtok($m[1], " \t\n")).' '.strtolower($m[2]);
        }
        if (preg_match('/^\s*(alter|create|drop|truncate|replace|merge|copy|grant|revoke)\b/i', $sql, $m) === 1) {
            return strtolower($m[1]).' ?';
        }

        return null;
    }
}
