<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LA DEUDA VISTA POR PERSONA, no por apunte.
 *
 * El caso real que lo pide: alguien se lleva un agua fiada el lunes, otra el
 * martes y otra el jueves. Son tres obligaciones distintas —y tienen que
 * seguirlo siendo, porque cada una nació un día y puede tener su plazo—, pero a
 * la hora de cobrar nadie quiere recorrer tres fichas: quiere ver «Oscar debe
 * 6.000» con las tres líneas debajo y cobrarlas de una vez.
 *
 * Esto es lo que arma esa vista. NO crea, junta ni reescribe obligaciones: las
 * agrupa para leerlas. Fusionarlas en una sola deuda «acumulada» habría sido la
 * otra forma de resolverlo, y es la mala: se perdería qué se llevó cada día,
 * qué plazo tenía cada consumo y contra qué venta de producto nació.
 *
 * DOS CAJAS, UNA PERSONA. Un socio puede deber un agua (caja de productos) y el
 * saldo de su plan (caja del gimnasio). La tarjeta suma las dos cosas —es lo que
 * debe— pero cada línea conserva SU caja, porque el dinero se arquea aparte y
 * eso no se negocia al cobrar.
 *
 * TODO SE AGREGA EN SQL. Agrupar en PHP obligaría a descargar todas las deudas
 * abiertas del gimnasio para enseñar veinte tarjetas.
 */
final class DebtorAccounts
{
    /** Personas por página. Son tarjetas con lista dentro: no caben más. */
    public const PER_PAGE = 12;

    public const MAX_PER_PAGE = 50;

    /**
     * Cuántas líneas se mandan dentro de cada tarjeta.
     *
     * Con más de esto la tarjeta deja de ser legible y el caso ya no es «tres
     * aguas»: es una cuenta que conviene abrir entera. El total NUNCA se recorta
     * —se calcula aparte, en SQL— así que la cifra que se cobra es la completa.
     */
    public const MAX_ITEMS = 40;

    public function __construct(private readonly DebtorDirectory $directory) {}

    /**
     * Una página de personas con deuda viva.
     *
     * @param  array{type?: ?string, search?: ?string, only_overdue?: bool}  $filtros
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, summary: array<string, mixed>}
     */
    public function page(array $filtros = [], int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        $agrupado = $this->grouped($filtros);

        // El total de personas y el total de dinero salen de la MISMA consulta
        // agrupada, envuelta: contar filas de un GROUP BY con `count()` devuelve
        // una fila por grupo, no el número de grupos.
        $resumen = DB::query()->fromSub($agrupado, 'g')
            ->selectRaw('COUNT(*) AS personas, COALESCE(SUM(g.saldo), 0) AS pendiente,'
                .' COALESCE(SUM(g.deudas), 0) AS deudas,'
                .' COUNT(CASE WHEN g.vencido > 0 THEN 1 END) AS personas_vencidas,'
                .' COALESCE(SUM(g.vencido), 0) AS vencido')
            ->first();

        $filas = $agrupado
            ->orderByDesc('vencido')
            ->orderByDesc('saldo')
            ->orderBy('debtor_type')
            ->orderBy('debtor_id')
            ->forPage($page, $perPage)
            ->get();

        $personas = $filas->count();
        $total = (int) ($resumen->personas ?? 0);

        return [
            'data' => $this->cardsFor($filas),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $personas > 0 ? ($page - 1) * $perPage + 1 : 0,
                'to' => $personas > 0 ? ($page - 1) * $perPage + $personas : 0,
            ],
            'summary' => [
                'people' => $total,
                'debts' => (int) ($resumen->deudas ?? 0),
                'outstanding_total' => round((float) ($resumen->pendiente ?? 0), 2),
                'overdue_people' => (int) ($resumen->personas_vencidas ?? 0),
                'overdue_total' => round((float) ($resumen->vencido ?? 0), 2),
            ],
        ];
    }

    /**
     * La tarjeta de UNA persona, para releerla después de cobrarle sin recargar
     * la lista entera.
     *
     * @return array<string, mixed>|null  null si ya no debe nada
     */
    public function card(DebtorType $type, int $id): ?array
    {
        $fila = $this->grouped([])
            ->where('receivables.debtor_type', $type->value)
            ->where('receivables.debtor_id', $id)
            ->first();

        return $fila ? ($this->cardsFor(collect([$fila]))[0] ?? null) : null;
    }

    /**
     * Las obligaciones VIVAS de una persona, de la más antigua a la más nueva.
     *
     * Ese orden es el del cobro: lo que se lleva más tiempo debiendo se salda
     * primero. Cualquier otro orden haría que un abono parcial dejara viva la
     * deuda más vieja, que es justo la que hay que cerrar.
     *
     * @return Collection<int, Receivable>
     */
    public function openDebts(DebtorType $type, int $id, ?CashShiftType $cash = null, array $onlyIds = []): Collection
    {
        $q = $this->withBalance()
            ->where('receivables.debtor_type', $type->value)
            ->where('receivables.debtor_id', $id)
            ->outstanding()
            ->whereRaw($this->balanceSql().' > 0')
            ->orderBy('receivables.created_at')
            ->orderBy('receivables.id');

        if ($cash !== null) {
            $q->where('receivables.type', $cash->value);
        }

        if ($onlyIds !== []) {
            $q->whereIn('receivables.id', $onlyIds);
        }

        return $q->get();
    }

    // ── Consultas ────────────────────────────────────────────────────────────

    /** El saldo en SQL: lo que se debe menos lo que se ha abonado. */
    private function balanceSql(): string
    {
        return '(receivables.total_amount - COALESCE(ap.aplicado, 0))';
    }

    /**
     * La consulta base con el saldo ya calculado por un `join` agregado.
     *
     * Con `withSum` el saldo no se podría filtrar ni sumar en la misma consulta
     * —queda fuera del WHERE—, y además cae en el mismo atributo que
     * `Receivable::paidAmount()` ya sabe leer, así que pintar una tarjeta no
     * dispara una consulta por línea.
     */
    private function withBalance(): Builder
    {
        $abonos = DB::table('receivable_payments')
            ->selectRaw('receivable_id, SUM(amount) AS aplicado')
            ->where('status', ReceivablePayment::STATUS_APPLIED)
            ->groupBy('receivable_id');

        return Receivable::query()
            ->leftJoinSub($abonos, 'ap', 'ap.receivable_id', '=', 'receivables.id')
            ->select('receivables.*')
            ->selectRaw('COALESCE(ap.aplicado, 0) AS applied_payments_sum_amount');
    }

    /**
     * Una fila por PERSONA con sus totales.
     *
     * @param  array{type?: ?string, search?: ?string, only_overdue?: bool}  $filtros
     */
    private function grouped(array $filtros): \Illuminate\Database\Query\Builder
    {
        $hoy = Receivable::businessToday()->toDateString();
        $saldo = $this->balanceSql();

        $abonos = DB::table('receivable_payments')
            ->selectRaw('receivable_id, SUM(amount) AS aplicado')
            ->where('status', ReceivablePayment::STATUS_APPLIED)
            ->groupBy('receivable_id');

        $q = DB::table('receivables')
            ->leftJoinSub($abonos, 'ap', 'ap.receivable_id', '=', 'receivables.id')
            ->whereIn('receivables.status', [Receivable::STATUS_PENDING, Receivable::STATUS_PARTIALLY_PAID])
            ->whereRaw($saldo.' > 0')
            ->whereNotNull('receivables.debtor_type')
            ->whereNotNull('receivables.debtor_id')
            ->groupBy('receivables.debtor_type', 'receivables.debtor_id')
            ->select('receivables.debtor_type', 'receivables.debtor_id')
            ->selectRaw('COUNT(*) AS deudas')
            ->selectRaw('COALESCE(SUM('.$saldo.'), 0) AS saldo')
            ->selectRaw('MIN(receivables.created_at) AS desde')
            // Vencido: lo que ya pasó su plazo. Se suma aparte porque es lo que
            // decide a quién se llama primero.
            ->selectRaw('COALESCE(SUM(CASE WHEN receivables.due_at IS NOT NULL AND receivables.due_at < ?'
                .' THEN '.$saldo.' ELSE 0 END), 0) AS vencido', [$hoy])
            ->selectRaw('MIN(CASE WHEN receivables.due_at IS NOT NULL AND receivables.due_at < ?'
                .' THEN receivables.due_at END) AS vencio_el', [$hoy]);

        if (! empty($filtros['type'])) {
            $q->where('receivables.type', $filtros['type']);
        }

        if (! empty($filtros['only_overdue'])) {
            $q->havingRaw('COALESCE(SUM(CASE WHEN receivables.due_at IS NOT NULL AND receivables.due_at < ?'
                .' THEN '.$saldo.' ELSE 0 END), 0) > 0', [$hoy]);
        }

        if (! empty($filtros['search'])) {
            // La búsqueda se resuelve contra los nombres del directorio y se
            // traduce a claves de deudor: `receivables` no guarda el nombre, y
            // buscar por `concept` no encontraría a nadie por su documento.
            $claves = $this->directory->keysMatching(trim((string) $filtros['search']));

            if ($claves === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where(function ($sub) use ($claves): void {
                    foreach ($claves as [$tipo, $id]) {
                        $sub->orWhere(fn ($w) => $w
                            ->where('receivables.debtor_type', $tipo)
                            ->where('receivables.debtor_id', $id));
                    }
                });
            }
        }

        return $q;
    }

    /**
     * Convierte las filas agrupadas en tarjetas, con sus líneas y su ficha.
     *
     * Tres consultas en total, pase lo que pase: los nombres de todas las
     * personas de la página en una, y las líneas de todas ellas en otra. Uno a
     * uno serían dos consultas por tarjeta.
     *
     * @param  Collection<int, object>  $filas
     * @return list<array<string, mixed>>
     */
    private function cardsFor(Collection $filas): array
    {
        if ($filas->isEmpty()) {
            return [];
        }

        $pares = $filas->map(fn ($f) => [DebtorType::tryFrom((string) $f->debtor_type), (int) $f->debtor_id])
            ->filter(fn (array $p) => $p[0] !== null)
            ->values();

        $fichas = $this->directory->resolveMany($pares->all());
        $lineas = $this->itemsFor($pares);

        return $filas->map(function ($f) use ($fichas, $lineas) {
            $tipo = DebtorType::tryFrom((string) $f->debtor_type);
            $clave = $f->debtor_type.':'.$f->debtor_id;
            $ficha = $fichas[$clave] ?? null;
            $items = $lineas->get($clave, collect());

            return [
                'key' => $clave,
                'debtor' => [
                    'type' => $f->debtor_type,
                    'type_label' => $tipo?->label(),
                    'id' => (int) $f->debtor_id,
                    'name' => $ficha['name'] ?? 'Deudor #'.$f->debtor_id,
                    'document' => $ficha['document'] ?? null,
                    'contact' => $ficha['contact'] ?? null,
                    // Solo los socios tienen ficha en el CRM a la que saltar.
                    'member_id' => $tipo === DebtorType::MEMBER ? (int) $f->debtor_id : null,
                ],
                'outstanding' => round((float) $f->saldo, 2),
                'debts_count' => (int) $f->deudas,
                'oldest_at' => $f->desde ? (string) $f->desde : null,
                'overdue' => [
                    'amount' => round((float) $f->vencido, 2),
                    'since' => $f->vencio_el ? substr((string) $f->vencio_el, 0, 10) : null,
                    'days' => $f->vencio_el ? $this->daysSince((string) $f->vencio_el) : 0,
                ],
                // Cada caja aparte: es lo que decide en qué turno entra el dinero.
                'by_cash' => [
                    CashShiftType::GYM->value => round((float) $items
                        ->where('type', CashShiftType::GYM->value)->sum('balance'), 2),
                    CashShiftType::PRODUCTS->value => round((float) $items
                        ->where('type', CashShiftType::PRODUCTS->value)->sum('balance'), 2),
                ],
                'cash_types' => $items->pluck('type')->unique()->values()->all(),
                'items' => $items->values()->all(),
                'items_truncated' => (int) $f->deudas > $items->count(),
            ];
        })->values()->all();
    }

    /**
     * Las líneas de todas las personas de la página, agrupadas por su clave.
     *
     * @param  Collection<int, array{0: DebtorType, 1: int}>  $pares
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function itemsFor(Collection $pares): Collection
    {
        $q = $this->withBalance()
            ->outstanding()
            ->whereRaw($this->balanceSql().' > 0')
            ->where(function (Builder $w) use ($pares): void {
                foreach ($pares as [$tipo, $id]) {
                    $w->orWhere(fn (Builder $s) => $s
                        ->where('receivables.debtor_type', $tipo->value)
                        ->where('receivables.debtor_id', $id));
                }
            })
            // De la más antigua a la más nueva: el mismo orden en el que se
            // cobra, para que la lista se lea como se va a saldar.
            ->orderBy('receivables.created_at')
            ->orderBy('receivables.id');

        return $q->get()
            ->groupBy(fn (Receivable $r) => $r->debtor_type?->value.':'.$r->debtor_id)
            ->map(fn (Collection $grupo) => $grupo
                ->take(self::MAX_ITEMS)
                ->map(fn (Receivable $r) => [
                    'id' => $r->id,
                    'concept' => $r->concept,
                    'type' => $r->type->value,
                    'type_label' => $r->type->label(),
                    'created_at' => optional($r->created_at)->toIso8601String(),
                    'total_amount' => $r->total()->toFloat(),
                    'paid_amount' => $r->paidAmount()->toFloat(),
                    'balance' => $r->balance()->toFloat(),
                    'status' => $r->status,
                    'status_label' => Receivable::statusLabel($r->status),
                    'due_date' => optional($r->due_at)->toDateString(),
                    'overdue' => $r->isOverdue(),
                    'days_overdue' => $r->daysOverdue(),
                    'source_type' => $r->source_type ? class_basename($r->source_type) : null,
                    'created_by_name' => $r->created_by_name,
                ])
                ->values());
    }

    /**
     * Días completos desde una fecha, contados en el calendario del NEGOCIO.
     *
     * Las dos fechas se llevan a medianoche en la MISMA zona antes de restar: si
     * una queda en UTC y la otra en Bogotá, la diferencia trae cinco horas de
     * sesgo y «lleva 8 días vencida» se convierte en 7.
     */
    private function daysSince(string $fecha): int
    {
        $hoy = Receivable::businessToday();
        $dia = \Carbon\Carbon::parse(substr($fecha, 0, 10), $hoy->timezone)->startOfDay();

        return (int) $dia->diffInDays($hoy, false);
    }
}
