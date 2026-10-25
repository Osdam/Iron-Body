<?php

namespace App\Services\Billing;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\ManualEmissionRejectedException;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\InvoiceBundle;
use App\Models\InvoiceBundleItem;
use App\Models\Member;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Factura electrónica MULTICONCEPTO desde el CRM.
 *
 * Tres pasos que no se mezclan:
 *
 *  1. candidates(): qué operaciones cobradas tiene el cliente y, de cada una,
 *     si se puede facturar y por qué no. No escribe nada.
 *  2. preview():    el comprobante completo que saldría. No escribe nada.
 *  3. emit():       la persona confirmó. Reserva las operaciones, crea la
 *     solicitud con su autorización sellada y, con Factus activo, encola la
 *     emisión. Lo que sale es EXACTAMENTE lo que se previsualizó: si algo
 *     cambió entre medias, la huella no coincide y no se emite.
 *
 * Qué se puede consolidar (y nada más):
 *  - pago de plan (`payment`) cobrado y rastreable;
 *  - venta de cafetería (`product_sale`) cobrada;
 *  - piso de entrenamiento personalizado (`commission_charge`) que el trato
 *    dice que ASUME EL CLIENTE y que está pagado. Si lo paga el entrenador, el
 *    adquiriente es él: no se le factura a la socia.
 *
 * Una operación respalda una sola factura activa. Lo garantiza el índice único
 * parcial de `invoice_bundle_items`; la ficha del socio bloqueada serializa
 * dos emisiones del mismo cliente.
 */
class ManualInvoiceBundleService
{
    public const MAX_ITEMS = 30;

    /** Cuánto atrás se buscan operaciones del cliente. */
    private const LOOKBACK_DAYS = 400;

    public function __construct(
        private FiscalProfileResolver $resolver,
        private InvoiceDtoBuilder $builder,
        private PaymentOriginInspector $inspector,
        private InvoicingService $invoicing,
    ) {}

    // ── 1. Candidatos ─────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function candidates(Member $member): array
    {
        $desde = now()->subDays(self::LOOKBACK_DAYS);
        $origins = [];

        $payments = Payment::query()
            ->where(fn ($q) => $q->where('member_id', $member->id)
                ->when($member->user_id, fn ($q2) => $q2->orWhere(fn ($q3) => $q3
                    ->whereNull('member_id')->where('user_id', $member->user_id))))
            ->where('created_at', '>=', $desde)
            ->orderByDesc('id')->limit(80)->get();
        foreach ($payments as $p) {
            $origins[] = $this->evaluate(InvoiceBundleItem::ORIGIN_PAYMENT, $p, $member);
        }

        foreach (ProductSale::where('member_id', $member->id)->where('created_at', '>=', $desde)
            ->orderByDesc('id')->limit(80)->get() as $s) {
            $origins[] = $this->evaluate(InvoiceBundleItem::ORIGIN_PRODUCT_SALE, $s, $member);
        }

        $agreementIds = TrainerCommissionAgreement::where('member_id', $member->id)->pluck('id');
        foreach (TrainerCommissionCharge::whereIn('agreement_id', $agreementIds)->where('created_at', '>=', $desde)
            ->orderByDesc('id')->limit(80)->get() as $c) {
            $origins[] = $this->evaluate(InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE, $c, $member);
        }

        usort($origins, fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        return array_map(fn (array $o) => $this->publicOrigin($o), $origins);
    }

    // ── 2. Vista previa ──────────────────────────────────────────────────

    /**
     * @param  list<string>  $keys  «payment:19905», «commission_charge:6»…
     * @return array<string,mixed>
     *
     * @throws ManualEmissionRejectedException
     */
    public function preview(Member $member, array $keys, ?bool $finalConsumer): array
    {
        $plan = $this->plan($member, $keys, $finalConsumer);

        return [
            'member_id' => $member->id,
            'keys' => $plan['keys'],
            'final_consumer' => $finalConsumer,
            'preview_hash' => $plan['hash'],
            'customer' => array_intersect_key($plan['built']['snapshot'], array_flip([
                'customer_doc_type', 'customer_doc_number', 'customer_dv', 'customer_name',
                'customer_email', 'customer_phone', 'is_final_consumer',
            ])),
            'origins' => array_map(fn (array $o) => $this->publicOrigin($o), $plan['origins']),
            'lines' => $plan['built']['line_items'],
            'payment_details' => $plan['built']['payload']['payment_details'],
            'totals' => array_intersect_key($plan['built']['snapshot'], array_flip([
                'currency', 'subtotal', 'discount', 'tax_total', 'total',
            ])),
            'issuer_legend' => app(TaxPolicy::class)->issuerLegend(),
            'observation' => $plan['built']['payload']['observation'],
            'billing' => [
                'enabled' => (bool) config('billing.enabled'),
                'env' => (string) config('billing.env'),
            ],
            'supersedes' => array_values(array_filter(array_map(
                fn (array $o) => $o['supersedes']?->id,
                $plan['origins'],
            ))),
        ];
    }

    // ── 3. Emisión confirmada ─────────────────────────────────────────────

    /**
     * @param  list<string>  $keys
     * @return array{invoice: ElectronicInvoice, bundle: InvoiceBundle, replay: bool}
     *
     * @throws ManualEmissionRejectedException
     */
    public function emit(
        Member $member,
        array $keys,
        ?bool $finalConsumer,
        string $idempotencyKey,
        string $previewHash,
        ?int $adminId,
        ?string $adminName,
    ): array {
        if ($replay = $this->replay($member, $idempotencyKey)) {
            return $replay;
        }

        try {
            $result = DB::transaction(function () use ($member, $keys, $finalConsumer, $idempotencyKey, $previewHash, $adminId, $adminName): array {
                // Dos emisiones del mismo cliente se hacen en fila. La segunda
                // ve lo que reservó la primera (o su propio doble clic).
                Member::query()->whereKey($member->id)->lockForUpdate()->first();

                if ($replay = $this->replay($member, $idempotencyKey)) {
                    return $replay;
                }

                $this->releaseStaleClaims($keys);

                $plan = $this->plan($member, $keys, $finalConsumer, lock: true);
                if (! hash_equals($plan['hash'], $previewHash)) {
                    throw ManualEmissionRejectedException::conflicto(
                        'Los datos de las operaciones o del cliente cambiaron desde la vista previa. '
                        .'Vuelve a previsualizar y confirma de nuevo; no se emitió nada.'
                    );
                }

                $bundle = InvoiceBundle::create([
                    'member_id' => $member->id,
                    'idempotency_key' => $idempotencyKey,
                    'final_consumer' => $finalConsumer,
                    'total' => $plan['built']['snapshot']['total'],
                    'preview_hash' => $plan['hash'],
                    'created_by_admin_id' => $adminId,
                    'created_by_name' => $adminName,
                ]);

                foreach ($plan['origins'] as $o) {
                    InvoiceBundleItem::create([
                        'invoice_bundle_id' => $bundle->id,
                        'origin_type' => $o['type'],
                        'origin_id' => $o['id'],
                        'description' => mb_substr($o['description'], 0, 255),
                        'occurred_at' => $o['occurred_at'],
                        'amount' => $o['amount']->toDatabase(),
                        'active' => true,
                        'replaces_invoice_id' => $o['replaces']?->id,
                    ]);
                }

                $invoice = ElectronicInvoice::create(array_merge($plan['built']['snapshot'], [
                    'source_type' => $bundle->getMorphClass(),
                    'source_id' => $bundle->id,
                    'type' => InvoiceType::INVOICE->value,
                    'status' => InvoiceStatus::PENDING->value,
                    'numbering_range_id' => config('billing.numbering.range_id'),
                    'prefix' => config('billing.numbering.prefix'),
                    'payload_snapshot' => $plan['built']['payload'],
                    'line_items_snapshot' => $plan['built']['line_items'],
                    'source_amount_snapshot' => $plan['built']['snapshot']['total'],
                    'manual_authorization_at' => now(),
                    'created_by_admin_id' => $adminId,
                    'manual_authorization_note' => sprintf(
                        'Emisión manual MULTICONCEPTO desde el CRM · %d operaciones · adquiriente: %s',
                        count($plan['origins']),
                        match ($finalConsumer) {
                            true => 'consumidor final',
                            false => 'perfil fiscal del cliente',
                            default => 'según perfil fiscal disponible',
                        },
                    ),
                    'last_attempt_at' => config('billing.enabled') ? now() : null,
                ]));

                $this->invoicing->recordLog($invoice, InvoiceLogAction::ENQUEUE, 'ok', sprintf(
                    'Factura multiconcepto (lote %s) autorizada%s: %s.',
                    $bundle->uuid,
                    $adminId !== null ? " por el admin #{$adminId}" : '',
                    implode(', ', array_map(fn ($o) => $o['label'], $plan['origins'])),
                ));

                foreach ($plan['origins'] as $o) {
                    if ($o['supersedes'] !== null) {
                        $this->supersede($o['supersedes'], $invoice, $adminName);
                    }
                }

                return ['invoice' => $invoice, 'bundle' => $bundle, 'replay' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // El índice parcial (otra persona reservó una operación) o la clave
            // de idempotencia (el mismo clic, por otra vía) ganaron la carrera.
            if ($replay = $this->replay($member, $idempotencyKey)) {
                return $replay;
            }

            throw ManualEmissionRejectedException::conflicto(
                'Otra persona acaba de incluir alguna de estas operaciones en una factura. '
                .'Actualiza la lista; no se emitió nada.'
            );
        }

        if (! $result['replay'] && config('billing.enabled')) {
            EmitElectronicInvoiceJob::dispatch($result['invoice']->id)
                ->onQueue((string) config('billing.queue', 'billing'));
        }

        return $result;
    }

    /**
     * Total que registran HOY las operaciones del lote. Es la referencia del
     * conciliador antes de emitir: si un pago se anuló o una cuenta se revirtió
     * después de reservarla, el comprobante ya no cuadra y no sale.
     */
    public function liveTotal(InvoiceBundle $bundle): float
    {
        $total = Money::zero();
        foreach ($bundle->items()->get() as $item) {
            $model = $this->find($item->origin_type, (int) $item->origin_id);
            if ($model !== null) {
                $total = $total->plus($this->grossOf($item->origin_type, $model));
            }
        }

        return (float) $total->toDecimalString();
    }

    /**
     * Radiografía conjunta del origen económico de un lote, con la misma forma
     * que PaymentOriginInspector. Basta una operación dudosa para que el lote
     * entero no se emita.
     *
     * @return array<string,mixed>
     */
    public function inspectBundle(InvoiceBundle $bundle): array
    {
        $out = [
            'transaction' => null, 'environment' => null, 'card_last_four' => null,
            'reference' => $bundle->uuid, 'payment_status' => 'paid',
            'is_sandbox' => false, 'is_test_card' => false,
            'wants_invoice' => false, 'has_verifiable_reference' => true,
        ];

        foreach ($bundle->items()->get() as $item) {
            $model = $this->find($item->origin_type, (int) $item->origin_id);
            if ($model === null) {
                $out['payment_status'] = 'missing';
                $out['has_verifiable_reference'] = false;

                continue;
            }

            if ($item->origin_type === InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE) {
                if ($this->commissionProblem($model) !== null) {
                    $out['payment_status'] = 'unpaid';
                    $out['has_verifiable_reference'] = false;
                }

                continue;
            }

            $o = $this->inspector->inspectSource($model);
            $out['is_sandbox'] = $out['is_sandbox'] || $o['is_sandbox'];
            $out['is_test_card'] = $out['is_test_card'] || $o['is_test_card'];
            $out['environment'] ??= $o['environment'];
            $out['card_last_four'] ??= $o['card_last_four'];
            $out['has_verifiable_reference'] = $out['has_verifiable_reference'] && $this->verifiable($item->origin_type, $model, $o);
            if ($this->paidProblem($item->origin_type, $model) !== null) {
                $out['payment_status'] = (string) ($o['payment_status'] ?: 'unpaid');
            }
        }

        return $out;
    }

    /**
     * Factura individual que un lote ACTIVO ya respalda por esta operación.
     * La usan la emisión individual y la barrera previa al envío.
     */
    public static function activeClaimFor(string $originType, int $originId): ?InvoiceBundleItem
    {
        return InvoiceBundleItem::query()
            ->where('origin_type', $originType)
            ->where('origin_id', $originId)
            ->where('active', true)
            ->whereHas('bundle', fn ($q) => $q->whereDoesntHave('invoice', fn ($i) => self::applyVoid($i)))
            ->first();
    }

    /**
     * Factura de lote que YA NO respalda sus operaciones:
     *
     *  - anulada sin haber llegado nunca a Factus (sin CUFE, número ni id), o
     *  - anulada por una nota crédito VALIDADA ante la DIAN (con CUFE) por el
     *    TOTAL de la factura.
     *
     * Una anulación sin nota crédito validada, una nota pendiente o rechazada,
     * o una nota parcial NO la anulan: la factura sigue existiendo ante la DIAN
     * y sus operaciones siguen facturadas. La misma regla en consulta
     * (applyVoid) y en PHP (isVoid); las pruebas cubren las dos.
     */
    public static function applyVoid($query): void
    {
        $query->where('electronic_invoices.status', InvoiceStatus::CANCELLED->value)
            ->where(fn ($w) => $w
                ->where(fn ($n) => $n->whereNull('electronic_invoices.cufe')
                    ->whereNull('electronic_invoices.full_number')
                    ->whereNull('electronic_invoices.factus_id'))
                ->orWhereExists(fn ($cn) => $cn->selectRaw('1')
                    ->from('electronic_invoices as cn')
                    ->whereColumn('cn.references_invoice_id', 'electronic_invoices.id')
                    ->where('cn.type', InvoiceType::CREDIT_NOTE->value)
                    ->where('cn.status', InvoiceStatus::CREDIT_NOTE_VALIDATED->value)
                    ->whereNotNull('cn.cufe')
                    ->whereColumn('cn.total', 'electronic_invoices.total')));
    }

    public static function isVoid(?ElectronicInvoice $invoice): bool
    {
        if ($invoice === null || $invoice->status !== InvoiceStatus::CANCELLED) {
            return false;
        }
        if (blank($invoice->cufe) && blank($invoice->full_number) && blank($invoice->factus_id)) {
            return true;
        }

        return self::annullingCreditNote($invoice) !== null;
    }

    /** Nota crédito validada (con CUFE) que anula la factura por el total. */
    public static function annullingCreditNote(ElectronicInvoice $invoice): ?ElectronicInvoice
    {
        return ElectronicInvoice::query()
            ->where('references_invoice_id', $invoice->id)
            ->where('type', InvoiceType::CREDIT_NOTE->value)
            ->where('status', InvoiceStatus::CREDIT_NOTE_VALIDATED->value)
            ->whereNotNull('cufe')
            ->get()
            ->first(fn (ElectronicInvoice $cn) => Money::fromAmount($cn->total)->cents() === Money::fromAmount($invoice->total)->cents());
    }

    /**
     * Factura de OTRO lote que todavía respalda esta operación (no nula). La
     * usa la barrera previa al envío como red independiente del índice único.
     */
    public static function conflictingInvoiceFor(string $originType, int $originId, int $exceptBundleId): ?ElectronicInvoice
    {
        foreach (InvoiceBundleItem::query()->where('origin_type', $originType)->where('origin_id', $originId)
            ->where('invoice_bundle_id', '!=', $exceptBundleId)->get() as $item) {
            $invoice = $item->bundle?->invoice;
            if ($invoice !== null && ! self::isVoid($invoice)) {
                return $invoice;
            }
        }

        return null;
    }

    /** Tipo de origen de un modelo facturable individualmente. */
    public static function originTypeOf(?Model $model): ?string
    {
        return match (true) {
            $model instanceof Payment => InvoiceBundleItem::ORIGIN_PAYMENT,
            $model instanceof ProductSale => InvoiceBundleItem::ORIGIN_PRODUCT_SALE,
            default => null,
        };
    }

    // ── Núcleo ───────────────────────────────────────────────────────────

    /**
     * Valida la selección y construye el comprobante. Lo comparten la vista
     * previa y la emisión, así que lo que se confirma es lo que se emite.
     *
     * @return array{keys: list<string>, origins: list<array<string,mixed>>, built: array<string,mixed>, hash: string}
     */
    private function plan(Member $member, array $keys, ?bool $finalConsumer, bool $lock = false): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        sort($keys);

        if ($keys === []) {
            throw ManualEmissionRejectedException::noFacturable('Selecciona al menos una operación.');
        }
        if (count($keys) > self::MAX_ITEMS) {
            throw ManualEmissionRejectedException::noFacturable('Una factura admite como máximo '.self::MAX_ITEMS.' operaciones.');
        }

        $origins = [];
        $problemas = [];
        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
            if (! in_array($type, InvoiceBundleItem::ORIGINS, true) || ! ctype_digit((string) $id)) {
                throw ManualEmissionRejectedException::noFacturable("Operación no reconocida: «{$key}».");
            }

            $model = $this->find($type, (int) $id, $lock);
            if ($model === null || ! $this->belongsTo($type, $model, $member)) {
                $problemas[] = "{$key}: no existe o no pertenece a este cliente";

                continue;
            }

            $o = $this->evaluate($type, $model, $member);
            if ($o['blocked'] !== null) {
                $problemas[] = "{$o['label']}: {$o['blocked']}";

                continue;
            }
            $origins[] = $o;
        }

        if ($problemas !== []) {
            throw ManualEmissionRejectedException::noFacturable(
                'No se puede facturar: '.implode('; ', $problemas).'.'
            );
        }

        $customer = $this->resolver->resolveForMember($member, $finalConsumer);
        $built = $this->builder->forBundle(
            array_map(fn ($o) => ['type' => $o['type'], 'model' => $o['model'], 'description' => $o['description']], $origins),
            $customer,
            $this->groupMeans($origins),
            $this->observation($origins),
        );

        $hash = hash('sha256', json_encode([
            'member' => $member->id,
            'keys' => $keys,
            'final_consumer' => $finalConsumer,
            'payload' => $built['payload'],
            'lines' => $built['line_items'],
            'supersedes' => array_map(fn ($o) => $o['supersedes']?->id, $origins),
            'replaces' => array_map(fn ($o) => $o['replaces']?->id, $origins),
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return ['keys' => $keys, 'origins' => $origins, 'built' => $built, 'hash' => $hash];
    }

    /**
     * Ficha de una operación y, si no se puede facturar, el motivo.
     *
     * @return array<string,mixed>
     */
    private function evaluate(string $type, Model $model, Member $member): array
    {
        $o = [
            'key' => $type.':'.$model->getKey(),
            'type' => $type,
            'id' => (int) $model->getKey(),
            'model' => $model,
            'label' => $this->label($type, $model),
            'description' => $this->description($type, $model),
            'occurred_at' => $this->occurredAt($type, $model),
            'amount' => $this->grossOf($type, $model),
            'means' => [],
            'method' => null,
            'blocked' => null,
            'fiscal' => ['state' => 'available', 'invoice_id' => null, 'full_number' => null, 'bundle_invoice_id' => null],
            'supersedes' => null,
            'replaces' => self::replacedInvoiceFor($type, (int) $model->getKey()),
        ];

        $o['blocked'] = $this->paidProblem($type, $model)
            ?? $this->traceProblem($type, $model)
            ?? ($o['amount']->isZero() ? 'el importe es cero' : null);

        if ($o['blocked'] === null) {
            [$o['means'], $o['method'], $meansProblem] = $this->means($type, $model, $o['amount']);
            $o['blocked'] = $meansProblem;
        }

        // ¿Ya respalda otra factura multiconcepto?
        if ($claim = self::activeClaimFor($type, $o['id'])) {
            $bundleInvoice = $claim->bundle?->invoice;
            $o['fiscal'] = ['state' => 'in_bundle', 'invoice_id' => null,
                'full_number' => $bundleInvoice?->full_number, 'bundle_invoice_id' => $bundleInvoice?->id];
            $o['blocked'] ??= $bundleInvoice?->status === InvoiceStatus::CANCELLED
                ? sprintf('la factura %s (solicitud #%d) figura anulada pero sin una nota crédito VALIDADA por el total: '
                    .'sigue existiendo ante la DIAN y no se refactura', $bundleInvoice->full_number ?: 'multiconcepto', $bundleInvoice->id)
                : sprintf('ya está incluida en la factura multiconcepto (solicitud #%s, %s)',
                    $bundleInvoice?->id ?? '?', $bundleInvoice?->full_number ?: ($bundleInvoice?->status->value ?? 'sin estado'));

            return $o;
        }

        // ¿Tiene su propia factura individual?
        $individual = $type === InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE ? null : ElectronicInvoice::query()
            ->where('source_type', $model->getMorphClass())
            ->where('source_id', $model->getKey())
            ->where('type', InvoiceType::INVOICE->value)
            ->first();

        if ($individual !== null && $individual->status !== InvoiceStatus::CANCELLED) {
            $o['fiscal']['invoice_id'] = $individual->id;
            $o['fiscal']['full_number'] = $individual->full_number;

            if ($individual->hasBeenTransmitted() || $individual->status === InvoiceStatus::VALIDATED) {
                $o['fiscal']['state'] = 'invoiced';
                $o['blocked'] ??= sprintf(
                    'ya fue facturada en el documento %s (solicitud #%d). Para corregirla se usa una nota crédito, no otra factura',
                    $individual->full_number ?: 'con CUFE', $individual->id,
                );
            } elseif ($this->isSupersedable($individual)) {
                // Solicitud automática que nunca salió: la factura consolidada
                // la sustituye (se anula al confirmar, con motivo y traza).
                $o['fiscal']['state'] = 'pending_individual';
                $o['supersedes'] = $individual;
            } else {
                $o['fiscal']['state'] = 'individual_in_progress';
                $o['blocked'] ??= sprintf(
                    'tiene una solicitud individual #%d en estado «%s» que ya se autorizó o se intentó enviar. Resuélvela antes de consolidar',
                    $individual->id, $individual->status->value,
                );
            }
        }

        if ($o['blocked'] !== null && $o['fiscal']['state'] === 'available') {
            $o['fiscal']['state'] = 'blocked';
        }

        return $o;
    }

    /**
     * Factura de lote anulada ANTE LA DIAN (nota crédito validada) que esta
     * operación respaldaba: la nueva factura la reemplaza. Las que nunca
     * llegaron a Factus no cuentan: no existieron fiscalmente.
     */
    private static function replacedInvoiceFor(string $type, int $id): ?ElectronicInvoice
    {
        foreach (InvoiceBundleItem::query()->where('origin_type', $type)->where('origin_id', $id)
            ->orderByDesc('id')->get() as $item) {
            $invoice = $item->bundle?->invoice;
            if ($invoice !== null && filled($invoice->cufe) && self::isVoid($invoice)) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * Una solicitud individual se puede sustituir solo si nunca salió ni
     * intentó salir y nadie la autorizó: es el pendiente automático del cobro.
     */
    private function isSupersedable(ElectronicInvoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::PENDING
            && ! $invoice->hasBeenTransmitted()
            && ! $invoice->isManuallyAuthorized()
            && $invoice->last_attempt_at === null
            && blank($invoice->request_payload)
            && (int) $invoice->retry_count === 0;
    }

    private function supersede(ElectronicInvoice $individual, ElectronicInvoice $bundleInvoice, ?string $adminName): void
    {
        $fresh = ElectronicInvoice::query()->whereKey($individual->id)->lockForUpdate()->first();
        if ($fresh === null || ! $this->isSupersedable($fresh)) {
            throw ManualEmissionRejectedException::conflicto(sprintf(
                'La solicitud individual #%d cambió mientras se confirmaba. No se emitió nada; vuelve a previsualizar.',
                $individual->id,
            ));
        }

        $fresh->forceFill([
            'status' => InvoiceStatus::CANCELLED->value,
            'retry_allowed' => false,
            'cancelled_at' => now(),
            'cancelled_by' => $adminName ?: 'Emisión multiconcepto',
            'cancellation_reason' => sprintf(
                'Sustituida por la factura multiconcepto (solicitud #%d). Nunca se envió a la DIAN.',
                $bundleInvoice->id,
            ),
        ])->save();

        $this->invoicing->recordLog($fresh, InvoiceLogAction::ENQUEUE, 'ok', sprintf(
            'Anulada sin transmitir: la operación quedó incluida en la factura multiconcepto (solicitud #%d).',
            $bundleInvoice->id,
        ));
    }

    /**
     * Un lote cuya factura se anuló sin llegar a la DIAN deja de reservar sus
     * operaciones. Se libera aquí, con la ficha del socio bloqueada.
     */
    private function releaseStaleClaims(array $keys): void
    {
        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);
            if (! in_array($type, InvoiceBundleItem::ORIGINS, true) || ! ctype_digit((string) $id)) {
                continue;
            }

            InvoiceBundleItem::query()
                ->where('origin_type', $type)->where('origin_id', (int) $id)->where('active', true)
                ->whereHas('bundle.invoice', fn ($i) => self::applyVoid($i))
                ->update(['active' => false, 'updated_at' => now()]);
        }
    }

    /** @return array{invoice: ElectronicInvoice, bundle: InvoiceBundle, replay: bool}|null */
    private function replay(Member $member, string $idempotencyKey): ?array
    {
        $bundle = InvoiceBundle::where('idempotency_key', $idempotencyKey)->first();
        if ($bundle === null) {
            return null;
        }
        if ((int) $bundle->member_id !== (int) $member->id) {
            throw ManualEmissionRejectedException::conflicto('Esa clave de emisión pertenece a otro cliente.');
        }

        $invoice = $bundle->invoice()->first();
        if ($invoice === null) {
            throw ManualEmissionRejectedException::conflicto('El lote existe pero no tiene factura asociada.');
        }

        return ['invoice' => $invoice, 'bundle' => $bundle, 'replay' => true];
    }

    // ── Orígenes ─────────────────────────────────────────────────────────

    private function find(string $type, int $id, bool $lock = false): ?Model
    {
        $query = match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT => Payment::query(),
            InvoiceBundleItem::ORIGIN_PRODUCT_SALE => ProductSale::query(),
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => TrainerCommissionCharge::query(),
            default => null,
        };

        return $query?->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private function belongsTo(string $type, Model $model, Member $member): bool
    {
        return match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT => (int) $model->member_id === (int) $member->id
                || ($model->member_id === null && $member->user_id !== null && (int) $model->user_id === (int) $member->user_id),
            InvoiceBundleItem::ORIGIN_PRODUCT_SALE => (int) $model->member_id === (int) $member->id,
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => (int) $model->agreement?->member_id === (int) $member->id,
            default => false,
        };
    }

    /** Null = cobrado. Si no, el motivo. */
    private function paidProblem(string $type, Model $model): ?string
    {
        if ($type === InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE) {
            return $this->commissionProblem($model);
        }

        if ($type === InvoiceBundleItem::ORIGIN_PRODUCT_SALE && strtolower((string) $model->status) === 'cancelled') {
            return 'la venta está anulada';
        }

        $estado = $model->payment_status ?? $model->status ?? null;
        $estado = $estado instanceof \BackedEnum ? (string) $estado->value : (string) $estado;

        return strtolower($estado) === 'paid'
            ? null
            : sprintf('está en estado «%s», no «pagado»; solo se factura lo cobrado', $estado !== '' ? $estado : 'desconocido');
    }

    /**
     * Un piso de entrenador se le factura al CLIENTE solo si el trato dice que
     * lo asume él y la cuenta quedó pagada por completo y sin reversiones.
     */
    private function commissionProblem(Model $charge): ?string
    {
        /** @var TrainerCommissionCharge $charge */
        if ($charge->payer !== TrainerCommissionAgreement::PAYER_MEMBER) {
            return 'el piso lo paga el entrenador: el adquiriente sería él, no el cliente. No se factura a la socia';
        }

        $receivable = $charge->receivable_id ? Receivable::find($charge->receivable_id) : null;
        if ($receivable === null) {
            return 'no tiene cuenta por cobrar asociada; no hay cobro que facturar';
        }
        if ($receivable->status !== Receivable::STATUS_PAID) {
            return sprintf('la cuenta del piso está «%s»: solo se factura cuando está pagada por completo', $receivable->status);
        }

        $aplicado = Money::zero();
        foreach (ReceivablePayment::where('receivable_id', $receivable->id)
            ->where('status', ReceivablePayment::STATUS_APPLIED)->get() as $rp) {
            $aplicado = $aplicado->plus(Money::fromAmount($rp->amount));
        }

        return $aplicado->cents() === Money::fromAmount($charge->amount)->cents()
            ? null
            : sprintf('lo cobrado (%s) no coincide con el valor del piso (%s)', $aplicado->toDecimalString(), Money::fromAmount($charge->amount)->toDecimalString());
    }

    /** Null = rastreable. Mismas reglas que la emisión individual. */
    private function traceProblem(string $type, Model $model): ?string
    {
        if ($type === InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE) {
            foreach (ReceivablePayment::where('receivable_id', $model->receivable_id)
                ->where('status', ReceivablePayment::STATUS_APPLIED)->get() as $rp) {
                if (! in_array(strtolower((string) $rp->method), PaymentOriginInspector::MANUAL_PAYMENT_METHODS, true)) {
                    return sprintf('un abono se registró con el medio «%s», que no es de mostrador', $rp->method);
                }
            }

            return null;
        }

        $o = $this->inspector->inspectSource($model);
        if ($o['is_sandbox']) {
            return sprintf('proviene de una transacción en ambiente «%s»; no movió dinero', $o['environment']);
        }
        if ($o['is_test_card']) {
            return 'proviene de una tarjeta de prueba';
        }
        if (! $this->verifiable($type, $model, $o)) {
            return 'su origen no es verificable (importación del sistema anterior, pasarela sin transacción o medio no registrado en caja)';
        }

        return null;
    }

    /**
     * Rastreable según PaymentOriginInspector y, además, un cobro MIXTO de
     * mostrador: el inspector no conoce `mixed`, pero cada parte quedó en el
     * registro de caja con su medio. Solo si TODAS las partes son de mostrador.
     */
    private function verifiable(string $type, Model $model, array $inspected): bool
    {
        if ($inspected['has_verifiable_reference']) {
            return true;
        }

        $method = strtolower(trim((string) ($type === InvoiceBundleItem::ORIGIN_PRODUCT_SALE ? $model->payment_method : $model->method)));
        $reference = (string) ($model->reference ?? '');
        if ($method !== 'mixed' || str_starts_with(strtoupper($reference), PaymentOriginInspector::IMPORTED_REFERENCE_PREFIX)) {
            return false;
        }

        $splits = $model->splits()->get();

        return $splits->isNotEmpty() && $splits->every(
            fn ($s) => in_array(strtolower(trim((string) $s->method)), PaymentOriginInspector::MANUAL_PAYMENT_METHODS, true)
        );
    }

    private function grossOf(string $type, Model $model): Money
    {
        return match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT, InvoiceBundleItem::ORIGIN_PRODUCT_SALE => Money::fromAmount($model->grossAmountValue()),
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => Money::fromAmount($model->amount),
        };
    }

    /**
     * Medios de pago REALES de la operación, en código Factus.
     *
     * @return array{0: list<array{code: string, amount: Money}>, 1: string, 2: ?string}
     */
    private function means(string $type, Model $model, Money $gross): array
    {
        $parts = [];

        if ($type === InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE) {
            foreach (ReceivablePayment::where('receivable_id', $model->receivable_id)
                ->where('status', ReceivablePayment::STATUS_APPLIED)->get() as $rp) {
                $parts[] = [(string) $rp->method, Money::fromAmount($rp->amount)];
            }
        } else {
            $method = strtolower(trim((string) ($type === InvoiceBundleItem::ORIGIN_PRODUCT_SALE ? $model->payment_method : $model->method)));
            if ($method === 'mixed') {
                foreach ($model->splits()->get() as $split) {
                    $parts[] = [(string) $split->method, Money::fromAmount($split->amount)];
                }
            } else {
                $parts[] = [$method, $gross];
            }
        }

        $sum = Money::zero();
        $means = [];
        foreach ($parts as [$method, $amount]) {
            $sum = $sum->plus($amount);
            $means[] = ['code' => self::meansCode($method), 'amount' => $amount, 'method' => $method];
        }

        $label = implode(' + ', array_unique(array_map(fn ($m) => $m['method'] !== '' ? $m['method'] : 'sin medio', $means)));

        if ($means === []) {
            return [[], $label, 'no tiene medios de pago registrados'];
        }
        if ($sum->cents() !== $gross->cents()) {
            return [$means, $label, sprintf('sus medios de pago suman %s y la operación %s', $sum->toDecimalString(), $gross->toDecimalString())];
        }

        return [$means, $label, null];
    }

    public static function meansCode(string $method): string
    {
        $map = (array) config('billing.payment_means_map', []);

        return (string) ($map[strtolower(trim($method))] ?? config('billing.payment_means_unknown_code', '1'));
    }

    /** @return list<array{payment_method_code: string, amount: string}> */
    private function groupMeans(array $origins): array
    {
        $byCode = [];
        foreach ($origins as $o) {
            foreach ($o['means'] as $m) {
                $byCode[$m['code']] = ($byCode[$m['code']] ?? Money::zero())->plus($m['amount']);
            }
        }
        ksort($byCode);

        return array_map(
            fn ($code, Money $amount) => ['payment_method_code' => (string) $code, 'amount' => $amount->toDecimalString()],
            array_keys($byCode),
            array_values($byCode),
        );
    }

    private function observation(array $origins): string
    {
        $reemplazos = [];
        foreach ($origins as $o) {
            if ($o['replaces'] !== null) {
                $nc = self::annullingCreditNote($o['replaces']);
                $reemplazos[$o['replaces']->id] = ($o['replaces']->full_number ?: '#'.$o['replaces']->id)
                    .($nc?->full_number ? ' (anulada con '.$nc->full_number.')' : '');
            }
        }
        $text = ($reemplazos !== [] ? 'Reemplaza '.implode(', ', $reemplazos).'. ' : '')
            .'Factura consolidada: '.implode('; ', array_map(fn ($o) => $o['label'], $origins));

        return mb_strlen($text) > 250 ? mb_substr($text, 0, 247).'...' : $text;
    }

    private function label(string $type, Model $model): string
    {
        return match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT => 'Pago #'.$model->getKey().($model->reference ? ' ('.$model->reference.')' : ''),
            InvoiceBundleItem::ORIGIN_PRODUCT_SALE => 'Venta '.($model->code ?: '#'.$model->getKey()),
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => 'Piso #'.$model->getKey().' · '.$model->periodLabel(),
        };
    }

    private function description(string $type, Model $model): string
    {
        return match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT => $model->plan_id
                ? 'Membresía '.(\App\Models\Plan::find($model->plan_id)?->name ?? 'plan #'.$model->plan_id)
                : 'Pago Iron Body',
            InvoiceBundleItem::ORIGIN_PRODUCT_SALE => 'Venta · '.$model->items()->get()
                ->map(fn ($i) => max(1, (int) $i->quantity).' × '.$i->name)->implode(', '),
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => sprintf(
                'Entrenamiento personalizado (piso) · %s · %s',
                $model->agreement?->trainer?->full_name ?: 'entrenador',
                $model->periodLabel(),
            ),
        };
    }

    private function occurredAt(string $type, Model $model): ?Carbon
    {
        $value = match ($type) {
            InvoiceBundleItem::ORIGIN_PAYMENT, InvoiceBundleItem::ORIGIN_PRODUCT_SALE => $model->paid_at ?? $model->created_at,
            InvoiceBundleItem::ORIGIN_COMMISSION_CHARGE => $model->created_at,
        };

        return $value ? Carbon::parse($value) : null;
    }

    /** Lo que ve el CRM de una operación: sin el modelo ni objetos internos. */
    private function publicOrigin(array $o): array
    {
        return [
            'key' => $o['key'],
            'type' => $o['type'],
            'id' => $o['id'],
            'label' => $o['label'],
            'description' => $o['description'],
            'occurred_at' => $o['occurred_at']?->toIso8601String(),
            'amount' => $o['amount']->toDecimalString(),
            'method' => $o['method'],
            'payment_means' => array_map(fn ($m) => ['code' => $m['code'], 'method' => $m['method'], 'amount' => $m['amount']->toDecimalString()], $o['means']),
            'selectable' => $o['blocked'] === null,
            'blocked_reason' => $o['blocked'],
            'fiscal' => $o['fiscal'],
            'supersedes_invoice_id' => $o['supersedes']?->id,
            'replaces' => $o['replaces'] ? [
                'invoice_id' => $o['replaces']->id,
                'full_number' => $o['replaces']->full_number,
                'credit_note' => self::annullingCreditNote($o['replaces'])?->full_number,
            ] : null,
        ];
    }
}
