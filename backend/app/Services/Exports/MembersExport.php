<?php

namespace App\Services\Exports;

use App\Models\Payment;
use App\Support\Members\MembershipFilter;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Socios con su membresía y su último pago.
 *
 * Una fila por socio. La «situación» de la membresía se calcula aquí y no se
 * toma de `users.status`, porque ese campo dice si la CUENTA está activa, no si
 * la membresía sigue vigente: hay socios con cuenta activa y membresía vencida
 * hace meses, y confundirlos era justo el problema de la tarjeta de «nuevos
 * miembros» de Analítica.
 */
class MembersExport extends ExportDataset
{
    /**
     * Días para considerar una membresía «por vencer». Se toma de
     * MembershipFilter para que la columna «Situación» diga lo mismo que el
     * filtro con el que se pidió el fichero: con dos umbrales, una exportación
     * de «vencen en 7 días» podría traer filas marcadas como «Activa».
     */
    public const EXPIRING_SOON_DAYS = MembershipFilter::EXPIRING_SOON_DAYS;

    /** Zona horaria del negocio: «vence hoy» es hoy en Neiva, no en UTC. */
    private const TZ = MembershipFilter::TZ;

    public function key(): string
    {
        return 'members';
    }

    public function label(): string
    {
        return 'Miembros';
    }

    public function description(): string
    {
        return 'Socios con su plan, fechas de membresía, situación y último pago.';
    }

    public function icon(): string
    {
        return 'group';
    }

    public function permission(): string
    {
        return 'members.export';
    }

    public function fileSlug(): string
    {
        return 'miembros';
    }

    public function columns(): array
    {
        $texto = ExportColumn::TYPE_TEXT;
        $numero = ExportColumn::TYPE_NUMBER;
        $fecha = ExportColumn::TYPE_DATE;

        return [
            // ── Datos del socio ────────────────────────────────────────────
            new ExportColumn('id', 'ID', 'Socio', fn (User $u) => $u->id, $numero, default: false),
            new ExportColumn('name', 'Nombre', 'Socio', fn (User $u) => $u->name),
            new ExportColumn('document', 'Documento', 'Socio', fn (User $u) => $u->document, personal: true),
            new ExportColumn('phone', 'Teléfono', 'Socio', fn (User $u) => $u->phone, personal: true),
            new ExportColumn('email', 'Correo', 'Socio', fn (User $u) => $u->email, personal: true),
            new ExportColumn('gender', 'Género', 'Socio', fn (User $u) => $u->gender, default: false),
            new ExportColumn('birth_date', 'Fecha de nacimiento', 'Socio', fn (User $u) => $this->dateOf($u->birth_date), $fecha, default: false, personal: true),
            new ExportColumn('address', 'Dirección', 'Socio', fn (User $u) => $u->address, default: false, personal: true),
            new ExportColumn('emergency_contact', 'Contacto de emergencia', 'Socio', fn (User $u) => $u->emergency_contact, default: false, personal: true),
            new ExportColumn('account_status', 'Estado de la cuenta', 'Socio', fn (User $u) => $this->accountStatusLabel($u->status)),
            new ExportColumn('registered_at', 'Fecha de registro', 'Socio', fn (User $u) => $u->created_at?->toDateString(), $fecha, default: false),

            // ── Membresía ──────────────────────────────────────────────────
            new ExportColumn('plan', 'Plan', 'Membresía', fn (User $u) => $u->plan),
            new ExportColumn('membership_start', 'Inicio de membresía', 'Membresía', fn (User $u) => $u->membership_start_date, $fecha),
            new ExportColumn('membership_end', 'Vencimiento', 'Membresía', fn (User $u) => $u->membership_end_date, $fecha),
            new ExportColumn('days_left', 'Días restantes', 'Membresía', fn (User $u) => $this->daysLeft($u), $numero),
            new ExportColumn('membership_state', 'Situación', 'Membresía', fn (User $u) => $this->membershipLabel($u)),
            new ExportColumn('auto_renew', 'Renovación automática', 'Membresía', fn (User $u) => $u->membership_auto_renew ? 'Sí' : 'No', default: false),

            // ── Último pago ────────────────────────────────────────────────
            new ExportColumn('last_payment_date', 'Último pago: fecha', 'Último pago', fn (User $u) => $this->paymentDate($u->lastPaidPayment), $fecha),
            new ExportColumn('last_payment_amount', 'Último pago: monto', 'Último pago', fn (User $u) => $u->lastPaidPayment ? (float) $u->lastPaidPayment->amount : null, $numero),
            new ExportColumn('last_payment_method', 'Último pago: método', 'Último pago', fn (User $u) => PaymentsExport::methodDescription($u->lastPaidPayment), default: false),
            new ExportColumn('last_payment_reference', 'Último pago: referencia', 'Último pago', fn (User $u) => $u->lastPaidPayment?->reference, default: false),
        ];
    }

    public function filters(): array
    {
        $planes = Plan::query()->pluck('name')
            ->merge(User::query()->whereNotNull('plan')->where('plan', '!=', '')->distinct()->pluck('plan'))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            [
                'key' => 'membership',
                'label' => 'Situación de la membresía',
                'type' => 'select',
                // Mismas opciones que el listado de Miembros: el mismo nombre
                // tiene que significar lo mismo en las dos pantallas.
                'options' => MembershipFilter::options(),
            ],
            [
                'key' => 'account_status',
                'label' => 'Estado de la cuenta',
                'type' => 'select',
                'options' => [
                    ['value' => 'all', 'label' => 'Todas'],
                    ['value' => 'active', 'label' => 'Activas'],
                    ['value' => 'inactive', 'label' => 'Inactivas'],
                    ['value' => 'pending', 'label' => 'Pendientes'],
                ],
            ],
            [
                'key' => 'plan',
                'label' => 'Plan',
                'type' => 'select',
                'options' => [
                    ['value' => '', 'label' => 'Todos los planes'],
                    ...$planes->map(fn (string $n) => ['value' => $n, 'label' => $n])->all(),
                ],
            ],
            // Control fino cuando los atajos de arriba no bastan: «las que
            // vencen entre el 1 y el 15», «los inscritos el mes pasado».
            ['key' => 'end_from', 'label' => 'Vence desde', 'type' => 'date'],
            ['key' => 'end_to', 'label' => 'Vence hasta', 'type' => 'date'],
            ['key' => 'created_from', 'label' => 'Inscrito desde', 'type' => 'date'],
            ['key' => 'created_to', 'label' => 'Inscrito hasta', 'type' => 'date'],
            ['key' => 'search', 'label' => 'Buscar nombre, documento o correo', 'type' => 'text'],
        ];
    }

    public function filterRules(): array
    {
        return [
            'membership' => 'nullable|in:'.implode(',', MembershipFilter::allowedValues()),
            'account_status' => 'nullable|in:all,active,inactive,pending',
            'plan' => 'nullable|string|max:120',
            'end_from' => 'nullable|date',
            'end_to' => 'nullable|date|after_or_equal:end_from',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',
            'search' => 'nullable|string|max:120',
        ];
    }

    public function query(array $filters): Builder
    {
        $q = User::query()
            ->with(['lastPaidPayment.splits'])
            ->orderBy('id');

        MembershipFilter::apply($q, (string) ($filters['membership'] ?? 'all'));

        $cuenta = (string) ($filters['account_status'] ?? 'all');
        if ($cuenta !== 'all' && $cuenta !== '') {
            MembershipFilter::apply($q, $cuenta);
        }

        // Rangos explícitos. Se suman a lo anterior en vez de sustituirlo:
        // «vencidas» + «vence desde el 1» es una pregunta legítima.
        if (filled($filters['end_from'] ?? null)) {
            $q->where('membership_end_date', '>=', $filters['end_from']);
        }
        if (filled($filters['end_to'] ?? null)) {
            $q->where('membership_end_date', '<=', $filters['end_to']);
        }
        if (filled($filters['created_from'] ?? null)) {
            $q->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (filled($filters['created_to'] ?? null)) {
            $q->whereDate('created_at', '<=', $filters['created_to']);
        }

        if (filled($filters['plan'] ?? null)) {
            $q->where('plan', $filters['plan']);
        }

        if (filled($filters['search'] ?? null)) {
            $like = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
            $op = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $q->where(fn (Builder $w) => $w
                ->where('name', $op, $like)
                ->orWhere('document', $op, $like)
                ->orWhere('email', $op, $like));
        }

        return $q;
    }

    // ── Cálculos ──────────────────────────────────────────────────────────────

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** Negativo si ya venció. Null si no tiene fecha de vencimiento. */
    private function daysLeft(User $u): ?int
    {
        if (! $u->membership_end_date) {
            return null;
        }

        $fin = CarbonImmutable::parse($u->membership_end_date, self::TZ)->startOfDay();

        return (int) $this->today()->diffInDays($fin, false);
    }

    private function membershipLabel(User $u): string
    {
        $dias = $this->daysLeft($u);

        return match (true) {
            $dias === null => 'Sin membresía',
            $dias < 0 => 'Vencida',
            $dias <= self::EXPIRING_SOON_DAYS => 'Por vencer',
            default => 'Activa',
        };
    }

    private function accountStatusLabel(?string $status): string
    {
        $clave = strtolower(trim((string) $status)) ?: 'active';

        return match ($clave) {
            'active', 'activo', 'activa' => 'Activa',
            'inactive', 'inactivo', 'inactiva' => 'Inactiva',
            'pending', 'pendiente' => 'Pendiente',
            'expired', 'vencido', 'vencida' => 'Vencida',
            default => ucfirst($clave),
        };
    }

    private function dateOf(mixed $valor): ?string
    {
        if (! $valor) {
            return null;
        }

        return $valor instanceof \DateTimeInterface
            ? $valor->format('Y-m-d')
            : substr((string) $valor, 0, 10);
    }

    private function paymentDate(?Model $pago): ?string
    {
        if (! $pago instanceof Payment) {
            return null;
        }

        return ($pago->paid_at ?? $pago->created_at)?->toDateString();
    }
}
