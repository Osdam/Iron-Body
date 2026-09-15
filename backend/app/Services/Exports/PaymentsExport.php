<?php

namespace App\Services\Exports;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cobros del gimnasio, con socio, plan y desglose por medio de pago.
 *
 * Además del método, ofrece una columna de importe POR MEDIO (efectivo,
 * transferencia, tarjeta…). Es lo que permite cuadrar en Excel con una suma de
 * columna: un cobro mixto de 60.000 aporta 20.000 a cada una de sus tres, en vez
 * de 60.000 a una sola. Sale de Payment::amountsByMethod(), la misma función que
 * usan el arqueo de caja y Finanzas, así que el fichero cuadra con ellos.
 */
class PaymentsExport extends ExportDataset
{
    /** Grupos de estado del filtro. Mismo vocabulario que las tarjetas de Pagos. */
    private const STATUS_GROUPS = [
        'paid' => PaymentController::PAID_STATUSES,
        'pending' => PaymentController::PENDING_STATUSES,
        'failed_or_cancelled' => PaymentController::FAILED_STATUSES,
        'refunded' => ['refunded', 'reembolsado', 'reembolsada'],
    ];

    /** Etiquetas de los valores de `payments.method` que usa el CRM. */
    private const METHOD_LABELS = [
        'cash' => 'Efectivo',
        'efectivo' => 'Efectivo',
        'transfer' => 'Transferencia',
        'transferencia' => 'Transferencia',
        'card' => 'Tarjeta',
        'tarjeta' => 'Tarjeta',
        'datafono' => 'Tarjeta',
        'pse' => 'PSE',
        'nequi' => 'Nequi / Daviplata',
        'daviplata' => 'Nequi / Daviplata',
        'wompi' => 'Wompi',
        'epayco' => 'ePayco',
        'other' => 'Otro',
        'manual' => 'Manual (histórico)',
        Payment::MIXED_METHOD => 'Mixto',
    ];

    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Pagos';
    }

    public function description(): string
    {
        return 'Cobros con socio, plan, estado y desglose por medio de pago.';
    }

    public function icon(): string
    {
        return 'payments';
    }

    public function permission(): string
    {
        return 'payments.export';
    }

    public function fileSlug(): string
    {
        return 'pagos';
    }

    public function columns(): array
    {
        $texto = ExportColumn::TYPE_TEXT;
        $numero = ExportColumn::TYPE_NUMBER;
        $fecha = ExportColumn::TYPE_DATE;

        $columnas = [
            // ── Cobro ──────────────────────────────────────────────────────
            new ExportColumn('id', 'ID', 'Cobro', fn (Payment $p) => $p->id, $numero),
            new ExportColumn('date', 'Fecha', 'Cobro', fn (Payment $p) => ($p->paid_at ?? $p->created_at)?->toDateString(), $fecha),
            new ExportColumn('status', 'Estado', 'Cobro', fn (Payment $p) => $this->statusLabel($p->status)),
            new ExportColumn('amount', 'Monto', 'Cobro', fn (Payment $p) => (float) $p->amount, $numero),
            new ExportColumn('method', 'Método', 'Cobro', fn (Payment $p) => self::methodLabel($p->method)),
            new ExportColumn('breakdown', 'Desglose', 'Cobro', fn (Payment $p) => self::breakdown($p)),
            new ExportColumn('reference', 'Referencia', 'Cobro', fn (Payment $p) => $p->reference),

            // ── Socio ──────────────────────────────────────────────────────
            new ExportColumn('member_name', 'Socio', 'Socio', fn (Payment $p) => $p->user?->name),
            new ExportColumn('member_document', 'Documento', 'Socio', fn (Payment $p) => $p->user?->document, personal: true),
            new ExportColumn('member_email', 'Correo', 'Socio', fn (Payment $p) => $p->user?->email, default: false, personal: true),
            new ExportColumn('member_phone', 'Teléfono', 'Socio', fn (Payment $p) => $p->user?->phone, default: false, personal: true),

            // ── Plan y caja ────────────────────────────────────────────────
            new ExportColumn('plan', 'Plan', 'Plan y caja', fn (Payment $p) => $p->plan?->name),
            new ExportColumn('cash_shift', 'Turno de caja', 'Plan y caja', fn (Payment $p) => $p->cash_shift_id, $numero, default: false),
            new ExportColumn('channel', 'Canal', 'Plan y caja', fn (Payment $p) => PaymentOrigin::channelLabelFor($p->channel())),
            new ExportColumn('registered_by', 'Registró', 'Plan y caja', fn (Payment $p) => $p->registered_by_name),
            new ExportColumn('registered_by_role', 'Rol de quien registró', 'Plan y caja', fn (Payment $p) => $p->registered_by_role, default: false),

            // ── Periodo de membresía que cubrió ────────────────────────────
            new ExportColumn('period_start', 'Membresía desde', 'Membresía', fn (Payment $p) => $p->period_start?->toDateString(), $fecha, default: false),
            new ExportColumn('period_end', 'Membresía hasta', 'Membresía', fn (Payment $p) => $p->period_end?->toDateString(), $fecha, default: false),
        ];

        // ── Importe por medio ───────────────────────────────────────────────
        foreach ([PaymentMethodKind::CASH, PaymentMethodKind::TRANSFER, PaymentMethodKind::CARD, PaymentMethodKind::WOMPI, PaymentMethodKind::OTHER] as $medio) {
            $columnas[] = new ExportColumn(
                'by_'.$medio->value,
                'En '.mb_strtolower($medio->label()),
                'Importe por medio',
                fn (Payment $p) => (float) ($p->amountsByMethod()[$medio->value] ?? 0),
                $numero,
                default: false,
            );
        }

        return $columnas;
    }

    public function filters(): array
    {
        return [
            ['key' => 'from', 'label' => 'Desde', 'type' => 'date'],
            ['key' => 'to', 'label' => 'Hasta', 'type' => 'date'],
            [
                'key' => 'status',
                'label' => 'Estado',
                'type' => 'select',
                'options' => [
                    ['value' => 'all', 'label' => 'Todos'],
                    ['value' => 'paid', 'label' => 'Pagados'],
                    ['value' => 'pending', 'label' => 'Pendientes'],
                    ['value' => 'failed_or_cancelled', 'label' => 'Fallidos o anulados'],
                    ['value' => 'refunded', 'label' => 'Reembolsados'],
                ],
            ],
            [
                'key' => 'method',
                'label' => 'Medio de pago',
                'type' => 'select',
                'options' => [
                    ['value' => 'all', 'label' => 'Todos'],
                    ['value' => PaymentMethodKind::CASH->value, 'label' => 'Incluye efectivo'],
                    ['value' => PaymentMethodKind::TRANSFER->value, 'label' => 'Incluye transferencia / Nequi'],
                    ['value' => PaymentMethodKind::CARD->value, 'label' => 'Incluye tarjeta'],
                    ['value' => PaymentMethodKind::WOMPI->value, 'label' => 'Incluye Wompi'],
                    ['value' => Payment::MIXED_METHOD, 'label' => 'Solo mixtos'],
                ],
            ],
            [
                'key' => 'channel',
                'label' => 'Canal',
                'type' => 'select',
                'options' => [
                    ['value' => 'all', 'label' => 'Todos'],
                    ['value' => 'gym', 'label' => 'Gimnasio (caja)'],
                    ['value' => 'app', 'label' => 'App'],
                    ['value' => 'legacy', 'label' => 'Sistema anterior'],
                ],
            ],
        ];
    }

    public function filterRules(): array
    {
        return [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|in:all,'.implode(',', array_keys(self::STATUS_GROUPS)),
            'method' => 'nullable|in:all,cash,transfer,card,wompi,'.Payment::MIXED_METHOD,
            'channel' => 'nullable|in:all,gym,app,legacy',
        ];
    }

    public function query(array $filters): Builder
    {
        $q = Payment::query()
            ->with(['user:id,name,document,email,phone', 'plan:id,name', 'splits'])
            ->orderBy('id');

        $fecha = 'COALESCE(payments.paid_at, payments.created_at)';
        if (filled($filters['from'] ?? null)) {
            $q->whereRaw("{$fecha} >= ?", [$filters['from'].' 00:00:00']);
        }
        if (filled($filters['to'] ?? null)) {
            $q->whereRaw("{$fecha} <= ?", [$filters['to'].' 23:59:59']);
        }

        $grupo = self::STATUS_GROUPS[$filters['status'] ?? ''] ?? null;
        if ($grupo) {
            $q->whereRaw('LOWER(payments.status) IN ('.$this->marks($grupo).')', $grupo);
        }

        $metodo = $filters['method'] ?? 'all';
        if ($metodo === Payment::MIXED_METHOD) {
            $q->where('payments.method', Payment::MIXED_METHOD);
        } elseif ($metodo !== 'all' && ($medio = PaymentMethodKind::tryFrom($metodo))) {
            // «Incluye tarjeta» debe traer también los mixtos con una parte en
            // tarjeta: quien filtra por medio quiere todo lo que entró por él.
            $alias = $medio->aliases();
            $q->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(payments.method) IN ('.$this->marks($alias).')', $alias)
                ->orWhereHas('splits', fn (Builder $s) => $s
                    ->whereRaw('LOWER(payment_splits.method) IN ('.$this->marks($alias).')', $alias)));
        }

        $canal = $filters['channel'] ?? 'all';
        $origen = collect(PaymentOrigin::cases())->first(fn (PaymentOrigin $o) => $o->channel() === $canal);
        if ($canal !== 'all' && $origen) {
            $q->where('payments.origin', $origen->value);
        }

        return $q;
    }

    // ── Etiquetas compartidas (también las usa MembersExport) ────────────────

    public static function methodLabel(?string $metodo): ?string
    {
        if ($metodo === null || $metodo === '') {
            return null;
        }

        return self::METHOD_LABELS[strtolower($metodo)] ?? ucfirst($metodo);
    }

    /** «Efectivo 20.000 · Nequi / Daviplata 40.000». Vacío si no es mixto. */
    public static function breakdown(?Payment $pago): ?string
    {
        if (! $pago || $pago->splits->isEmpty()) {
            return null;
        }

        return $pago->splits
            ->map(fn ($s) => self::methodLabel($s->method).' '.number_format((float) $s->amount, 0, ',', '.'))
            ->implode(' · ');
    }

    /** Método, y si fue mixto, su desglose entre paréntesis. */
    public static function methodDescription(?Payment $pago): ?string
    {
        if (! $pago) {
            return null;
        }

        $desglose = self::breakdown($pago);

        return $desglose ? 'Mixto ('.$desglose.')' : self::methodLabel($pago->method);
    }

    private function statusLabel(?string $status): string
    {
        $clave = strtolower(trim((string) $status));
        foreach (['paid' => 'Pagado', 'pending' => 'Pendiente', 'refunded' => 'Reembolsado'] as $grupo => $etiqueta) {
            if (in_array($clave, self::STATUS_GROUPS[$grupo], true)) {
                return $etiqueta;
            }
        }
        if (in_array($clave, ['failed'], true)) {
            return 'Fallido';
        }
        if (in_array($clave, PaymentController::FAILED_STATUSES, true)) {
            return 'Anulado';
        }

        return $clave === '' ? 'Pendiente' : ucfirst($clave);
    }

    /** @param  list<string>  $valores */
    private function marks(array $valores): string
    {
        return implode(', ', array_fill(0, count($valores), '?'));
    }
}
